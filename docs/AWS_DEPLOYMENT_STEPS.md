# Deploying Data Speak to AWS, step by step (MVP layout)

This is the walkthrough version of [DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md), written for a first deployment by
someone new to AWS. It uses the smallest layout that runs the whole product: **one EC2 server** for the API, the
analytics service and the Ollama model, one small RDS database, and Amplify for the frontend. Follow the steps in
order; each one ends with a check so you know it worked before moving on. Budget about half a day, most of it
waiting for AWS to create things.

What you will build and what it costs (ap-southeast-1, on-demand, approximate):

| Step | What | AWS service | Monthly cost |
|---|---|---|---|
| 5 | Database | RDS for PostgreSQL, `db.t4g.micro` | about 15 USD (free for 12 months on a new account) |
| 6 | Server: API + analytics + Ollama | EC2 `t3a.xlarge` (4 vCPU, 16 GB) | about 125 USD running all month; stop it when idle and pay only for the disk |
| 7 | Email | SES | cents |
| 8 | Frontend | Amplify Hosting | a few USD |

Total: roughly 140 USD a month always on, or well under 50 USD if the server is stopped outside demo hours.

Two choices you make once, in step 6:

| Option | Instance | Model | Narrative speed | When to pick |
|---|---|---|---|---|
| **A. CPU only (default)** | `t3a.xlarge`, 16 GB | `qwen3:4b` | 1 to 3 minutes per report, translations a few minutes in the background | MVP, demos, a handful of users |
| **B. GPU** | `g4dn.xlarge` (T4, 16 GB RAM), about 0.65 USD per hour | `qwen3:8b` | under a minute per report | When you want the better model and faster answers; stop it between demos |

Everything else in this guide is identical for A and B. You can switch later by launching a new instance and
repeating step 6; the database keeps all the data.

Keep this sheet of values and fill it in as you go:

```
Region ..................... ap-southeast-1 (Singapore)  <- pick the region closest to your users
Domain ..................... dataspeaknow.com
Frontend host .............. dataspeaknow.com (and www.dataspeaknow.com)
API host ................... api.dataspeaknow.com
RDS endpoint ............... (step 5)
RDS master password ........ (step 5)
Server Elastic IP .......... (step 6)
SES SMTP user / password ... (step 7)
```

The guide's examples use `example.com`; for this project read `dataspeaknow.com` for the frontend and
`api.dataspeaknow.com` for the API. Use the same region for every resource. Console steps start from https://console.aws.amazon.com with the region selected top-right.

---

## Step 0. What you need before starting

- An AWS account with a credit card attached.
- A domain name you control (any registrar). You will create two DNS records.
- Your Data Speak repository on GitHub (Amplify and the server pull from it).
- A computer with a terminal and `ssh` (Windows: PowerShell or Windows Terminal).

---

## Step 1. Secure the account and set a budget

1. Sign in as the root user once. Open **IAM** → **Users** → **Create user**, name `admin`, tick *Provide user
   access to the AWS Management Console*, *I want to create an IAM user*, set a password. Permissions: *Attach
   policies directly* → `AdministratorAccess`. Create.
2. Open the user → **Security credentials** → **Assign MFA device** (authenticator app). Do the same for the root
   user (IAM → Dashboard → *Add MFA for root user*). Sign out, sign in as `admin`, use it from now on.
3. **Billing and Cost Management** → **Budgets** → **Create budget** → *Use a template* → *Monthly cost budget*,
   for example 100 USD, your email. You get a mail at 85 percent and at 100 percent.

Check: signed in as `admin`, MFA on, budget exists.

---

## Step 2. Create the two security groups

A security group is a firewall attached to a resource. Open **EC2** → **Security Groups** → **Create security
group**, in the default VPC, twice:

| Name | Description | Inbound rules |
|---|---|---|
| `dataspeak-server` | API, analytics and Ollama | HTTP (80) from Anywhere-IPv4; HTTPS (443) from Anywhere-IPv4; Custom UDP 443 from Anywhere-IPv4; SSH (22) from *My IP* |
| `dataspeak-rds` | Database | PostgreSQL (5432), source = the `dataspeak-server` security group (type `sg-` in the source box and pick it) |

Leave outbound at the default. Ollama's port 11434 is **not** opened anywhere: the containers reach it inside the
server. If your public IP changes later, edit the SSH rule.

Check: two groups; `dataspeak-rds` has a single rule whose source is `dataspeak-server`.

---

## Step 3. Create an SSH key pair

**EC2** → **Key Pairs** → **Create key pair**: name `dataspeak`, RSA, `.pem`. The browser downloads
`dataspeak.pem`:

```bash
mkdir -p ~/.ssh && mv ~/Downloads/dataspeak.pem ~/.ssh/ && chmod 400 ~/.ssh/dataspeak.pem
```

(Windows PowerShell: skip `chmod`, keep the file in `C:\Users\<you>\.ssh\`.)

---

## Step 4. DNS records you will create later

| Record | Type | Points to | Created in |
|---|---|---|---|
| `api.example.com` | A | the server's Elastic IP | step 6 |
| `app.example.com` | CNAME | the value Amplify gives you | step 8 |

Route 53 is optional; your registrar's DNS panel works.

---

## Step 5. Create the database (RDS for PostgreSQL)

1. **RDS** → **Databases** → **Create database** → *Standard create*, engine **PostgreSQL 17.x**.
2. Template: *Free tier* if offered, otherwise *Dev/Test* with *Single DB instance*.
3. DB instance identifier `dataspeak`, master username `dataspeak`, *Self managed* password, type a strong one and
   write it on your sheet.
4. Instance `db.t4g.micro`. Storage gp3, 20 GB, autoscaling on.
5. Connectivity: *Don't connect to an EC2 compute resource*, default VPC, **Public access: No**, security group:
   remove `default`, choose `dataspeak-rds`.
6. *Additional configuration*: **Initial database name `dataspeak`** (easy to miss). Backups 7 days.
7. Create (5 to 10 minutes). When *Available*, copy the **Endpoint** to your sheet.

For the MVP the master user is the application user.

Check: status Available, endpoint copied.

---

## Step 6. Create the server and deploy the backend and the model

### 6a. Launch the instance

1. **EC2** → **Launch instances**. Name `dataspeak-server`.
2. AMI and type:
   - **Option A (CPU):** AMI *Ubuntu Server 24.04 LTS*, instance type `t3a.xlarge` (if not available, `t3.xlarge`).
   - **Option B (GPU):** AMI *Deep Learning Base OSS Nvidia Driver GPU AMI (Ubuntu 24.04)* (search in *Browse more
     AMIs*; it includes the NVIDIA driver), instance type `g4dn.xlarge`. New accounts must first request the quota
     *Running On-Demand G and VT instances* = 4 in **Service Quotas** → *Amazon EC2*; approval can take a day.
3. Key pair `dataspeak`. Network settings → *Edit*: default VPC, Auto-assign public IP **Enable**, *Select existing
   security group* → `dataspeak-server`.
4. Storage: 60 GiB gp3 (models and Docker images need room). Launch.

### 6b. Fixed address and DNS

1. **EC2** → **Elastic IPs** → **Allocate** → then **Actions** → **Associate** → instance `dataspeak-server`.
   Write the address on your sheet.
2. At your DNS provider create the A record `api.example.com` → Elastic IP. Confirm with
   `nslookup api.example.com` before continuing; the HTTPS certificate cannot be issued before DNS resolves.

**Testing before your domain is ready.** You can deploy on the instance's public DNS name
(`ec2-<ip>.<region>.compute.amazonaws.com`) first. Let's Encrypt does not issue certificates for `amazonaws.com`
names, so set `SERVER_NAME=http://ec2-...amazonaws.com` and `APP_URL=http://ec2-...amazonaws.com` (plain HTTP on
port 80), set `FRONTEND_URL=http://localhost:5173`, and run the frontend on your computer with
`VITE_API_BASE_URL=http://ec2-...amazonaws.com/api/v1 npm run dev` in `frontend/`. An HTTPS page on Amplify cannot
call an HTTP API, so Amplify waits until the domain is in place. Switch the three values back when DNS is ready.

### 6c. Install Docker and Ollama

```bash
ssh -i ~/.ssh/dataspeak.pem ubuntu@<Elastic IP>

sudo apt-get update && sudo apt-get install -y ca-certificates curl git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu

curl -fsSL https://ollama.com/install.sh | sh
sudo mkdir -p /etc/systemd/system/ollama.service.d
printf '[Service]\nEnvironment="OLLAMA_HOST=0.0.0.0:11434"\nEnvironment="OLLAMA_KEEP_ALIVE=30m"\n' | sudo tee /etc/systemd/system/ollama.service.d/override.conf
sudo systemctl daemon-reload && sudo systemctl restart ollama
ollama pull qwen3:4b        # option A (about 2.5 GB). Option B: ollama pull qwen3:8b
ollama list
exit
```

`OLLAMA_HOST=0.0.0.0` lets the Docker containers on this machine reach Ollama; the security group keeps the port
closed to the internet.

### 6d. Get the code and write the production settings

Log in again (the docker group applies to new sessions). For a private GitHub repository create a fine-grained
personal access token with *Contents: read* and use it as the password when git asks.

```bash
ssh -i ~/.ssh/dataspeak.pem ubuntu@<Elastic IP>
git clone https://github.com/<your-user>/dataspeak.git && cd dataspeak
cp deploy/aws/.env.prod.example deploy/aws/.env.prod
docker run --rm php:8.4-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'   # APP_KEY
openssl rand -hex 32                                                                                    # ANALYTICS_INTERNAL_TOKEN
nano deploy/aws/.env.prod
```

Edit these lines; leave the rest:

| Line | Value |
|---|---|
| `APP_KEY` | the `base64:...` string |
| `APP_URL` | `https://api.example.com` |
| `FRONTEND_URL` | `https://app.example.com` |
| `SERVER_NAME` | `api.example.com` |
| `ACME_EMAIL` | your email |
| `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` | RDS endpoint, `dataspeak`, the RDS password |
| `ANALYTICS_INTERNAL_TOKEN` | the hex string |
| `OLLAMA_MODEL` | `qwen3:4b` (option A, already the default) or `qwen3:8b` (option B) |
| `OLLAMA_TIMEOUT` | `600` for option A (default), `300` for option B |
| `MAIL_*` | after step 7 |

`OLLAMA_BASE_URL` stays `http://host.docker.internal:11434`. Save with `Ctrl+O`, `Enter`, `Ctrl+X`.

### 6e. Deploy

```bash
cd ~/dataspeak/deploy/aws
./deploy.sh --no-pull
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f app   # until "certificate obtained"; Ctrl+C
```

The first run builds the images (5 to 10 minutes), migrates the database, starts everything and prints the health
response.

Check, from your computer: `curl https://api.example.com/api/v1/health` returns `"status":"ok"` with
`"database":"up"` and `"redis":"up"`. On the server,
`docker compose -f docker-compose.prod.yml --env-file .env.prod exec app curl -s http://host.docker.internal:11434/api/tags`
lists your model.

If `database` is `down`: wrong endpoint or password, or the `dataspeak-rds` rule does not reference
`dataspeak-server`. Fix `.env.prod` and run `./deploy.sh --no-pull` again.

---

## Step 7. Email for account verification (SES)

1. **Amazon SES** → **Identities** → **Create identity** → *Email address*, the address you will send from; click
   the link SES mails you. (Later, verify the whole domain with the DKIM records SES shows.)
2. **SES** → **SMTP settings** → **Create SMTP credentials** → download them (shown once). Sheet.
3. Sandbox: new SES accounts deliver only to verified addresses. Verify each tester's address as an identity; before
   real users register, **Account dashboard** → **Request production access** (*Transactional*, "account verification
   emails for a web app").
4. On the server edit `deploy/aws/.env.prod`:

```
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=email-smtp.ap-southeast-1.amazonaws.com
MAIL_PORT=587
MAIL_USERNAME=<SMTP user name>
MAIL_PASSWORD=<SMTP password>
MAIL_FROM_ADDRESS=<verified sender address>
```

then `./deploy.sh --no-pull`.

---

## Step 8. Deploy the frontend (Amplify Hosting)

1. **AWS Amplify** → **Create new app** → **GitHub** → authorise → choose the repository and the `main` branch.
2. Tick **My app is a monorepo**, root directory `frontend`. Keep the detected `amplify.yml`.
3. *Advanced settings* → **Environment variables** → `VITE_API_BASE_URL` = `https://api.example.com/api/v1`.
4. Save and deploy (3 to 5 minutes). Open the `https://main.<id>.amplifyapp.com` URL: the login page loads.
5. **Hosting** → **Rewrites and redirects** → **Manage redirects** → add:
   source `</^[^.]+$|\.(?!(css|gif|ico|jpg|jpeg|js|png|txt|svg|woff|woff2|ttf|map|json|csv)$)([^.]+$)/>`,
   target `/index.html`, type `200 (Rewrite)`. Save.
6. **Hosting** → **Custom domains** → **Add domain** → `example.com`, subdomain `app` only. Add the CNAME Amplify
   shows at your DNS provider; the certificate takes 15 to 30 minutes.
7. If you also want the `amplifyapp.com` address to work, add it to `CORS_ALLOWED_ORIGINS` in `.env.prod` on the
   server and run `./deploy.sh --no-pull`.

Every push to `main` rebuilds and publishes the frontend.

Check: `https://app.example.com` loads with no CORS errors in the browser console.

---

## Step 9. First end-to-end test

1. Register with a verified test address, click the link in the mail: login page with the green banner.
2. Log in, create an analysis, load the sample dataset, continue through Profile and Define, press *Analyze and
   recommend charts*, review the charts, press *Write the executive summary*. With option A the summary takes one to
   three minutes; the page shows the stages while it works.
3. Open the report, switch the language to Thai, export the PDF. Thai and Burmese translations keep running in the
   background for a few minutes on option A; the page shows a notice and updates itself.

If the report says the AI model was unavailable: on the server run
`docker compose -f docker-compose.prod.yml --env-file .env.prod logs worker | grep ai.unreachable`, then
`systemctl status ollama` and `ollama list`. The rest of the report works without the model.

---

## Step 10. Day-to-day

Update after you push code:

```bash
ssh -i ~/.ssh/dataspeak.pem ubuntu@<Elastic IP>
cd ~/dataspeak/deploy/aws && ./deploy.sh
```

Useful commands on the server:

```bash
alias dc='docker compose -f ~/dataspeak/deploy/aws/docker-compose.prod.yml --env-file ~/dataspeak/deploy/aws/.env.prod'
dc ps                           # what is running
dc logs -f --tail=100 worker    # analysis, report and translation jobs
dc restart worker               # after changing model settings
sudo systemctl status ollama    # the model server
free -h                         # memory: the model plus the stack should leave a little headroom on 16 GB
```

Saving money: **stop the server when nobody uses the app** (EC2 → Instance state → Stop). The disk, the model, the
settings and the Elastic IP stay; starting it again takes a minute and needs no reconfiguration. An Elastic IP
attached to a stopped instance costs a few USD a month. RDS can be stopped for up to seven days at a time (RDS →
Actions → Stop temporarily) and restarts itself after that.

Backups: RDS keeps daily snapshots for 7 days. Nothing on the server holds permanent data.

Growing later: move Ollama to its own GPU instance by following DEPLOYMENT_AWS.md section 4 and setting
`OLLAMA_BASE_URL` to its private IP; or change the instance type in place (stop → Actions → Instance settings →
Change instance type → start).

---

## Step 11. Deleting everything (when the project ends)

Amplify app → EC2 instance (Terminate) → Elastic IP (Release) → RDS database (Delete, final snapshot optional) →
security groups → SES identities → the budget. Check **Billing** → **Bills** a month later.

---

## Common problems

| Symptom | Likely cause and fix |
|---|---|
| `ssh: connection timed out` | Your public IP changed; update the SSH rule in `dataspeak-server` to the new *My IP*. |
| HTTPS certificate error for more than 10 minutes | DNS not pointing at the Elastic IP yet, or port 80 closed; `dc logs app` shows `acme` errors. |
| Health shows `"database":"down"` | Wrong `DB_HOST` or `DB_PASSWORD`, or the RDS security group does not reference `dataspeak-server`. |
| Verification mail never arrives | SES credentials wrong, sender not verified, or recipient not verified while in the sandbox. |
| Browser console shows a CORS error | `FRONTEND_URL` or `CORS_ALLOWED_ORIGINS` does not exactly match the address in the browser (`https://`, no trailing slash). Redeploy after fixing. |
| Report completes with "AI model unavailable" | Ollama stopped (`sudo systemctl restart ollama`), model not pulled, or `OLLAMA_HOST` override missing. |
| Reports time out on option A | Use `qwen3:4b`, keep `OLLAMA_TIMEOUT=600`, and check `free -h`; if memory is short, choose a 16 GB type. |
| `g4dn.xlarge` launch fails with a quota or capacity message | Quota not yet approved, or no capacity in that Availability Zone; try another subnet or option A. |
| `deploy.sh` says `.env.prod is missing` | Wrong directory or step 6d skipped. |
