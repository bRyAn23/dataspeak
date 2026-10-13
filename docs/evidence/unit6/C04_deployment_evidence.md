# Unit 6 Evidence C04: Deployment Configuration and Build/Health Evidence

Data Speak, UoPeople MSIT 5910, Part C. Evidence collected on **2026-10-14, 00:15 to 00:16 (Asia/Bangkok, UTC+07:00)** from the repository at commit `c4a1951` on `main` (plus the uncommitted working tree listed in [01_test_environment.md](01_test_environment.md)) and from read-only requests to the production hosts. Nothing was deployed, migrated, rotated or changed in production. No credential appears in any image or log; the production environment file `deploy/aws/.env.prod` is gitignored and was not opened for this evidence.

Classification used throughout: **Verified** = observed through actual execution in this session; **Configured** = present in version-controlled configuration but not independently observed running in this session; **Planned** = described as future work.

## 1. Architecture as found in the repository

| Component | Documented | Found in source (2026-10-14) | Status |
| --- | --- | --- | --- |
| Frontend | React 19, TypeScript, Vite 6, Tailwind CSS | `frontend/package.json`: react ^19.0.0 (19.3.0 installed), typescript ^5.7.0 (5.9.3), **vite ^8.0.5 (8.3.1 ran the build)**, tailwindcss ^4.0.0. The documentation's "Vite 6" is out of date. | Verified (build executed) |
| Backend | PHP 8.4, Laravel 13, FrankenPHP | `composer.json` php ^8.3, laravel/framework ^13.17 (13.34.0 installed); `docker/backend/Dockerfile.prod` `FROM dunglas/frankenphp:1-php8.4`; host PHP 8.4.21, container PHP 8.4.26 | Verified locally; FrankenPHP image Configured for production |
| Analytics | Python 3.12, FastAPI, Polars, SciPy, statsmodels | `docker/analytics/Dockerfile` `FROM python:3.12-slim`; `requirements.txt` fastapi, polars, scipy, statsmodels, scikit-learn | Verified locally (container Python 3.12.15, health 200) |
| Database | PostgreSQL 17, Amazon RDS in production | dev compose `postgres:17` (17.11 running); prod env template has `DB_HOST`, `DB_SSLMODE`; production API health reports `database: ok` | Verified (dependency check via health endpoint) |
| Queue and cache | Redis 7 | dev `redis:7-alpine` (7.4.11); prod `redis:7-alpine` with 128 MB (64 MB in the nginx override), `volatile-lru`, AOF; production health reports `redis: ok` | Verified (dependency check) |
| Development LLM | Qwen via Ollama | dev compose `ollama` profile; `OLLAMA_MODEL=qwen3:8b`; prod compose still carries `host.docker.internal` for Ollama | Configured (dev); comment in prod compose is historical |
| Production LLM | Claude Messages API | `backend/config/services.php` `anthropic` block; `AI_LLM_DRIVER` env switch | Configured (not exercised in this session) |
| Containers | Docker, Docker Compose | `docker-compose.yml` (dev), `deploy/aws/docker-compose.prod.yml` + `docker-compose.nginx.yml` (prod) | Verified (dev stack running); prod Configured |
| Web server | nginx | `deploy/aws/nginx/api-ssl.conf.template`, `frontend.conf.template`; production responses carry `server: nginx/1.28.3 (Ubuntu)` | Verified (response header) |
| Hosting | AWS EC2 arm64 | `deploy/aws/push.sh` builds `--platform linux/arm64`; `docs/DEPLOYMENT_AWS.md` | Configured; host not inspected in this session |
| Production URLs | dataspeaknow.com, api.dataspeaknow.com | Both answered HTTP 200 over HTTPS | Verified |

## 2. Services configured for deployment (`deploy/aws/docker-compose.prod.yml`, screenshots C01 and C01b)

| # | Service | Configuration found | Status |
| --- | --- | --- | --- |
| 1 | nginx | Not a container. Installed on the EC2 host; `docker-compose.nginx.yml` rebinds the app to `127.0.0.1:8080` and sets `TRUSTED_PROXIES`; `nginx/api-ssl.conf.template` terminates TLS with Let's Encrypt certificates and proxies to the container; `frontend.conf.template` serves the static build. `deploy.sh` installs the site once and reloads nginx. | Verified running (production headers) |
| 2 | Laravel application (`app`) | Image `dataspeak-api:latest` from `Dockerfile.prod` (FrankenPHP, OPcache on, composer `--no-dev`, no `.env` baked); `env_file: .env.prod`; `restart: unless-stopped`; `depends_on` redis healthy and analytics started; healthcheck `curl http://localhost/up` (nginx layout); json-file logging capped at 5 × 20 MB; entrypoint refuses to start without `APP_KEY` and warms config/route/view caches | Verified reachable (production `/api/v1/health` 200); container state not inspected today |
| 3 | Laravel queue worker (`worker`) | Same image; `php artisan queue:work redis --queue=default,translations --tries=1 --timeout=600 --max-jobs=200`; `stop_grace_period: 120s`; healthcheck disabled (no web server in this process) | Configured |
| 4 | Laravel scheduler (`scheduler`) | Same image; `php artisan schedule:work`; runs upload purge and stale-job reaping | Configured |
| 5 | FastAPI analytics (`analytics`) | Image `dataspeak-analytics:latest`; `restart: unless-stopped`; **no published ports** (reachable only from containers on the host); `ANALYTICS_INTERNAL_TOKEN` injected from the env file; uploads spooled to a `tmpfs`; log rotation | Configured (local equivalent Verified: health 200 on loopback) |
| 6 | Redis (`redis`) | `redis:7-alpine`, AOF persistence, `maxmemory 64mb` with `volatile-lru` in the nginx layout, healthcheck `redis-cli ping`, named volume | Verified as a dependency (`redis: ok` in production health) |
| 7 | PostgreSQL connection to RDS | No container; `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE` in `.env.prod` (template committed, real file gitignored); migrations run by `deploy.sh` before the app restarts | Verified as a dependency (`database: ok` in production health) |

Networking: all containers share the default Compose network of project `dataspeak-prod`; only `app` publishes a port, and only on the host loopback; the analytics service is addressed as `http://analytics:8000` through `ANALYTICS_BASE_URL`. Secrets are referenced only as `${VARIABLE}` or through `env_file`; the compose files contain no literal credential, which is why no redaction was needed in C01.

## 3. Build verification (screenshot C02, log section 1 of C06)

| Item | Observed |
| --- | --- |
| Command | `cd frontend && npm ci && npm run build` (`build` = `tsc --noEmit -p tsconfig.json && vite build`) |
| Environment | macOS 26.6.2, Node v24.21.0, npm 11.19.0, Vite 8.3.1 |
| Started | 2026-10-14 00:15:00 +07 |
| `npm ci` | added 417 packages, audited 418 packages in 5 s |
| Type check and bundle | 2 597 modules transformed; `✓ built in 414ms`; exit code 0 |
| Output | `frontend/dist/`: `index.html` (0.91 kB), `assets/index-9MhTT88_.js` (973.89 kB, 279.29 kB gzip), `assets/index-DK2E9A9Z.css` (37.15 kB), logo, favicon, icons, robots.txt |
| Warnings | npm: two deprecated transitive packages; `4 vulnerabilities (2 moderate, 2 critical)` reported by `npm audit` in the dependency tree; three packages with install scripts not yet allow-listed. Vite: two configuration deprecation notices (`__dirname`, JSON import attributes) and one chunk larger than 500 kB after minification. No errors. |
| Status | **Verified: build succeeded.** This demonstrates that the committed source compiles and bundles; it does not by itself demonstrate that production is running this build. |

### 3b. Compose configuration validation (screenshot C02c, log section 6 of C06)

| Item | Observed (2026-10-14 00:46:44 +07) |
| --- | --- |
| Command | `docker compose -f docker-compose.prod.yml -f docker-compose.nginx.yml --env-file .env.prod.example config --quiet` run in `deploy/aws` with Docker Compose v5.5.1 |
| Environment file | The committed `.env.prod.example` was used deliberately so that no production secret was loaded or printed; validation depends on structure, not on secret values |
| Result | `config --quiet` exited 0 (configuration valid); `config --services` listed analytics, redis, app, scheduler, worker; `config --images` listed `dataspeak-api:latest` (three services), `redis:7-alpine`, `dataspeak-analytics:latest`; `config --volumes` listed app_storage, redis_data, caddy_data, caddy_config |
| Resolved `app` service | After the nginx override: `container_name: dataspeak-prod-api`, `restart: unless-stopped`, port published only on `host_ip 127.0.0.1` → `8080:80`, health check every 30 s with 5 retries, `SERVER_NAME: :80`, `TRUSTED_PROXIES: '*'`, start conditions `service_healthy` (redis) and `service_started` (analytics) |
| Development stack | `docker compose config --quiet` at the repository root (the same check the CI workflow runs) exited 0 |
| Status | **Verified.** This shows the version-controlled Compose definitions are syntactically and structurally valid and resolve to the intended services; it does not show that the containers are running on the EC2 host. |

## 4. Laravel verification (screenshot C02b, log sections 2 and 3 of C06)

| Item | Observed |
| --- | --- |
| `php artisan --version` | Laravel Framework 13.34.0 (PHP 8.4.21 on the host) |
| `php artisan route:list --path=api/v1` | 28 routes under `api/v1`, including `GET api/v1/health` → `Api\V1\HealthController`; the full list is in C06 |
| `php artisan test` | 148 passed, 903 assertions, 4.43 s, 2026-10-14 00:15:12 +07 (in-memory SQLite; FastAPI and LLM faked) |
| Status | **Verified.** No production migration, cache clear, key rotation or secret change was performed. |

## 5. Health verification (screenshot C03, log section 4 of C06)

Route confirmed first: `backend/routes/api.php` line 28 registers `GET /api/v1/health` to `HealthController`, which pings the database connection and Redis and returns `status`, `service`, `version` and per-dependency `checks`. The analytics health route is `GET /health` in `analytics/app/api/health.py` (PY-API-001, unauthenticated readiness); in production the analytics container publishes no port, so it is reachable only from the host's containers.

| Target | Request | Result (2026-10-14 00:15:59 to 00:16:00 +07) | What it demonstrates |
| --- | --- | --- | --- |
| FastAPI analytics, local | `GET http://127.0.0.1:8001/health` | HTTP/1.1 200, `server: uvicorn`, `{"status":"ok","service":"dataspeak-analytics","version":"1.0"}` | Local analytics container availability |
| Laravel API, local | `GET http://localhost:8000/api/v1/health` | HTTP/1.1 200, `{"status":"ok","service":"dataspeak-backend","version":"1.0","checks":{"database":"ok","redis":"ok"}}` | Local backend availability and local PostgreSQL and Redis dependency health |
| Production frontend | `GET https://dataspeaknow.com/` | HTTP 200 over HTTPS, certificate verified by curl (`ssl_verify=0`), 0.23 s | Static frontend availability and valid TLS only; says nothing about the API |
| Production API | `GET https://api.dataspeaknow.com/api/v1/health` | HTTP/2 200 via `server: nginx/1.28.3 (Ubuntu)`, `date: Tue, 13 Oct 2026 17:16:00 GMT`, `checks: database ok, redis ok` | Backend availability through nginx and TLS, plus RDS and Redis dependency health as seen by the application |

Not demonstrated by these checks: that the production queue worker, scheduler and analytics containers are running, that background jobs complete, or that the production LLM provider is reachable. Those would require a shell on the EC2 host or an end-to-end run against production, neither of which was performed for this evidence. A homepage HTTP 200 is treated only as frontend availability.

### 5b. Continuous integration status (read-only GitHub API, 2026-10-14)

The repository contains `.github/workflows/ci.yml` with four jobs. The public GitHub API reports exactly one workflow run, on commit `c4a1951` (`main`, 2026-10-08 00:02 UTC): **Backend (Pint, PHPUnit) success; Frontend (lint, typecheck, test, build) success; Compose file validates success; Analytics (Ruff, PyTest) failure at step `Run pytest -q`.** The job log could not be downloaded (the API requires repository admin rights), so the failure was reproduced locally under the same conditions as the workflow: a clean `python:3.12-slim` container, `pip install -r requirements-dev.txt` (which resolved fastapi 0.143.0, pydantic 2.14.1, polars 2.0.0, numpy 2.5.4, scipy 1.18.1, pytest 9.1.1 on 2026-10-14) and `pytest -q` from the repository layout. Result: **48 passed, 1 failed**, the failing test being `tests/contract/test_openapi_contract.py::test_openapi_file_matches_application` (log: `logs/C07_pytest_python312_ci_reproduction.log`, C06 section 5).

Cause established by comparing the regenerated OpenAPI document with the frozen `docs/contracts/fastapi-openapi.json`: the set of paths is identical and only six leaves differ, all in the three multipart `file` upload fields, where newer FastAPI/Pydantic emit `contentMediaType` instead of `format: binary`. The frozen contract was generated with the older library versions installed in the local virtual environment (FastAPI 0.128.8, Pydantic 2.13.5), while `requirements.txt` uses open version ranges, so the CI runner installs newer releases. The failure therefore reflects dependency drift in the schema generator, not a change to Data Speak's API. All 48 functional and statistical tests pass on Python 3.12 with the newest libraries. No code or contract was changed for this evidence; the remediation (pin the FastAPI and Pydantic versions used to freeze the contract, or regenerate and review the contract under the pinned versions) is recorded as planned work. Whether the 2026-10-08 run failed for exactly this reason cannot be confirmed without the job log, but the reproduction fails at the same step under the same conditions.

## 6. Summary by classification

**Verified (this session)**
- Frontend production build succeeds from a clean install (`npm ci`, `vite build`, exit 0).
- Laravel 13.34.0 boots, lists 28 API routes, and passes 148 automated tests.
- Local FastAPI and Laravel health endpoints return 200 with dependency checks ok.
- Production frontend and production API health both return 200 over HTTPS; the API reports database and Redis ok; nginx 1.28.3 fronts the API.
- Production compose files, Dockerfiles, nginx templates, deploy scripts and the env template are version-controlled; the real env file is gitignored.

**Configured (in repository, not observed running today)**
- GitHub Actions workflow `.github/workflows/ci.yml` (frontend lint, format, typecheck, test, build; backend Pint and PHPUnit; analytics Ruff and pytest on Python 3.12; `docker compose config`), committed and present on `origin/main`; its only run so far failed in the analytics job (section 5b).
- Queue worker and scheduler containers and their commands; FastAPI analytics container with no published ports; Redis memory policy and AOF; restart policies; health checks; log rotation; FrankenPHP production image with OPcache; entrypoint cache warm-up and `APP_KEY` guard; migrations in `deploy.sh`; `DB_SSLMODE` for RDS; `TRUSTED_PROXIES` and CORS origins; Claude as production LLM driver; arm64 image build and `docker save | ssh docker load` transfer in `push.sh`.

**Planned (not implemented)**
- Continuous delivery: a CI workflow exists and is treated as Configured (see section 5b), but it does not deploy; releases are manual. Making the workflow pass and adding deployment from a tagged build remain planned. An `amplify.yml` exists for AWS Amplify hosting of the frontend, but no Amplify app is connected; the frontend is served by nginx on the EC2 host.
- Transactional email provider (SES) and re-enabling email verification (`REQUIRE_VERIFIED_EMAIL=false` in production).
- Pinned, versioned image tags and a documented rollback procedure (images are tagged `latest`; rollback currently means rebuilding a previous commit or reloading a retained image).
- Externalised secret management (secrets live in the gitignored `.env.prod` on the host), monitoring and alerting beyond the health endpoint, automated backup verification for RDS, and a multi-instance topology for availability.

## 7. Evidence files

| File | Content |
| --- | --- |
| C01_deployment_configuration.png | VS Code editor window: `docker-compose.prod.yml` lines 15-51 (anchor settings, `app` service, start of `worker`) |
| C01b_deployment_configuration_part2.png | VS Code editor pane: lines 44-102 (`worker`, `scheduler`, `redis`, `analytics`, `volumes`) |
| C01t_deployment_configuration_terminal.png, C01t2_deployment_configuration_terminal_part2.png | The same file (lines 1-62 and 63-101) plus the `docker-compose.nginx.yml` override printed with line numbers in a Terminal window |
| C02_frontend_build.png | Terminal: `npm ci && npm run build`, built in 414 ms, exit 0, `dist/` listing |
| C02c_docker_compose_config.png | Terminal: `docker compose config` validation of the production files (exit 0), services, images, volumes, resolved `app` service, and the development-stack check |
| C02b_laravel_tests.png | Terminal: Laravel 13.34.0, 148 tests passed |
| C03_application_health.png | Terminal: the four health requests with status lines, headers and bodies |
| C06_build_health_logs.txt | Verbatim logs of all of the above, the API route list, and the Python 3.12 CI reproduction |
| logs/C07_pytest_python312_ci_reproduction.log | Clean-container reproduction of the CI analytics job (48 passed, 1 failed) |
| logs/C02_frontend_build.log, logs/C02b_laravel_tests.log, logs/C03_health_checks.log, logs/C05_laravel_route_list.log | Original per-command logs |

**Capture method.** C01 and C01b are screenshots of Visual Studio Code with `deploy/aws/docker-compose.prod.yml` open (file name visible in the editor tab and breadcrumb): C01 is a dedicated editor window showing lines 15-51 (the shared `x-app` anchor, the `app` service with ports, volumes and health check, and the start of `worker`); C01b is the editor pane of the main workspace window showing lines 44-102 (`worker`, `scheduler`, `redis`, `analytics`, `volumes`). Both were taken with the macOS `screencapture` tool and cropped to the window or pane; no content was edited. Terminal renderings of the same file with line numbers are kept as C01t and C01t2 for completeness. No redaction was required because the file contains only `${VARIABLE}` references and no hostnames or credentials.

## 8. APA 7 figure captions

The current draft already numbers Figures 1 to 8, and its Figure 8 is the Part C placeholder "Deployment Configuration and Successful Build Evidence". The captions below therefore use Figure 8 for the configuration and Figure 9 for the build and health evidence. Renumber if figures are inserted earlier.

**Figure 8**

*AWS Docker Compose Deployment Configuration*

*Note.* The screenshot presents the version-controlled configuration `deploy/aws/docker-compose.prod.yml` for the Data Speak application services on the single arm64 EC2 host: the Laravel API container built from the FrankenPHP image, the queue worker and scheduler that reuse the same image, the Redis service with memory and persistence settings, and the FastAPI analytics service with no published ports, shown in Visual Studio Code across two panels. Values are injected from an environment file that is excluded from version control; no sensitive value appears. Captured 14 October 2026 from the repository at commit c4a1951.

**Figure 9**

*Data Speak Production Build and Application Health Verification*

*Note.* Panel (a) documents `docker compose config --quiet` on the production Compose files with the committed example environment file (Docker Compose v5.5.1, exit code 0, five services resolved) and `npm ci && npm run build` executed on 14 October 2026 at 00:15 (macOS 26.6.2, Node 24.21.0, Vite 8.3.1): 417 packages installed, 2,597 modules transformed, build completed in 414 ms with exit code 0 and output in `frontend/dist/`. Panel (b) documents `php artisan test` on Laravel 13.34.0: 148 tests passed with 903 assertions in 4.43 s. Panel (c) documents read-only health requests at 00:16: the local FastAPI and Laravel health endpoints returned HTTP 200, the production frontend returned HTTP 200 over HTTPS, and the production API health endpoint returned HTTP/2 200 through nginx 1.28.3 with database and Redis checks reporting ok. A successful local build does not by itself show that production runs this build, and the homepage response shows frontend availability only.

## 9. Part C paragraph (APA 7, 130 words)

Data Speak runs on one arm64 Amazon EC2 instance with Amazon RDS for PostgreSQL because this layout keeps the minimum viable product inexpensive while delegating database durability, backups and patching to a managed service. Docker images built from version-controlled Dockerfiles and Compose files give development and production the same runtime, and an environment file excluded from the repository separates the two configurations so secrets never enter source control. Such control of versions, builds and environments is the core of software configuration management (Summers, 2020; Bennett, 2024). On 14 October 2026 the production build succeeded, 148 backend tests passed, and the production health endpoint reported database and Redis availability. The committed CI workflow's only run failed on a contract test; a passing pipeline, pinned image tags and externalised secrets remain planned.
