# Data Speak on AWS

New to AWS? Start with the walkthrough [AWS_DEPLOYMENT_STEPS.md](AWS_DEPLOYMENT_STEPS.md) and come back here for
the reference material.

Target topology (MVP layout, decided on 5 October 2026):

| Part | AWS service | What runs there |
|---|---|---|
| Server | one EC2 (Ubuntu 24.04) | Laravel API (FrankenPHP: Caddy + PHP with automatic HTTPS), queue worker, scheduler, Redis and the analytics FastAPI service as Docker Compose containers (`deploy/aws/docker-compose.prod.yml`), plus Ollama installed natively on the same host |
| Frontend | Amplify Hosting | The React build (`frontend/`), built from the repository with `amplify.yml` |
| Database | RDS for PostgreSQL 17 | The only database; reached from the server over TLS |

```
Browser ──HTTPS──> Amplify (app.example.com)           static React app
Browser ──HTTPS──> EC2 api.example.com:443             FrankenPHP  ─┬─> RDS PostgreSQL (5432, TLS)
                                                        worker      ─┼─> Redis (container)
                                                        scheduler   ─┼─> analytics container (8000, internal)
                                                                     └─> Ollama on the host (11434, not exposed)
```

Sizing: `t3a.xlarge` (4 vCPU, 16 GB) with `qwen3:4b` on the CPU is enough for an MVP (one to three minutes per
narrative); `g4dn.xlarge` (T4 GPU) with `qwen3:8b` answers in under a minute at about five times the hourly price.
A separate GPU instance for Ollama (section 10) is the upgrade path, not the starting point.

Raw datasets exist only in the API host's `app_storage` volume (`storage/app/tmp`) for at most
`UPLOAD_TTL_MINUTES`, and in the analytics container's tmpfs for the duration of one request. Nothing raw reaches
RDS, Redis, Amplify or Ollama. The analytics container and Ollama have no public exposure.

Everything below uses the placeholders `api.example.com`, `app.example.com` and region `ap-southeast-1`. Replace
them with your own.

---

## 1. Files in the repository

| File | Purpose |
|---|---|
| `deploy/aws/docker-compose.prod.yml` | Production stack for the server: `app`, `worker`, `scheduler`, `redis`, `analytics`. Compose project `dataspeak-prod` with `dataspeak-prod-*` container names, so it never collides with the development stack if started on a laptop by mistake (it would still try to reach RDS). |
| `deploy/aws/.env.prod.example` | Template for `deploy/aws/.env.prod` (never committed). |
| `deploy/aws/Caddyfile` | HTTPS site definition baked into the API image (`SERVER_NAME`, `ACME_EMAIL`). |
| `deploy/aws/deploy.sh` | Pull (or `--no-pull`, or `--images` for pre-built images), build, migrate, restart, nginx site, health check. |
| `deploy/aws/push.sh` | Run on your computer: build the images for the server's CPU (`linux/arm64` by default), ship them over SSH with `docker save`/`docker load`, copy the deploy folder and run `deploy.sh --images`. No git access on the server needed. |
| `deploy/aws/push-frontend.sh`, `deploy/aws/nginx/api-ssl.conf.template`, `deploy/aws/nginx/frontend.conf.template` | Alternative to Amplify: build the React app here and serve it from nginx on the same server under its own host name (`dataspeaknow.com` and `www`), with `api.dataspeaknow.com` staying API-only. Frontend HTTPS: `sudo certbot --nginx -d dataspeaknow.com -d www.dataspeaknow.com --redirect` once DNS points at the server. |
| `deploy/aws/docker-compose.nginx.yml`, `deploy/aws/nginx/api.conf` | nginx layout (`PROXY=nginx` in `.env.prod`): the API container listens on `127.0.0.1:8080`, nginx on the host owns ports 80/443 and the Let's Encrypt certificate (`certbot --nginx`). |
| `docker/backend/Dockerfile.prod`, `docker/backend/entrypoint.prod.sh` | API image (code baked in, caches warmed at start, no `.env` file). |
| `docker/analytics/Dockerfile` | Analytics image (shared with development; production runs it without `--reload`). |
| `amplify.yml` | Amplify build specification for the monorepo (`appRoot: frontend`). |
| `backend/config/cors.php` | Browser origins allowed: `FRONTEND_URL` plus `CORS_ALLOWED_ORIGINS`. |
| `backend/bootstrap/app.php` | `TRUSTED_PROXIES` support for an ALB in front of the API (not needed when FrankenPHP terminates TLS). |

---

## 2. Network and security groups

Create one VPC (or use the default) with public subnets for the two EC2 instances and private subnets for RDS.

| Security group | Inbound rules |
|---|---|
| `dataspeak-server` (EC2) | 80/tcp and 443/tcp (+ 443/udp for HTTP/3) from `0.0.0.0/0`; 22/tcp from your IP only |
| `dataspeak-rds` (RDS) | 5432/tcp from `dataspeak-server` only |

Ollama's port 11434 is never opened: the containers reach it through `host.docker.internal` on the same machine.
Outbound: default allow. Allocate an Elastic IP for the instance and point the DNS A record of `api.example.com`
at it before the first start, because Caddy requests the Let's Encrypt certificate on boot.

---

## 3. RDS for PostgreSQL

1. Create a PostgreSQL 17 instance (`db.t4g.micro` for the MVP, `db.t4g.medium` later; storage 20 GB gp3, autoscaling on),
   private subnets only, security group `dataspeak-rds`, automated backups 7 days, deletion protection on.
2. Database `dataspeak`, master user of your choice. Then create the application user:

```sql
CREATE ROLE dataspeak WITH LOGIN PASSWORD '<strong password>';
GRANT ALL PRIVILEGES ON DATABASE dataspeak TO dataspeak;
\c dataspeak
GRANT ALL ON SCHEMA public TO dataspeak;
```

3. In `.env.prod`: `DB_HOST=<endpoint>`, `DB_DATABASE=dataspeak`, `DB_USERNAME`, `DB_PASSWORD`,
   `DB_SSLMODE=require`. RDS certificates are trusted by the image's CA bundle; `require` encrypts without pinning.
4. Migrations run from the API host (`deploy.sh` does it): `php artisan migrate --force`.

---

## 4. Ollama

Default: install Ollama natively on the same EC2 as the API (section 5) and keep `OLLAMA_BASE_URL=http://host.docker.internal:11434`.
The compose file maps `host.docker.internal` to the host for the app and worker containers. Use `qwen3:4b` with
`OLLAMA_TIMEOUT=600` on a CPU instance, `qwen3:8b` with `300` on a GPU instance.

Separate model server (later, optional): a `g4dn.xlarge` or `g5.xlarge` with the AWS Deep Learning Base AMI (NVIDIA
driver included), 100 GB gp3, security group allowing 11434 from `dataspeak-server` only. Then:

```bash
curl -fsSL https://ollama.com/install.sh | sh
sudo mkdir -p /etc/systemd/system/ollama.service.d
printf '[Service]\nEnvironment="OLLAMA_HOST=0.0.0.0:11434"\nEnvironment="OLLAMA_KEEP_ALIVE=30m"\n' | sudo tee /etc/systemd/system/ollama.service.d/override.conf
sudo systemctl daemon-reload && sudo systemctl restart ollama
ollama pull qwen3:8b
ollama list
nvidia-smi            # the model should load on the GPU on first use
```

3. On the same host nothing else is needed. For a separate model server, note its private IP and set
   `OLLAMA_BASE_URL=http://<private IP>:11434` in `.env.prod`; the security group is the only access control
   Ollama has, so never open 11434 to the internet.
4. Check from the API server: `curl http://host.docker.internal:11434/api/tags` from inside the app container, or
   `curl http://<private IP>:11434/api/tags` for a separate server.

If the instance is stopped to save cost, the pipeline still completes: reports carry the calculated findings with
`ai.status = "unavailable"` and no narrative (see `DEVELOPER_MANUAL.md` section 4.4).

---

## 5. API EC2 (Laravel + analytics)

### 5.1 Instance

`t3a.xlarge` (4 vCPU, 16 GB; the model needs the memory) or `g4dn.xlarge` for GPU inference, Ubuntu 24.04 (the Deep
Learning Base GPU AMI for `g4dn`), 60 GB gp3, security group `dataspeak-server`, Elastic IP, no IAM permissions
needed (SES is used over SMTP credentials).

```bash
sudo apt-get update && sudo apt-get install -y ca-certificates curl git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu && newgrp docker
git clone <your repository URL> dataspeak && cd dataspeak
```

### 5.2 Configuration

```bash
cp deploy/aws/.env.prod.example deploy/aws/.env.prod
# generate the two secrets
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'   # APP_KEY
openssl rand -hex 32                                                                           # ANALYTICS_INTERNAL_TOKEN
nano deploy/aws/.env.prod
```

Fill in: `APP_KEY`, `APP_URL=https://api.example.com`, `SERVER_NAME=api.example.com`, `ACME_EMAIL`,
`FRONTEND_URL=https://app.example.com`, the RDS block, `ANALYTICS_INTERNAL_TOKEN`, the Ollama block and the SES
block (section 7). Leave `TRUSTED_PROXIES` empty: FrankenPHP terminates TLS itself, so the request scheme is
already `https`.

### 5.3 First start

```bash
cd deploy/aws
./deploy.sh --no-pull
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f app   # until "certificate obtained"
curl https://api.example.com/api/v1/health
```

`deploy.sh` builds the two images, starts Redis and the analytics container, runs `php artisan migrate --force`
against RDS, then starts the API, the worker and the scheduler. Re-run it for every update; the worker finishes its
current job before restarting (`stop_grace_period: 120s`).

What runs:

| Container | Command | Notes |
|---|---|---|
| `dataspeak-api` | `frankenphp run` | Ports 80/443; Let's Encrypt certificates in the `caddy_data` volume. |
| `dataspeak-worker` | `php artisan queue:work redis --queue=default,translations --tries=1 --timeout=600` | One worker; raise to two containers (`docker compose up -d --scale worker=2`) if reports queue up. |
| `dataspeak-scheduler` | `php artisan schedule:work` | Purges expired uploads every 5 minutes, reaps stale jobs every minute. |
| `dataspeak-redis` | `redis-server --appendonly yes` | Queue, cache, idempotency keys, translation flags. |
| `dataspeak-analytics` | `uvicorn app.main:app` | No published port; the API reaches it as `http://analytics:8000`. |

### 5.4 Operations

```bash
cd ~/dataspeak/deploy/aws
alias dc='docker compose -f docker-compose.prod.yml --env-file .env.prod'
dc ps                              # state
dc logs -f --tail=200 worker       # job logs (JSON lines, payloads never logged)
dc exec app php artisan dataspeak:reap-stale-jobs
dc exec app php artisan dataspeak:ai-eval <analysis-id> --runs=3 --json
dc run --rm --no-deps --entrypoint sh app -c 'php artisan migrate:status'
```

Backups: RDS automated snapshots cover all persistent data. The `app_storage` volume holds only transient uploads
and font caches; `redis_data` holds queue state. Neither needs a backup.

Updating: `git pull` happens inside `deploy.sh`; images are rebuilt with the new code, caches are warmed on
container start, migrations run before the new API starts.

---

## 6. Frontend on Amplify Hosting

1. Amplify console → New app → Host web app → connect the Git repository and branch (`main`).
2. Monorepo: set the app root to `frontend`. Amplify picks up the root `amplify.yml`, which runs `npm ci` and
   `npm run build` and publishes `frontend/dist`.
3. Environment variable: `VITE_API_BASE_URL = https://api.example.com/api/v1`. This is the only backend the
   browser knows.
4. Rewrites and redirects: add the single-page-app rule so deep links such as `/analyses/<id>/report` load the app.

   | Source | Target | Type |
   |---|---|---|
   | `</^[^.]+$\|\.(?!(css\|gif\|ico\|jpg\|jpeg\|js\|png\|txt\|svg\|woff\|woff2\|ttf\|map\|json\|csv)$)([^.]+$)/>` | `/index.html` | 200 (Rewrite) |

5. Custom domain `app.example.com` (Amplify issues the certificate). Until then the default
   `https://main.<app-id>.amplifyapp.com` URL works; add it to `CORS_ALLOWED_ORIGINS` on the API host if you use it.
6. Every push to the branch rebuilds and deploys. The frontend's Vitest suite is not run by Amplify; it runs in
   GitHub Actions (`.github/workflows/ci.yml`).

Cross-origin: the API allows the origin in `FRONTEND_URL` (plus `CORS_ALLOWED_ORIGINS`), the headers
`Authorization`, `Content-Type`, `Idempotency-Key`, `X-Dataspeak-Language`, and exposes `Content-Disposition` and
`Content-Language` so PDF downloads keep their file name. Authentication is a bearer token, so no cookies cross
origins and `supports_credentials` stays off.

---

## 7. Email verification with Amazon SES

Registration requires a confirmed email address, so production needs a real mailer.

1. SES → Verified identities → verify the sending domain (recommended) or at least `noreply@example.com`.
2. SES → SMTP settings → Create SMTP credentials (an IAM user with `ses:SendRawEmail`). Note the user name and
   password.
3. `.env.prod`:

```
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=email-smtp.ap-southeast-1.amazonaws.com
MAIL_PORT=587
MAIL_USERNAME=<SMTP user name>
MAIL_PASSWORD=<SMTP password>
MAIL_FROM_ADDRESS=noreply@example.com
```

4. While the SES account is in the sandbox, only verified recipient addresses receive mail. Request production
   access before opening registration to real users. The verification link in the mail points at
   `APP_URL/api/v1/auth/verify-email/...` and redirects to `FRONTEND_URL/login?verified=1`, so both URLs must be
   the public HTTPS ones.

---

## 8. Checklist after the first deployment

- `https://api.example.com/api/v1/health` returns `{"status":"ok"}` with database and Redis checks passing.
- `https://app.example.com` loads, register works, the verification mail arrives, the link lands on the login page
  with the green banner.
- Upload the sample dataset, run the pipeline to the report. In the API host logs `analytics.call` entries show
  `status_code: 200`, and `ai.generation_succeeded` appears when Ollama is reachable; `ai.unreachable` means the
  Ollama service is not running, the model tag is not pulled, or `OLLAMA_BASE_URL` is wrong.
- Switch the interface language to Thai and export the PDF: the download has the Thai file name and content.
- `docker compose ... exec app php artisan schedule:list` shows the two scheduled commands.

---

## 9. Costs and sizing (indicative, on-demand, ap-southeast-1)

| Item | Size | Approximate monthly cost |
|---|---|---|
| Server (API + analytics + Ollama) | t3a.xlarge, CPU inference | 125 USD running all month; a few USD if stopped when idle |
| Server, GPU variant | g4dn.xlarge | about 0.65 USD per running hour (475 USD if never stopped) |
| RDS | db.t4g.micro, 20 GB | 15 USD (free tier for 12 months on new accounts) |
| Amplify | build minutes and traffic | under 5 USD for a capstone |

Stopping the server outside demo hours is the main saving: the disk, the model and the Elastic IP stay, and the
next start needs no reconfiguration.

---

## 10. Variations

- **nginx on the host instead of FrankenPHP's own HTTPS (the layout used for the MVP server).** Set `PROXY=nginx` and
  `TRUSTED_PROXIES=*` in `.env.prod`; `deploy.sh` then adds `docker-compose.nginx.yml` (API on `127.0.0.1:8080`,
  smaller Redis) and installs `nginx/api.conf`. Issue the certificate once with
  `sudo certbot --nginx -d api.<domain> --redirect`; certbot renews it automatically.
- **Claude API instead of a model server (the production choice since 6 October 2026).** Set `AI_LLM_DRIVER=anthropic` and `ANTHROPIC_API_KEY=<key>` (model
  `claude-sonnet-5-5` by default) in `.env.prod` and redeploy; Ollama is no longer needed on the server and the
  instance can be small again. About five cents per report on Sonnet 5.5, two cents on Haiku 4.5.
- **Small instance without a model server.** On a 2 GB instance (`t4g.small`) run with `AI_ENABLED=false` and
  `PRETRANSLATE_REPORTS=false`: reports contain the calculated findings and charts without the narrative, and the
  stack fits in memory (add a 2 GB swap file). Add a model server later and switch the two flags back on.

- **ALB in front of the API.** Terminate TLS on an Application Load Balancer with an ACM certificate, target the
  instance on port 80, set `SERVER_NAME=:80` and `TRUSTED_PROXIES=*` so Laravel honours `X-Forwarded-Proto`.
- **ElastiCache instead of the Redis container.** Point `REDIS_HOST` at the cluster endpoint and remove the `redis`
  service and its dependency from the compose file.
- **Separate GPU EC2 for Ollama.** Launch it as described in section 4, open 11434 to `dataspeak-server` only, set
  `OLLAMA_BASE_URL=http://<private IP>:11434` and `OLLAMA_MODEL=qwen3:8b`, and the API server can shrink to 8 GB.
- **Separate EC2 for the analytics service.** Give it its own compose file with only the `analytics` service, publish
  port 8000 to the API server's security group only, and set `ANALYTICS_BASE_URL=http://<private-ip>:8000`.
