# Unit 7 System Discovery: Data Speak

Prepared 2026-10-15 from the repository at commit `e5ecc93` (`main`, 5 uncommitted working-tree files) and the running local development stack. This document fixes the system boundary that the Unit 7 tests exercise. It contains no secrets.

## 1. Actual implemented architecture

| Layer | Implementation found in source | Status |
| --- | --- | --- |
| Frontend | React 19.3, TypeScript 5.9, Vite 8.3, Tailwind 4, TanStack Query 5, React Router 7, Recharts 3. Ten routed pages (`src/app/routes.tsx`): login, register, verify-email, analyses list, new analysis, profile, define, analyze, report, report/claims/:claimId. API client adds Bearer token and `X-Dataspeak-Language`. | Implemented |
| Backend API | Laravel 13.34 on PHP 8.4. 28 routes under `/api/v1` (`backend/routes/api.php`): auth (register, login, logout, me, verify, resend), analyses CRUD, dataset upload and validate, profile run/show, definitions update and suggest, run, status, insights, visualizations (list, options, selection), report (generate, show, pdf, translate), claims show, health. Sanctum tokens, owner policy, rate limits, idempotency middleware on run and report. | Implemented |
| Queue jobs | `ProfileAnalysisJob`, `RunAnalysisJob`, `GenerateReportJob`, `TranslateReportJob` on Redis queues `default` and `translations`; worker container `backend-worker`. | Implemented |
| Analytics service | FastAPI on Python 3.12 (container) with Polars, NumPy, SciPy, statsmodels, scikit-learn. Endpoints `/health`, `/v1/validate`, `/v1/profile`, `/v1/analyze`, `/v1/insights`, `/v1/visualizations/recommend`; shared `X-Internal-Token`; bound to loopback 127.0.0.1:8001 locally, no published port in production. | Implemented |
| Database | PostgreSQL 17.11; 23 migrations applied (users, analyses, analysis_stages, dataset_columns, field_definitions, evidence, insights, visualizations, ai_generations, claims, claim_evidence, reports, tokens, plus incremental additions). | Implemented |
| Cache and queue | Redis 7.4 (queues, cache, sessions, idempotency keys). | Implemented |
| AI gateway | `App\Services\Ai\AiGateway` behind the `LlmClient` interface. Development driver `ollama` (Qwen `qwen3:8b`, local server running); production driver `anthropic` (Claude). Uses: column-definition suggestions, executive interpretation (claims), display-label translation, claim translation. Schema-constrained output, placeholder-only numbers, rule-based claim validation (`RuleBasedClaimValidator`). | Implemented (local tests use Ollama) |
| Reports | `ReportAssembler` builds KPI cards, executive summary, findings, caveats from SUPPORTED claims; PDF via dompdf; four languages. | Implemented |
| Email verification | Implemented; enabled locally (`REQUIRE_VERIFIED_EMAIL` default true) with Mailpit; disabled in production. | Implemented, configurable |
| CI | `.github/workflows/ci.yml`: frontend lint/typecheck/test/build, backend Pint+PHPUnit, analytics Ruff+pytest, compose config. Only run so far (2026-10-08) failed the analytics job on the frozen OpenAPI contract test (dependency drift, see Unit 6 C04). | Implemented, not green |

**Implemented workflow (verified by the Unit 6 and Unit 7 runs):** upload → validation (FastAPI) → profiling (FastAPI) + column suggestions (LLM) → user definitions → run job: deterministic analysis → insight detection and ranking → visualization recommendation → display-label translation → chart review by the user → report job: AI insight synthesis → structured claims → rule-based claim validation (with one repair round) → report assembly → optional PDF and translations. Every planned stage in the brief exists in code; the AI narrative stage is the only non-deterministic one and degrades to a deterministic report when the model is unavailable.

**Not implemented or out of scope:** no scatter chart recommendation (type exists in the enum only); no admin UI; no multi-tenant organisations; email sending in production (no SES); no automated deployment pipeline.

## 2. Running services and ports (local development stack, verified 2026-10-15 15:31)

| Service | Container | Port (host) | Version |
| --- | --- | --- | --- |
| Frontend (Vite dev server) | frontend | 127.0.0.1:5173 | Node 24.21 |
| Laravel API (PHP built-in server) | backend | 127.0.0.1:8000 | PHP 8.4.26, Laravel 13.34.0 |
| Queue worker | backend-worker | none | same image |
| FastAPI analytics | analytics | 127.0.0.1:8001 | Python 3.12.15 |
| PostgreSQL | postgres | 127.0.0.1:5432 | 17.11 |
| Redis | redis | 127.0.0.1:6379 | 7.4.11 |
| Mailpit | mailpit | 127.0.0.1:8025 | latest |
| Ollama (host process) | n/a | 127.0.0.1:11434 | model qwen3:8b |

Health at discovery: Laravel `status ok, database ok, redis ok`; FastAPI `status ok`; frontend HTTP 200; Mailpit HTTP 200.

## 3. Available testing tools

| Level | Tool | Location | Existing cases |
| --- | --- | --- | --- |
| Frontend unit and integration (mocked API) | Vitest 3.2.7, Testing Library, MSW, axe-core | `frontend/src/**/*.test.ts(x)` | 14 files, 28 tests |
| Backend feature and unit | PHPUnit 12.5 via `php artisan test` (in-memory SQLite; FastAPI and LLM faked) | `backend/tests` | 148 tests, 903 assertions |
| Analytics unit, golden and contract | pytest 8.4.2, pytest-cov 7.1 | `analytics/tests` | 49 tests (33 golden-reference assertions in 3 tests; frozen OpenAPI contract) |
| Browser automation | Playwright 1.60 (Python) with Chromium, installed in a session scratch directory | `docs/testing/unit7/scripts/system_tests.py` | new for Unit 7 |
| Performance | Python/httpx harnesses | `docs/testing/unit7/scripts/performance_tests.py`, `docs/evidence/unit6/scripts/api_latency.py` | new / Unit 6 |
| Static checks | ESLint, Prettier, Pint, Ruff | per project | |

## 4. Test fixtures and reference data

- `analytics/tests/golden/tiny.csv` (6 rows, hand-verified values) and `retail_sales.csv` (240 rows, Thai and Myanmar headers, 14.6% missing discounts, synthetic). Expected values are hand constants or Python `statistics` results, never copied from the engine.
- `frontend/public/samples/retail_sales_sample.csv` (same data) used by the "Use the sample retail dataset" button.
- `docs/contracts/fastapi-openapi.json`: frozen OpenAPI contract.
- Unit 7 additions: synthetic performance datasets of 100, 1,000 and 10,000 rows (`evidence/performance/datasets/`), and invalid inputs (`evidence/system/inputs/`: duplicate and empty headers, header-only, empty file, plain text).

## 5. Supported inputs, operations and outputs

- Inputs: CSV (UTF-8, UTF-16 or latin-1 fallback; comma, semicolon, tab or pipe) and XLSX (first non-empty sheet), up to 50 MB; one dataset per analysis.
- Validation errors stop the flow: NO_HEADER, EMPTY_COLUMN_NAME, DUPLICATE_COLUMN, NO_DATA_ROWS. Warnings continue: ENCODING_FALLBACK, DUPLICATE_ROWS, RAGGED_ROWS, HIGH_NULL_RATE, MOSTLY_EMPTY_COLUMN, CONSTANT_COLUMN.
- Analysis families: descriptive, trend, group comparison (Welch t, Mann-Whitney, ANOVA, Kruskal-Wallis), association (Spearman, chi-square with Cramer's V), quality; deterministic evidence ids.
- Claim validation: schema, unknown or missing evidence, digits outside placeholders, placeholder whitelist, language, causal wording (review), evidence quality versus claim confidence, direction contradiction; status SUPPORTED, NEEDS_REVIEW or REJECTED; only SUPPORTED claims enter the report.
- Outputs: JSON report (KPI cards, executive summary, findings, caveats, charts), PDF, translations (en, th, zh, my), claim traceability.

## 6. Required environment setup for the tests

Local Docker Compose stack (`docker compose up -d`), Ollama with `qwen3:8b` on the host for suggestion and narrative stages, the analytics virtual environment for pytest, Node modules for Vitest, PHP 8.4 with Composer dependencies for PHPUnit, and the scratch Playwright install. Test accounts are created per run with `@example.com` addresses and verified through an artisan command in the local container. Production is not used.

## 7. System-testing limitations identified before execution

1. The local stack differs from production in web server (PHP built-in vs FrankenPHP behind nginx), LLM provider (Qwen 8B vs Claude Haiku), hardware and network; timings are not production figures.
2. The LLM stages are non-deterministic; tests assert structure and validation outcomes, not exact narrative text.
3. Failure injection is limited to stopping the local analytics container; database or Redis outages are not injected.
4. Browser tests run headless Chromium at one viewport; no cross-browser or mobile coverage.
5. Performance tests are sequential and single-user; no concurrency or load testing.
6. The 10,000-row dataset is the largest exercised; the 50 MB upload limit is not approached.
7. No external participants: acceptance verification is investigator-led against the documented requirements.
