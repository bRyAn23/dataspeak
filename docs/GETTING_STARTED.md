# Getting Started: running Data Speak from a clean machine

This guide takes you from a fresh clone to a running stack and a completed end-to-end analysis.
Read [DEVELOPER_MANUAL.md](DEVELOPER_MANUAL.md) for the program and API lists and [ARCHITECTURE_NOTES.md](ARCHITECTURE_NOTES.md) for how the pieces work.

Two ways to run:

- **Docker (recommended).** One command starts all six containers. You only need Docker Desktop.
- **Native.** Run Laravel, FastAPI and Vite on your machine, with only Postgres and Redis in Docker.
  Useful for debugging a single service.

---

## 1. Prerequisites

| Tool | Docker path | Native path |
| --- | --- | --- |
| Git | yes | yes |
| Docker Desktop 4.x or later (Compose v2) | yes | yes, for Postgres and Redis |
| Node.js 24 and npm | no | yes |
| PHP 8.4 with `pdo_pgsql`, `mbstring`, `intl`, `fileinfo`, `sqlite3` | no | yes |
| Composer 2 | no | yes |
| Python 3.12 (3.9 works) and `pip` | no | yes |
| Ollama 0.3x or later with a Qwen model (`ollama pull qwen3:8b`) | yes, native on the host (or the compose `ollama` profile) | yes |

Ports used on the host: 5173 (frontend), 8000 (Laravel), 8001 (FastAPI, loopback only),
5432 (Postgres, loopback only), 6379 (Redis, loopback only), 11434 (Ollama, loopback only). Stop anything else using them.

## 2. Clone and create the environment files

```bash
git clone https://github.com/bRyAn23/dataspeak.git
cd dataspeak

cp .env.example .env                    # compose settings (ports, dev database password, internal token)
cp backend/.env.example backend/.env    # Laravel
cp frontend/.env.example frontend/.env  # VITE_API_BASE_URL only
cp analytics/.env.example analytics/.env
```

The examples contain development defaults and no real secrets. The one value shared between two
services is `ANALYTICS_INTERNAL_TOKEN`: the root `.env` injects it into both the Laravel and the
FastAPI containers, so for Docker you do not need to touch it. For the native path, set the same
value in `backend/.env` and `analytics/.env`. Generate your own with `openssl rand -hex 32`.

## 3. Run with Docker (recommended)

### 3.0 Install the AI model (once)

The executive narrative is produced by a Qwen model served locally by Ollama. Install Ollama from
ollama.com, then:

```bash
ollama --version            # 0.3x or later (structured outputs)
ollama pull qwen3:8b        # about 5 GB; use qwen3:4b on machines with less than 16 GB RAM
ollama list                 # the tag must appear here
```

On macOS keep Ollama native (Docker has no GPU access there); the backend container reaches it at
`http://host.docker.internal:11434`, which is the default in the root `.env`. On Linux you may run it
as a container instead: `docker compose --profile ollama up -d` then
`docker compose exec ollama ollama pull qwen3:8b` and set `OLLAMA_BASE_URL=http://ollama:11434`.
If no model is available the pipeline still completes: reports carry the deterministic findings and
`ai.status = "unavailable"`.

### 3.1 Build and start

```bash
docker compose up -d --build
```

The first build downloads base images and installs dependencies; expect several minutes. Later
starts take seconds.

Check that everything is up:

```bash
docker compose ps
```

You should see `dataspeak-frontend`, `dataspeak-backend`, `dataspeak-backend-worker`,
`dataspeak-analytics`, `dataspeak-postgres (healthy)` and `dataspeak-redis (healthy)`.

### 3.2 Generate the application key and migrate the database

```bash
docker compose exec backend php artisan key:generate      # first time only; writes APP_KEY into backend/.env
docker compose exec backend php artisan migrate           # creates all tables
docker compose exec backend php artisan migrate:status    # 16 migrations should show "Ran"
```

### 3.3 Verify

```bash
curl http://localhost:8000/api/v1/health
# {"data":{"status":"ok","service":"dataspeak-backend","version":"1.0","checks":{"database":"ok","redis":"ok"}}}

curl http://127.0.0.1:8001/health
# {"status":"ok","service":"dataspeak-analytics","version":"1.0"}

open http://localhost:5173            # the frontend: register or log in, then follow the five steps
open http://127.0.0.1:8025            # Mailpit: every email the stack sends (the sign-up confirmation link)
```

### 3.4 Run the end-to-end smoke test

This drives the real stack through the whole workflow with the committed synthetic dataset:
register, confirm the email (the script reads the link from Mailpit), create an analysis, upload,
validate, profile, define, run (queued job processed by the worker), poll, insights, visualizations,
report, delete.

```bash
python3 tests/e2e/smoke_workflow.py
# ...
# ok   status (ANALYZED 100%) -> 200
# ok   insights (7) -> 200
# ok   visualizations (8) -> 200
# ok   report generate -> 201
# E2E SMOKE PASSED
```

### 3.5 Day-to-day commands

```bash
docker compose logs -f backend backend-worker analytics   # follow logs (JSON lines)
docker compose restart backend-worker                     # after editing backend PHP (the worker caches code)
docker compose up -d --force-recreate -V backend backend-worker   # after changing composer.json
docker compose up -d --force-recreate -V frontend         # after changing package.json
docker compose exec backend php artisan dataspeak:purge-uploads   # purge expired transient uploads now
docker compose exec backend php artisan tinker
docker compose exec postgres psql -U dataspeak -d dataspeak
docker compose down                                       # stop; data stays in the postgres_data volume
docker compose down -v                                    # stop and delete the database volume
```

Web and analytics containers reload code automatically from the bind mounts. Only the worker needs
a restart.

## 4. Run natively (Postgres and Redis in Docker)

### 4.1 Start the data services only

```bash
docker compose up -d postgres redis
```

### 4.2 Backend (Laravel)

```bash
cd backend
composer install
php artisan key:generate
# backend/.env already points at 127.0.0.1:5432 / 127.0.0.1:6379 with the dev credentials
# set ANALYTICS_BASE_URL=http://127.0.0.1:8001 and ANALYTICS_INTERNAL_TOKEN=<your token>
php artisan migrate
php artisan serve                      # http://localhost:8000  (artisan serve is fine outside Docker)
```

In a second terminal, start the queue worker. Without it, `POST /run` is accepted but never processed:

```bash
cd backend
php artisan queue:work redis --queue=default,translations --tries=1 --timeout=600
```

### 4.3 Analytics (FastAPI)

```bash
cd analytics
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements-dev.txt
# analytics/.env: ANALYTICS_INTERNAL_TOKEN=<same token as backend>, DATASPEAK_TMP_DIR=./tmp
uvicorn app.main:app --reload --host 127.0.0.1 --port 8001
```

Interactive API docs are at http://127.0.0.1:8001/docs (internal use only).

### 4.4 Frontend (Vite)

```bash
cd frontend
npm install
npm run dev                            # http://localhost:5173
```

`frontend/.env` must contain `VITE_API_BASE_URL=http://localhost:8000/api/v1`. There is
deliberately no FastAPI URL in the frontend.

### 4.5 Verify

Same checks as section 3.3, then `python3 tests/e2e/smoke_workflow.py`.

## 5. Running the test suites

| Service | Lint and format | Tests |
| --- | --- | --- |
| backend | `cd backend && vendor/bin/pint --test` | `cd backend && php artisan test` (62 tests, SQLite in memory, no services needed) |
| analytics | `cd analytics && ruff check . && ruff format --check .` | `cd analytics && pytest` (41 tests) |
| frontend | `cd frontend && npm run lint && npm run format:check && npm run typecheck` | `cd frontend && npm test` |
| all | | `docker compose config --quiet` validates the stack file |

CI runs exactly these commands (`.github/workflows/ci.yml`).

After changing any FastAPI schema, regenerate the frozen contract and commit the diff:

```bash
cd analytics && python -m scripts.export_openapi
```

After changing the schema design, regenerate the dictionary:

```bash
analytics/.venv/bin/python database/build_data_dictionary.py
```

## 6. Your first analysis in the browser

Open http://localhost:5173 and register (passwords need 8 characters). The language switcher (English, ไทย, 中文, မြန်မာ) sits on the login card and at the bottom of the sidebar; the report is written in the language active when you press **Analyze** or **Rewrite summary**, and the PDF follows the report. You land on **Confirm your email**:
open Mailpit at http://127.0.0.1:8025, open the message from Data Speak and click **Confirm my email**.
The API confirms the address and sends the browser back to the login page with a green banner; log in
(or press **I have confirmed my email** in the first tab). Until that click, the app refuses analysis
routes with "Please confirm your email address first". Then click **New analysis**.
Pick a CSV or an Excel workbook (.xlsx; the worksheet with the largest table is used) or use **Use the sample retail dataset**, press **Create and upload**, then follow the stepper:
Profile shows what was detected together with a suggested English name and meaning per column (the AI model proposes them from the headers and detected types, software keeps only what fits the values), Define lets you confirm or change them (a currency needs a unit; names must be English because the whole report, chart labels included, is produced in English),
Analyze runs the statistics, insights and chart recommendations with a live progress bar, then stops so you
can review the charts: untick any you do not want, add others from the list of charts the results support, and
press **Write the executive summary**. The narrative is written from the charts you kept, a completion screen
appears and the report opens by itself. Every checked sentence has a **How we know** link that opens the traceability
panel. Rejected AI claims are never shown. **Export PDF** in the report header downloads the same report
as a PDF with a cover page, the Data Speak letterhead on every page and timestamped, numbered footers.

## 7. Your first analysis by hand (curl)

Everything the frontend does, using the committed golden dataset.

```bash
API=http://localhost:8000/api/v1

# 1. Register and keep the token
TOKEN=$(curl -s -X POST $API/auth/register -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Dev","email":"dev@example.com","password":"dev-password-1","password_confirmation":"dev-password-1"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["token"])')
AUTH="Authorization: Bearer $TOKEN"

# 1b. Confirm the email: fetch the signed link from Mailpit and follow it once (302 to the frontend login)
LINK=$(curl -s "http://127.0.0.1:8025/api/v1/search?query=to:dev@example.com" \
  | python3 -c 'import sys,json,urllib.request,re,html; m=json.load(sys.stdin)["messages"][0]["ID"]; b=json.load(urllib.request.urlopen(f"http://127.0.0.1:8025/api/v1/message/{m}")); print(re.search(r"https?://[^\s\"<>]+/auth/verify-email/[^\s\"<>]+", html.unescape(b["HTML"]) + b["Text"]).group(0))')
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' "$LINK"     # 302 http://localhost:5173/login?verified=1

# 2. Create an analysis shell
ID=$(curl -s -X POST $API/analyses -H "$AUTH" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Retail 2025"}' | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["id"])')

# 3. Upload the CSV (transient), validate, profile
curl -s -X POST $API/analyses/$ID/dataset -H "$AUTH" -H 'Accept: application/json' -F file=@analytics/tests/golden/retail_sales.csv
curl -s -X POST $API/analyses/$ID/validate -H "$AUTH" -H 'Accept: application/json'
curl -s -X POST $API/analyses/$ID/profile  -H "$AUTH" -H 'Accept: application/json'   # 202: queued job
# Profiling and the suggested definitions run in the background (10-60 s). Poll until PROFILED:
until curl -s $API/analyses/$ID/status -H "$AUTH" -H 'Accept: application/json' | grep -q '"status":"PROFILED"'; do sleep 2; done
curl -s $API/analyses/$ID/profile -H "$AUTH" -H 'Accept: application/json'            # columns with suggested definitions

# 4. Define business meaning (enum values come from the data dictionary)
curl -s -X PUT $API/analyses/$ID/definitions -H "$AUTH" -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{
  "definitions": [
    {"column":"order_date","business_name":"Order Date","semantic_type":"DATE"},
    {"column":"region","business_name":"Sales Region","semantic_type":"CATEGORY"},
    {"column":"product_category","business_name":"Product Category","semantic_type":"CATEGORY"},
    {"column":"ยอดขาย_thb","business_name":"Revenue","semantic_type":"CURRENCY","unit":"THB","aggregation":"SUM"},
    {"column":"ရောင်းအား_units","business_name":"Units","semantic_type":"NUMBER","aggregation":"SUM"},
    {"column":"discount_pct","business_name":"Discount","semantic_type":"PERCENTAGE","aggregation":"AVERAGE"},
    {"column":"customer_id","business_name":"Customer","semantic_type":"IDENTIFIER"}
  ]}'

# 5. Run (queued) and poll every 2 seconds until is_terminal is true (COMPLETED or FAILED). The run chains
#    straight into report generation (AUTO_REPORT=true), so one POST does everything.
curl -s -X POST $API/analyses/$ID/run -H "$AUTH" -H 'Accept: application/json' -H "Idempotency-Key: $(uuidgen)"
curl -s $API/analyses/$ID/status -H "$AUTH" -H 'Accept: application/json'

# 6. Results
curl -s $API/analyses/$ID/insights        -H "$AUTH" -H 'Accept: application/json'
curl -s $API/analyses/$ID/visualizations  -H "$AUTH" -H 'Accept: application/json'

# 7. Report: already generated by the run above. (POST /report regenerates the narrative on demand.)
curl -s $API/analyses/$ID/report          -H "$AUTH" -H 'Accept: application/json'
#    data.ai.status is "ready" when validated claims exist, otherwise "unavailable" or "no_valid_claims".
#    data.claims[].statement is rendered from evidence; statement_template keeps the placeholders.
curl -s $API/analyses/$ID/claims/<claim-id> -H "$AUTH" -H 'Accept: application/json'   # traceability

# 8. Clean up (removes all derived records; the transient file was already deleted after the run)
curl -s -X DELETE $API/analyses/$ID -H "$AUTH" -H 'Accept: application/json' -o /dev/null -w '%{http_code}\n'
```

The analysis status model you will see: `CREATED -> UPLOADED -> VALIDATED -> PROFILED -> DEFINED ->
ANALYZING -> ANALYZED -> REPORTING -> COMPLETED`, with `FAILED` from any processing stage. Invalid
step order returns HTTP 409 with a code such as `STEP_NOT_READY`.

```bash
# 9b. Chart review (the run stops at ANALYZED): see what can be added, keep all recommended charts, add the first option, then write the report
curl -s $API/analyses/$ID/visualizations/options -H "$AUTH" -H 'Accept: application/json'
# PUT $API/analyses/$ID/visualizations/selection with {"selected": [<ids from GET /visualizations>], "add": ["<option key>"]}
# POST $API/analyses/$ID/report  (202), then poll /status until COMPLETED

# 10. Download the PDF (after status is COMPLETED)
curl -s -H "$AUTH" -o report.pdf -D - $API/analyses/$ID/report/pdf | grep -i 'content-'
```

## 8. Common problems

| Problem | What to do |
| --- | --- |
| `docker compose up` sits on "Starting" or `docker start` hangs, but `docker ps` works | Docker Desktop's engine is wedged. Quit Docker Desktop; if `com.docker.backend` processes remain, `pkill -f com.docker.backend`, then relaunch. Your database volume is kept. |
| Backend container exits immediately mentioning a missing class | Stale vendor volume from an earlier image. `docker compose up -d --force-recreate -V backend backend-worker`. |
| `/api/v1/health` reports `database: down` | Migrations or connection settings. Inside Docker the hostnames are `postgres` and `redis`; natively they are `127.0.0.1`. Check `docker compose logs backend`. |
| `POST /run` returns 202 but status never leaves ANALYZING | The queue worker is not running (Docker: `docker compose ps backend-worker`; native: start `php artisan queue:work redis`). |
| An analysis stays on "Preparing report" / the progress bar never moves | The background job died (worker restarted, container stopped). The status endpoint and a scheduled sweep mark it failed with `JOB_INTERRUPTED` once the job deadline (15 min + 2 min grace) passes; to do it now run `docker compose exec backend php artisan dataspeak:reap-stale-jobs --force --analysis=<id>`. The Analyze page then offers **Build the report again**; the statistics are kept, no re-upload needed. |
| `403 EMAIL_NOT_VERIFIED` on every analysis call | The account has not clicked its confirmation link. Open http://127.0.0.1:8025, click **Confirm my email**, or `POST /auth/email/resend`. For a closed lab set `REQUIRE_VERIFIED_EMAIL=false`. |
| No confirmation email arrives | `docker compose ps mailpit`; the backend must have `MAIL_HOST=mailpit` (compose sets it). Natively with `MAIL_MAILER=log` the email, link included, is in `backend/storage/logs/laravel.log`. |
| PDF export answers 500 with "GD extension" | Rebuild the backend image (`docker compose build backend backend-worker`); natively install `php-gd`. |
| Report completes with `ai.status: unavailable` | Ollama is not reachable from the backend or the model is not pulled. Check `curl http://127.0.0.1:11434/api/tags`, `OLLAMA_BASE_URL` (inside Docker it must be `host.docker.internal` or `ollama`), and `docker compose logs backend-worker` for `ai.unreachable`. |
| Report completes with `ai.status: no_valid_claims` | The model's claims failed validation (digits, causal phrasing, unknown evidence). Run `php artisan dataspeak:ai-eval <id>` to see rejection reasons by rule. |
| Analytics returns `ANALYTICS_UNAUTHORIZED` through Laravel (HTTP 502) | `ANALYTICS_INTERNAL_TOKEN` differs between the two services. |
| Upload returns 422 `VALIDATION_FAILED` | Only `.csv` files up to `UPLOAD_MAX_MB` (50 MB) with content are accepted. |
| `php artisan test` cannot find phpunit | Run it from the `backend/` directory. |
| Browser shows 'The server could not be reached' | `VITE_API_BASE_URL` in `frontend/.env` must point at the Laravel port (default `http://localhost:8000/api/v1`) and the backend must be up. After editing `frontend/.env`, restart Vite. |
| Port already in use | Change `FRONTEND_PORT`, `BACKEND_PORT`, `ANALYTICS_LOOPBACK_PORT`, `POSTGRES_LOOPBACK_PORT` or `REDIS_LOOPBACK_PORT` in the root `.env`. |
| macOS has no `timeout` command | Use `perl -e 'alarm 30; exec @ARGV' <command>` when you need to time-box a Docker call. |

## 9. Where things are

| You want | Look at |
| --- | --- |
| API routes | `backend/routes/api.php` |
| State machine | `backend/app/Services/Lifecycle/AnalysisLifecycle.php` |
| Analysis pipeline job | `backend/app/Jobs/RunAnalysisJob.php` |
| Frontend routes and pages | `frontend/src/app/routes.tsx`, `frontend/src/pages/` |
| Frontend API client and status labels | `frontend/src/services/apiClient.ts`, `frontend/src/utils/statusLabels.ts` |
| Statistical rules and thresholds | `analytics/app/services/analysis/rules.py` |
| AI prompt, schema, gateway | `backend/app/Services/Ai/` |
| Claim validation rules | `backend/app/Services/Validation/RuleBasedClaimValidator.php`, thresholds in `backend/config/dataspeak.php` (`ai` block) |
| Laravel to FastAPI contract | `docs/contracts/analytics-contract.md`, `docs/contracts/fastapi-openapi.json` |
| Schema source of truth | `database/build_data_dictionary.py`, `docs/Data_Speak_Data_Dictionary.xlsx` |
| Privacy and logging rules | `docs/conventions/logging-and-privacy.md` |
| Identifier conventions | `docs/conventions/identifiers.md` |
| Golden test data | `analytics/tests/golden/` |
