# Unit 6 Evidence 01: Test Environment

Data Speak quantitative performance evaluation, UoPeople MSIT 5910.
Every value below was read from the machine or the repository on the test date. Nothing is quoted from design documents unless marked as such.

## 1. Test date, place and code version

| Item | Value |
| --- | --- |
| Test date and time | 2026-10-13 22:19 (local) |
| Timezone | Asia/Bangkok, UTC+07:00 |
| Repository | https://github.com/bRyAn23/dataspeak.git |
| Branch and commit | `main` at `c4a1951` ("Add output-validation unit tests and evidence script") |
| Nearest tag | `v0.2.1` (unit-testing milestone) |
| Working tree | about 70 modified files not yet committed (production deployment, chart themes, Claude driver, reasons). The test suites below were run against this working tree. |

## 2. Test machine

| Item | Value | Source |
| --- | --- | --- |
| Operating system | macOS 26.6.2 (build 25G83), arm64 | `sw_vers`, `uname -m` |
| CPU | Apple M5 Pro, 18 logical cores | `sysctl machdep.cpu.brand_string hw.ncpu` |
| RAM | 64 GiB | `sysctl hw.memsize` (68 719 476 736 bytes) |
| Free disk | 706 GiB of 926 GiB | `df -h /` |
| Container runtime | Docker 29.8.1, Docker Compose v5.5.1 | `docker --version`, `docker compose version` |

Production is a separate AWS environment (one arm64 EC2 instance plus Amazon RDS). It was not modified for this evaluation. Only its public health endpoints were read (section 6).

## 3. Runtime and dependency versions

### 3.1 Analyst service (Python, FastAPI)

Two Python environments exist and they differ. Tests run on the host virtual environment; the Docker image runs the service.

| Item | Host venv (`analytics/.venv`, where tests run) | Docker container `analytics` (where the service runs) |
| --- | --- | --- |
| Python | 3.9.6 | 3.12.15 (image `python:3.12-slim`) |
| FastAPI | 0.128.8 | present (version not printed; pytest is not installed in the image) |
| Pydantic | 2.13.5 | |
| Polars | 1.36.1 | |
| NumPy | 2.0.2 | |
| SciPy | 1.13.1 | |
| statsmodels | 0.14.6 | |
| scikit-learn | 1.6.1 | |
| uvicorn | 0.39.0 | |
| httpx | 0.28.1 | |
| pytest | 8.4.2 | not installed |
| pytest-cov | 7.1.0 (installed 2026-10-08; listed in `requirements-dev.txt` as `pytest-cov>=5`) | not installed |
| coverage | 7.10.7 | |
| ruff | 0.16.10 | |

`analytics/pyproject.toml` declares `requires-python = ">=3.9"`, so both interpreters are within the supported range.

### 3.2 Backend (PHP, Laravel)

| Item | Host (where tests run) | Docker container `backend` |
| --- | --- | --- |
| PHP | 8.4.21 (NTS) | 8.4.26 (NTS) |
| Composer | 2.9.5 | |
| Laravel Framework | 13.34.0 | same code, same `composer.lock` |
| Laravel Sanctum | 4.3.3 | |
| barryvdh/laravel-dompdf | 3.1.2 | |
| PHPUnit | 12.5.37 | |

Backend tests use in-memory SQLite (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) and fake both FastAPI and the language model, so they need no running container.

### 3.3 Frontend (React, TypeScript)

| Item | Version |
| --- | --- |
| Node.js | 24.21.0 |
| npm | 11.19.0 |
| TypeScript | 5.9.3 |
| React | 19.3.0 |
| TanStack Query | 5.104.1 |
| Recharts | 3.10.1 |
| Vitest | 3.2.7 |
| Testing Library React | ^16.3.0, MSW ^2.15.0, @vitest/coverage-v8 ^3.2.4 (package.json ranges) |

### 3.4 Data stores (Docker Compose, development stack)

| Service | Image | Version reported by the service |
| --- | --- | --- |
| PostgreSQL | `postgres:17` | PostgreSQL 17.11 (Debian, aarch64) |
| Redis | `redis:7-alpine` | 7.4.11 |

### 3.5 Language model

| Environment | Setting | Verified state |
| --- | --- | --- |
| Development | `AI_LLM_DRIVER=ollama`, `OLLAMA_MODEL=qwen3:8b`, `OLLAMA_BASE_URL=http://host.docker.internal:11434` (read from the running backend container's config) | Ollama binary installed at `/usr/local/bin/ollama`; model manifest `qwen3:8b` present under `~/.ollama/models`; **the Ollama server was not running** at test time (no process, port 11434 not answering). The Compose `ollama` service sits behind the `ollama` profile and has no container. |
| Production | `AI_LLM_DRIVER=anthropic`, Claude Messages API, model `claude-haiku-4-5-20251001` (from `deploy/aws/.env.prod`, not re-read here) | Not exercised in this evaluation. |

## 4. Where the evaluated code and tests are

| Purpose | Location |
| --- | --- |
| Statistical accuracy tests (golden values) | `analytics/tests/unit/test_engine_golden.py`: `test_tiny_golden_hand_verified_values`, `test_retail_golden_cross_checked_with_statistics_module`, `test_determinism_same_input_same_evidence`, `test_insights_reference_evidence_and_avoid_causal_language`, `test_visualizations_use_units_from_definitions_and_flag_missing_units` |
| Golden datasets | `analytics/tests/golden/tiny.csv` (6 data rows, hand-checked: amount mean 30, median 30, min 10, max 50, sum 180), `analytics/tests/golden/retail_sales.csv` (240 data rows, expectations computed with Python's `statistics` module), `analytics/tests/golden/README.md` |
| CSV validation and profiling tests | `analytics/tests/unit/test_csv_reader.py`, `test_inference_and_profile.py`, `test_output_validation.py` |
| Method selection rules | `analytics/tests/unit/test_rules_and_selection.py` |
| Frozen API contract | `analytics/tests/contract/test_openapi_contract.py` against `docs/contracts/fastapi-openapi.json` |
| Analysis status endpoint | `GET /api/v1/analyses/{id}/status` (RUN-API-002), route in `backend/routes/api.php` line 64, handler `RunController::status` in `backend/app/Http/Controllers/Api/V1/RunController.php` line 65. Returns status, current stage, per-stage progress and job state; reaps stale jobs before answering. Polled by the frontend every 2 s (`frontend/src/hooks/useAnalysisStatus.ts`, `POLL_MS = 2000`). |
| Existing testing documentation | `docs/TESTING_GUIDE.md` (levels 1 to 5). Its test counts (85 backend, 41 analytics, 16 frontend) are out of date; current counts are in section 5. |
| Evidence script | `analytics/scripts/output_validation.py` |

## 5. Test execution commands and results on the test date

All commands run from the repository root on the host. No container is required for any suite.

```bash
# Analyst service (49 tests, coverage)
cd analytics && .venv/bin/python -m pytest --cov=app --cov-report=term-missing

# Backend (148 tests, in-memory SQLite, FastAPI and LLM faked)
cd backend && php artisan test

# Frontend (28 tests in 14 files, MSW fakes the API)
cd frontend && npx vitest run
```

| Suite | Result | Duration |
| --- | --- | --- |
| Analyst (pytest) | 49 passed, 93% line coverage (1381 statements, 99 missed) | 1.9 s |
| Backend (PHPUnit) | 148 passed, 903 assertions | 4.2 s |
| Frontend (Vitest) | 28 passed in 14 files | 2.5 s |

Optional checks from the testing guide that were not part of this run: `vendor/bin/pint --test`, `ruff check . && ruff format --check .`, `npm run lint && npm run typecheck && npm run build`.

## 6. Available services at test time

| Service | Where | Check | Result |
| --- | --- | --- | --- |
| Backend API (dev) | http://localhost:8000/api/v1/health | GET | `status ok`, database ok, redis ok |
| Analyst API (dev) | http://127.0.0.1:8001/health | GET | `status ok`, dataspeak-analytics 1.0 |
| Frontend dev server | container `frontend` | `docker compose ps` | Up 7 days |
| Queue worker | container `backend-worker` | `docker compose ps` | Up 7 days |
| PostgreSQL 17 | container `postgres` | `docker compose ps`, `select version()` | Up 7 days (healthy) |
| Redis 7 | container `redis` | `docker compose ps`, `INFO server` | Up 7 days (healthy) |
| Mailpit | container `mailpit` | `docker compose ps` | Up 7 days (healthy) |
| Ollama (dev LLM) | host, port 11434 | process list, HTTP | **Not running** |
| Production API | https://api.dataspeaknow.com/api/v1/health | GET (read only) | HTTP 200, database ok, redis ok |
| Production frontend | https://dataspeaknow.com/ | GET (read only) | HTTP 200 |

## 7. Limitations and configuration notes

1. **Python version mismatch.** Automated tests run on Python 3.9.6 in the host venv, while the service runs on Python 3.12.15 in Docker. The container image has no pytest, so the suite cannot be run inside it without adding dev dependencies. Results are therefore evidence for the code, not for the exact production interpreter. Creating a 3.12 venv on the host would remove this gap.
2. **Ollama was not running.** Any live measurement of the development LLM path (column suggestions, narrative, translation) requires `ollama serve` on the host first. All automated suites fake the model, so they are unaffected.
3. **PHP patch level differs** between host (8.4.21) and container (8.4.26). Same major and minor version; not expected to change results.
4. **pytest-cov was installed on 2026-10-08** into the existing venv to produce coverage; it was already listed in `requirements-dev.txt`.
5. **Testing guide counts are stale** (section 4). The guide's commands are still correct.
6. **Uncommitted changes.** The evaluated working tree is ahead of commit `c4a1951`. For reproducibility the tree should be committed before performance measurements are reported.
7. **Production was not used for testing.** Only two unauthenticated health pages were read. No production data, database or configuration was accessed or changed.
8. **Hardware figures are for the development laptop.** They are not representative of the production instance (a small arm64 EC2 with about 2 GB RAM plus swap, per the deployment notes), so timing measurements taken here must not be presented as production performance.
