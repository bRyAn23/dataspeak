# Round 3 Environment Report

Data Speak, Unit 7 Test Round 3. Captured 2026-10-16 20:31:48 (+07); raw capture in `evidence/environment/environment_capture.log`. Round 3 ran on the same developer machine and local Docker stack as Rounds 1 and 2; production (AWS, Claude API) was not touched.

## 1. Host

| Item | Value |
| --- | --- |
| Operating system | macOS 26.6.2 (arm64), kernel 25.6.0 |
| Hardware | Apple M5 Pro, 64 GiB unified memory |
| Git | branch `main`, HEAD `e4ae051`, 10 commits ahead of `origin/main` (`c4a1951`); DEF-09 fix present in the working tree, committed at the end of the round (commit hashes in `final-test-summary.md`) |
| Node.js / npm | v24.21.0 / 11.19.0 (frontend tests, lint, build) |
| PHP (host, PHPUnit) | 8.4.21, Composer 2.9.5 |
| Python (analytics venv, pytest; harness scripts) | 3.9.6 |
| Docker / Compose | 29.8.1 / v5.5.1 |
| Ollama | 0.35.1, model `qwen3:8b` (8.2 B parameters, Q4_K_M), `OLLAMA_NUM_PARALLEL` unset (server default) |

## 2. Containers (docker compose, development stack)

| Service | Image | Version inside | State at capture |
| --- | --- | --- | --- |
| backend (Laravel API) | dataspeak-backend | PHP 8.4.26, Laravel 13.34.0 | up |
| backend-worker (default queue) | dataspeak-backend-worker | same image | up, restarted 20:11 with the DEF-09 code |
| backend-worker-translations (translations queue, DEF-04) | dataspeak-backend-worker-translations | same image | up, restarted 20:11 |
| analytics (FastAPI) | dataspeak-analytics | Python 3.12.15, fastapi 0.128.8, pydantic 2.13.5, starlette 0.49.3 (DEF-06 pins) | up |
| frontend (Vite dev server) | dataspeak-frontend | React 19 / TypeScript | up |
| postgres | postgres:17 | PostgreSQL 17.11 | up (healthy) |
| redis | redis:7-alpine | 7.4.11 | up (healthy) |
| mailpit | axllent/mailpit | n/a | up (healthy) |

Health at capture: Laravel `/api/v1/health` 200; FastAPI `/health` `{"status":"ok"}`; frontend 200; Ollama `/api/version` 0.35.1.

## 3. AI and queue configuration under test (values only)

| Setting | Value |
| --- | --- |
| `dataspeak.ai.driver` | `ollama` (coordinator therefore active, `AI_MODEL_LOCK=auto`) |
| Model, context, seed, temperature | `qwen3:8b`, 8192, 42, 0.1 (deterministic settings unchanged from Rounds 1–2) |
| `OLLAMA_TIMEOUT` | 300 s (unchanged) |
| Coordinator (DEF-09, new) | lock TTL 330 s, interactive wait 240 s, background wait 300 s, background yield 120 s, poll 250 ms, busy retry delay 60 s |
| Queue / cache / database | redis / redis / pgsql |
| PHPUnit | in-memory SQLite, array cache, sync queue, FastAPI and model faked (`backend/phpunit.xml`) |

## 4. Test data and isolation

| Dataset | Rows × columns | Bytes | SHA-256 (first 16) | Use |
| --- | --- | --- | --- | --- |
| `analytics/tests/golden/retail_sales.csv` | 240 × 7 | 11,377 | `e2b1a859c2ac0866` | golden reference, workflow and DEF-09 runs |
| `evidence/performance/datasets/perf_small_100.csv` (Round 1 folder, read-only) | 100 × 7 | 4,834 | `31aca2258c84edc9` | performance P1/P3 |
| `perf_medium_1000.csv` | 1,000 × 7 | 47,682 | `53a02ee430b56641` | performance |
| `perf_large_10000.csv` | 10,000 × 7 | 474,754 | `e890a2eaaaa26507` | performance |
| `evidence/system/inputs/` (Round 1 folder, read-only) | header-only, empty, duplicate-header and `.txt` files | | invalid-input cases (INT-05) |

All datasets are synthetic or the project's golden fixture; no personal data. Workflow tests use the synthetic accounts `unit7-a…@example.com` and `unit7-b…@example.com` in the local development database `dataspeak`; this database is a development instance, not production or staging. Automated suites use their own isolated stores (SQLite in memory, MSW in the browser tests, FastAPI TestClient).

## 5. Differences from earlier rounds that affect comparisons

- Round 1 (2026-10-15) ran commit `e5ecc93` with a single queue worker; Round 2 (2026-10-16 morning) ran `524842f` with two workers; Round 3 adds the DEF-09 coordinator. Narrative and translation timings are therefore comparable only within a round, and the DEF-09 contention scenarios have no Round 1 equivalent.
- The analytics container was rebuilt in Round 2 with pinned versions (DEF-06); the host venv (Python 3.9) used for pytest is the same in all rounds.
- Model output is non-deterministic across runs at the claim level even with a fixed seed (different prompt content per analysis), so claim counts vary between reports; deterministic statistics do not.
- Host load: Round 3 measurements ran while the developer machine was otherwise idle; the Ollama server runs natively on the host (Metal), not in Docker, so container memory figures exclude the model.
