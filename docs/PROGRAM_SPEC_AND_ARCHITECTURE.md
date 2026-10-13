# Data Speak: Program Specification, Architecture Design and Database Design

Version 1.0, 13 October 2026. Audience: capstone reviewers and developers who need to understand how Data Speak is built, project by project. Companion documents: [DEVELOPER_MANUAL.md](DEVELOPER_MANUAL.md) (program and function lists), [ARCHITECTURE_NOTES.md](ARCHITECTURE_NOTES.md) (design history), [../database/data_dictionary.md](../database/data_dictionary.md) (column-level dictionary), [DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md) (hosting).

Repository: https://github.com/bRyAn23/dataspeak.git

---

## Contents

1. [Product overview and design principles](#1-product-overview-and-design-principles)
2. [System architecture](#2-system-architecture)
3. [End-to-end workflow](#3-end-to-end-workflow)
4. [Frontend project (React + TypeScript)](#4-frontend-project-react--typescript)
5. [Backend project (PHP Laravel)](#5-backend-project-php-laravel)
6. [Analyst project (Python FastAPI)](#6-analyst-project-python-fastapi)
7. [LLM integration (Qwen via Ollama, Claude in production)](#7-llm-integration-qwen-via-ollama-claude-in-production)
8. [Database design (PostgreSQL)](#8-database-design-postgresql)
9. [Cross-cutting concerns](#9-cross-cutting-concerns)
10. [Appendix](#10-appendix)

---

## 1. Product overview and design principles

### 1.1 What Data Speak does

Data Speak turns a CSV or Excel file into a validated, executive-ready report. A business user uploads a dataset, confirms what each column means, and receives:

- a data profile (types, completeness, quality issues),
- deterministic statistics (descriptive, trend, group comparison, association, quality),
- ranked insights and recommended charts,
- an AI-written narrative in English, Thai, Chinese or Burmese, where every sentence is validated against the computed evidence,
- a PDF export and a traceability view that links each sentence to the numbers behind it.

### 1.2 Four product rules

Every design decision in this document follows four rules that were fixed at the start of the project.

| Rule | Meaning | Where it is enforced |
| --- | --- | --- |
| Software calculates | Every number comes from the analytics service (Polars, SciPy, statsmodels). The language model never performs arithmetic. | FastAPI analysis engine; evidence records |
| AI interprets | The model receives evidence and writes sentences that reference evidence by placeholder, never by digit. | Executive interpretation prompt; JSON output schema |
| Validation verifies | Each AI sentence is parsed, its placeholders resolved and checked, and only SUPPORTED claims reach the report. | Rule-based claim validator; report assembler |
| Raw customer data is transient | Uploaded rows are stored on disk only for the duration of the pipeline and never written to the database, logs or the model. | Transient upload store; persistence of aggregates only |

Two further constraints shape the architecture: the frontend talks only to the Laravel API (never to FastAPI or the model), and the data dictionary enumerations are the single source of truth for every status and type value.

### 1.3 Project inventory

| Project | Folder | Technology | Role |
| --- | --- | --- | --- |
| Frontend | `frontend/` | React 19, TypeScript, Vite, Tailwind CSS 4, TanStack Query, Recharts | User interface, polling, chart rendering |
| Backend | `backend/` | PHP 8.4, Laravel 13, Sanctum, Redis queues, dompdf | Public API, auth, lifecycle, persistence, orchestration, reports |
| Analyst | `analytics/` | Python 3.12, FastAPI, Polars, NumPy, SciPy, statsmodels | CSV validation, profiling, deterministic statistics, insights, chart specs |
| LLM integration | `backend/app/Services/Ai/` | Ollama (Qwen) in development, Claude Messages API in production | Column meaning suggestions, narrative interpretation, translations |
| Database | `database/`, `backend/database/migrations/` | PostgreSQL 17 | Derived metadata, evidence, claims, reports |
| Deployment | `docker/`, `deploy/aws/` | Docker Compose, FrankenPHP, nginx, certbot | Local stack and AWS single-instance hosting |

---

## 2. System architecture

### 2.1 Component diagram

```
+--------------------------------------------------------------------------------------+
|  Browser                                                                             |
|  React + TypeScript SPA  (dataspeaknow.com, served by nginx; Vite dev server :5173)  |
+-------------------------------------+------------------------------------------------+
                                      | HTTPS, JSON, Bearer token, X-Dataspeak-Language
                                      v
+--------------------------------------------------------------------------------------+
|  Laravel 13 API  /api/v1  (api.dataspeaknow.com, FrankenPHP behind nginx; :8000 dev) |
|  Auth (Sanctum) . Lifecycle state machine . Transient upload store . Queue jobs      |
|  Reports and PDF . Claim validation . AI gateway                                     |
+-----------+----------------------+-----------------------+---------------------------+
            |                      |                       |
            | SQL                  | queues, progress,     | X-Internal-Token
            v                      | cache                 v
+-----------------------+   +------v--------+   +----------------------------------------+
|  PostgreSQL 17        |   |  Redis 7      |   |  FastAPI analytics service (internal)  |
|  metadata, evidence,  |   |  default and  |   |  /v1/validate  /v1/profile             |
|  insights, charts,    |   |  translations |   |  /v1/analyze   /v1/insights            |
|  claims, reports      |   |  queues       |   |  /v1/visualizations/recommend          |
+-----------------------+   +---------------+   |  Polars . SciPy . statsmodels          |
                                                +----------------------------------------+
            |
            | HTTPS (evidence and labels only, never rows)
            v
+--------------------------------------------------------------------------------------+
|  LLM provider behind the LlmClient interface                                         |
|  development: Ollama  POST /api/chat  (qwen3:8b, JSON schema via `format`)           |
|  production:  Claude Messages API  (forced tool call carrying the JSON schema)       |
+--------------------------------------------------------------------------------------+
```

### 2.2 Communication contracts

| Link | Protocol | Authentication | Payload rule |
| --- | --- | --- | --- |
| Browser to Laravel | HTTPS JSON, multipart for upload | Sanctum bearer token | Never contains dataset rows after upload; responses carry aggregates and labels only |
| Laravel to FastAPI | HTTP multipart (file) or JSON | Shared secret in `X-Internal-Token` | File is streamed from the transient store; metadata JSON carries analysis id, definitions, options |
| Laravel to LLM | HTTPS JSON | API key (Claude) or none (local Ollama) | Evidence ids, display values, labels, headers. No rows, no raw values beyond aggregates |
| Laravel to PostgreSQL | SQL | DB credentials, TLS on RDS | Derived metadata only |
| Laravel to Redis | RESP | Optional password | Job payloads carry analysis ids only; progress counters; idempotency keys |

### 2.3 Deployment topologies

**Local development** (`docker-compose.yml`): frontend, backend, backend-worker, analytics, postgres, redis, mailpit and ollama containers. Only the frontend and backend ports are reachable from the host; FastAPI, Redis and PostgreSQL bind to loopback.

**Production** (`deploy/aws/`): one EC2 instance (arm64) runs nginx, the Laravel app image (FrankenPHP), a queue worker, a scheduler, the analytics image and Redis. PostgreSQL is Amazon RDS. The frontend is a static build served by nginx on `dataspeaknow.com`; the API is on `api.dataspeaknow.com`. Both hosts use Let's Encrypt certificates. The model is Claude via the Anthropic API, so no GPU host is needed.

### 2.4 Architectural style

- **Layered monorepo.** Each project has its own toolchain and tests; they integrate only through documented HTTP contracts.
- **Orchestration in Laravel.** Laravel owns the analysis lifecycle, calls FastAPI and the model, and persists results. FastAPI is stateless and keeps nothing between requests.
- **Queue-driven pipeline.** Long work (profiling, analysis, report generation, translation) runs in queued jobs; the browser polls a status endpoint.
- **Evidence-centred data model.** Insights, charts, claims and reports all reference evidence records by a deterministic key, which makes every statement traceable.
- **Pluggable model provider.** The `LlmClient` interface hides the provider; switching from Qwen to Claude is an environment variable.

---

## 3. End-to-end workflow

### 3.1 Pipeline stages

| # | Stage | Who runs it | Input | Output | Persisted as |
| --- | --- | --- | --- | --- | --- |
| 1 | UPLOAD | Laravel | CSV or XLSX (converted to CSV) | Transient file, SHA-256, size | `analyses` metadata |
| 2 | VALIDATION | FastAPI via Laravel | Transient file | Valid flag, errors, warnings, row and column counts | `analyses.row_count`, `column_count` |
| 3 | PROFILING | FastAPI via `ProfileAnalysisJob` | Transient file | Column types, null rates, stats, quality findings | `dataset_columns` |
| 4 | DEFINITION | LLM via `ProfileAnalysisJob`, then the user | Column headers and profile stats | Suggested business name, semantic type, unit, aggregation, reason; user edits | `field_definitions` |
| 5 | STATISTICAL_ANALYSIS | FastAPI via `RunAnalysisJob` | File and definitions | Evidence records | `evidence` |
| 6 | INSIGHT_DETECTION | FastAPI via `RunAnalysisJob` | Evidence | Ranked insights | `insights` |
| 7 | VISUALIZATION | FastAPI via `RunAnalysisJob`, then the user | Evidence and insights | Chart specs; user picks charts and theme | `visualizations`, `analyses.chart_theme` |
| 8 | AI_SYNTHESIS | LLM via `GenerateReportJob` | Evidence payload with placeholders | Structured claims | `ai_generations` |
| 9 | CLAIM_VALIDATION | Laravel | Claims and evidence | SUPPORTED / REJECTED status per claim | `claims`, `claim_evidence` |
| 10 | REPORTING | Laravel | Supported claims, KPIs, charts | Report sections, data notes; PDF on demand | `reports` |
| 11 | COMPLETE | Laravel | | Transient file deleted | `analyses.csv_purged_at` |

### 3.2 Analysis state machine

```
CREATED -> UPLOADED -> VALIDATED -> PROFILED -> DEFINED -> ANALYZING -> ANALYZED -> REPORTING -> COMPLETED
                ^           ^           ^          ^                        |            |
                |           |           |          |                        |            |
                +-----------+-----------+----------+---- re-upload / redefine / re-run ---+
   any processing failure -> FAILED (retry returns to the last stable state)
```

Transitions are whitelisted in `AnalysisLifecycle` and every change writes an `analysis_stages` row with attempt number, progress, duration and a safe error code. Status polling (RUN-API-002) reads these rows, so the browser shows real progress rather than a spinner.

### 3.3 Browser journey

1. Register or log in (email verification can be switched off for MVP).
2. **New analysis**: name it, drop a file, see validation results.
3. **Profile**: review types, completeness, quality issues, and the suggested meanings with reasons.
4. **Define**: edit business names, semantic types, units and aggregations; validation blocks inconsistent choices.
5. **Analyze**: start the run; a stepper shows profiling, analysis, insights, charts, narrative and validation progress.
6. **Choose charts**: two tabs (recommended charts, custom required charts), colour theme, reasons per chart.
7. **Report**: KPI cards, executive summary, findings, caveats, charts; switch language; export PDF; click any sentence to open its evidence.

---

## 4. Frontend project (React + TypeScript)

### 4.1 Purpose and boundaries

The frontend is a single-page application that renders what the API returns. It performs no statistics and never contacts FastAPI or the model. Query data is kept in memory only; the browser keeps the auth token in sessionStorage (cleared when the tab closes) and the chosen language in localStorage.

### 4.2 Technology stack

| Concern | Choice | Notes |
| --- | --- | --- |
| UI | React 19, TypeScript strict | Function components and hooks |
| Build | Vite 6, `@vitejs/plugin-react` | `VITE_API_BASE_URL` is the only build-time setting |
| Styling | Tailwind CSS 4, `lucide-react` icons | App shell pinned with `fixed inset-0` so pages never leave blank space below |
| Data fetching | TanStack Query 5 | Retries skipped for 401/403/404/409/422; `staleTime` 10 s; no persisted cache |
| Routing | React Router 7 | Guards for auth and verification |
| Charts | Recharts 3 | Renders chart specs from the API; six colour themes |
| Tests | Vitest, Testing Library, MSW, axe-core | 14 test files, mocked API |
| Lint | ESLint 10, Prettier | |

### 4.3 Source layout

```
frontend/src
  app/            App.tsx, routes.tsx, guards.tsx (RequireAuth, RequireVerified, RedirectIfAuthenticated)
  pages/          one component per route (FE-PG-001 .. FE-PG-010)
  components/     upload/ profile/ define/ analyze/ report/ layout/
  hooks/          useAuth, useAnalyses (query and mutation hooks), useAnalysisStatus (polling)
  services/       apiClient (fetch wrapper), auth/analysis/dataset/profile/definition/report services
  i18n/           core (t, currentLocale, activateLocale), I18nProvider, th.ts, zh.ts, my.ts dictionaries
  types/          api, analysis, dataset, report types mirrored from the API resources
  utils/          chartThemes, evidenceWording, format, naming, profileSummary, quality, statusLabels, steps
  config/         env.ts, queryClient.ts
  test/           msw handlers and test setup
```

### 4.4 Routes and pages

| ID | Path | Page | Responsibility |
| --- | --- | --- | --- |
| FE-PG-001 | `/login` | LoginPage | Email and password login |
| FE-PG-002 | `/register` | RegisterPage | Account creation; honours `verification_required` |
| FE-PG-010 | `/verify-email` | VerifyEmailPage | Resend and confirm verification |
| FE-PG-003 | `/analyses` | AnalysisDashboardPage | Paginated list, status badges, delete |
| FE-PG-004 | `/analyses/new` | NewAnalysisPage | Name, dropzone, sample dataset, privacy notice, validation summary |
| FE-PG-005 | `/analyses/:id/profile` | DataProfilePage | Overview cards, column table with suggested meaning and reason, quality list |
| FE-PG-006 | `/analyses/:id/define` | DataDefinitionPage | Field definition table, semantic type select, validation, save |
| FE-PG-007 | `/analyses/:id/analyze` | AnalysisRunPage | Start action, progress stepper, failure panel, chart review, completion |
| FE-PG-008 | `/analyses/:id/report` | ReportPage | KPI cards, executive summary, findings, charts, export, language |
| FE-PG-009 | `/analyses/:id/report/claims/:claimId` | ReportPage (traceability panel) | Claim statement, status, supporting evidence |

A `useAnalysisGuard` hook redirects the user to the correct step for the analysis status, so deep links to a stage that is not yet reachable fall back to the right page.

### 4.5 Component groups

| Group | Components | What they do |
| --- | --- | --- |
| upload | AnalysisNameField, CsvDropzone, SampleDatasetAction, PrivacyNotice, UploadValidationSummary | Collect the file, show validation errors and warnings in plain language |
| profile | DatasetOverviewCards, ColumnProfileTable, QualityIssueList, ProfileContinueAction | Present counts, completeness, per-column types, nulls, stats, and the suggestion reason |
| define | FieldDefinitionTable, SemanticTypeSelect, DefinitionValidation, DefinitionSaveAction | Edit definitions; block saving when a measure lacks an aggregation or a unit is inconsistent |
| analyze | AnalysisStartAction, AnalysisStatusPoller, AnalysisProgress, AnalysisFailurePanel, ChartReviewPanel, CompletionCelebration | Run the pipeline, show stage progress with model-stage hints, let the user choose charts and theme |
| report | SummaryMetricCard, ExecutiveSummary, ChartRenderer, ChartSummaryCard, ClaimLink, TraceabilityPanel, ExportPdfAction | Render the report; each sentence links to its claim; PDF download follows the interface language |
| layout | AppShell, Sidebar, PageHeader, AnalysisStepper, LanguageSwitcher, StatusBadge, Button, ConfirmDialog, EmptyState, ErrorState, LoadingState | Shell, navigation, shared states |

### 4.6 Services and API client

`services/apiClient.ts` wraps `fetch`:

- prefixes `env.apiBaseUrl`, sets `Accept: application/json`, `Authorization: Bearer <token>` and `X-Dataspeak-Language: <locale>` on every call;
- `request<T>()` for JSON, `download()` for the PDF blob with the server's file name;
- parses the API error envelope into a typed error with `status`, `code` and `message`; `describeError()` maps it to user text;
- a registered unauthorized handler clears the session and routes to `/login` on 401.

Each feature service (`authService`, `analysisService`, `datasetService`, `profileService`, `definitionService`, `reportService`) is a thin typed map from a function to an API route, so pages never build URLs.

### 4.7 State, polling and caching

- Auth state lives in `AuthProvider` (token mirrored to `sessionStorage` so a refresh keeps the session, user in memory).
- Server state lives in TanStack Query with a key factory in `useAnalyses.ts`; mutations invalidate the analysis keys.
- `useAnalysisStatus` polls RUN-API-002 every 2 seconds until the status is terminal (`isStopped`), and stops when the tab is hidden.
- The report page polls the translation endpoint until the narrative is complete in the chosen language (up to 90 attempts).

### 4.8 Internationalisation

Interface languages: English (source strings), Thai, Chinese, Burmese. `t(text, vars)` looks up the English string in the active dictionary (463 entries per language) and falls back to English. Changing the language updates the `X-Dataspeak-Language` header, so labels, chart titles and narrative sentences arrive translated from the API, and the PDF is produced in the same language.

### 4.9 Error handling and accessibility

- Every page has loading, empty and error states; API error codes map to translated messages.
- Destructive actions (delete analysis) use `ConfirmDialog`.
- Tests include axe-core checks on key pages; interactive controls have labels; status is conveyed by text as well as colour.

### 4.10 Build and test

```bash
cd frontend
npm ci
npm run dev          # Vite on :5173, proxies nothing: calls VITE_API_BASE_URL directly
npm run test         # Vitest + MSW
npm run build        # dist/ for nginx or Amplify
```

---

## 5. Backend project (PHP Laravel)

### 5.1 Purpose and boundaries

Laravel is the only public API and the system of record. It authenticates users, enforces the analysis lifecycle, stores uploaded files transiently, calls FastAPI and the model, validates AI claims, assembles reports and renders PDFs. It never stores or logs dataset rows.

### 5.2 Technology stack

| Concern | Choice |
| --- | --- |
| Runtime | PHP 8.4, Laravel 13, FrankenPHP in production |
| Auth | Laravel Sanctum personal access tokens |
| Queue and cache | Redis (predis); queues `default` and `translations` |
| Database | PostgreSQL via PDO; `DB_SSLMODE` for RDS |
| Spreadsheet | openspout (XLSX to CSV, streaming) |
| PDF | barryvdh/laravel-dompdf with inline SVG charts |
| Quality | PHPUnit (148 tests), Pint |

### 5.3 Source layout

```
backend/app
  Http/Controllers/Api/V1/   Auth, EmailVerification, Analysis, Dataset, Profile, Definition,
                             DefinitionSuggestion, Run, Insight, Visualization, Report, ReportPdf,
                             ReportTranslation, Claim, Health
  Http/Middleware/           ForceJsonResponse, SetLocale, IdempotencyKey, EnsureEmailIsVerifiedJson
  Http/Requests, Resources   validation and response shaping
  Jobs/                      ProfileAnalysisJob, RunAnalysisJob, GenerateReportJob, TranslateReportJob
  Models/                    User, Analysis, AnalysisStageRecord, DatasetColumn, FieldDefinition,
                             Evidence, Insight, Visualization, AiGeneration, Claim, Report
  Enums/                     one enum per data dictionary enumeration
  Services/
    Lifecycle/               AnalysisLifecycle (state machine), StaleJobReaper
    Uploads/                 TransientUploadStore, SpreadsheetConverter
    Analytics/               AnalyticsServiceClient, ProfilePersister, AnalysisResultPersister,
                             DefinitionPayload, ProgressStore
    Ai/                      AiGateway, ColumnDefinitionSuggester, DisplayLabelTranslator,
                             ClaimTranslator, EvidencePayloadBuilder, EvidenceSentences,
                             PlaceholderResolver, Prompts/, Schemas/, Clients/, Contracts/
    Validation/              RuleBasedClaimValidator, ValidationResult, Contracts/ClaimValidator
    Reports/                 ReportAssembler, ChartCatalog, ChartGrounding, ChartReasons, ChartTitles,
                             DisplayLabels, Pdf/ (ReportPdfBuilder, ChartSvg, ChartHtml, PdfWording, ScriptSpans)
    Reporting/               NarrativeRenderer
    AnalysisDeletionService
```

### 5.4 Public API specification

All routes are under `/api/v1`, return JSON, and use the error envelope `{ "error": { "code", "message", "details" } }`. Authenticated routes require `Authorization: Bearer <token>`. `X-Dataspeak-Language` selects `en`, `th`, `zh` or `my`.

| ID | Method and path | Auth | Throttle | Purpose |
| --- | --- | --- | --- | --- |
| | GET `/health` | none | | Service, database and Redis status |
| AUTH-API-001 | POST `/auth/register` | none | auth 5/min | Create account; returns token and `verification_required` |
| AUTH-API-002 | POST `/auth/login` | none | auth 5/min | Issue token |
| AUTH-API-003 | POST `/auth/logout` | token | | Revoke token |
| AUTH-API-004 | GET `/me` | token | | Current user |
| | GET `/auth/verify-email/{id}/{hash}` | signed URL | | Confirm email |
| | POST `/auth/email/resend` | token | verification 3/min | Resend verification mail |
| ANL-API-001 | GET `/analyses` | token, verified | api 120/min | Paginated list |
| ANL-API-002 | POST `/analyses` | token, verified | | Create analysis (name) |
| ANL-API-003 | GET `/analyses/{id}` | owner | | Analysis with status, stage, counts |
| ANL-API-004 | DELETE `/analyses/{id}` | owner | | Delete analysis and transient file |
| DATA-API-001 | POST `/analyses/{id}/dataset` | owner | upload 10/min | Upload CSV or XLSX (multipart) |
| DATA-API-002 | POST `/analyses/{id}/validate` | owner | | Validate via FastAPI; 422 `CSV_VALIDATION_FAILED` on errors |
| PROF-API-001 | POST `/analyses/{id}/profile` | owner | | Queue profiling and definition suggestions |
| PROF-API-002 | GET `/analyses/{id}/profile` | owner | | Dataset summary, columns, quality findings, suggestions |
| DEF-API-001 | PUT `/analyses/{id}/definitions` | owner | | Save field definitions |
| DEF-API-002 | POST `/analyses/{id}/definitions/suggest-names` | owner | ai-suggest 10/min | Re-run model suggestions |
| RUN-API-001 | POST `/analyses/{id}/run` | owner | run 6/min, idempotent | Queue the analysis pipeline |
| RUN-API-002 | GET `/analyses/{id}/status` | owner | | Status, stages, progress, job state |
| INS-API-001 | GET `/analyses/{id}/insights` | owner | | Ranked insights |
| VIS-API-001 | GET `/analyses/{id}/visualizations` | owner | | Chart specs with reasons |
| VIS-API-002 | GET `/analyses/{id}/visualizations/options` | owner | | Catalogue of custom charts the data supports |
| VIS-API-003 | PUT `/analyses/{id}/visualizations/selection` | owner | | Selected charts, custom charts, theme |
| RPT-API-001 | POST `/analyses/{id}/report` | owner | report 6/min, idempotent | Queue report generation |
| RPT-API-002 | GET `/analyses/{id}/report` | owner | | Report in the requested language |
| RPT-API-003 | GET `/analyses/{id}/report/pdf` | owner | report 6/min | PDF in the requested language |
| RPT-API-004 | POST `/analyses/{id}/report/translate` | owner | ai-suggest 10/min | Translate narrative on demand; returns progress |
| TRC-API-001 | GET `/analyses/{id}/claims/{claimId}` | owner | | Claim with evidence for traceability |

Authorization: an `AnalysisPolicy` restricts every `{id}` route to the owning user. Email verification is enforced by `verified.api` only when `REQUIRE_VERIFIED_EMAIL=true`.

### 5.5 Middleware and request handling

| Middleware | Effect |
| --- | --- |
| ForceJsonResponse | Every response is JSON, including framework errors |
| SetLocale | Reads `X-Dataspeak-Language`, sets the app locale, adds `Vary` and `Content-Language` headers so caches keep languages apart |
| IdempotencyKey | On run and report, repeated requests with the same key return the first result instead of queuing twice |
| EnsureEmailIsVerifiedJson | JSON 403 with `EMAIL_NOT_VERIFIED` when verification is required and missing |
| throttle:* | Per-user or per-IP limits listed in the API table |
| TrustProxies | Honours `X-Forwarded-*` from nginx (`TRUSTED_PROXIES`) |

### 5.6 Controllers, services and jobs

**Upload and validation.** `DatasetController@upload` stores the file through `TransientUploadStore` (disk `transient`, path `analyses/{public_id}/dataset.csv`, SHA-256 and size recorded, no cell read). XLSX files are converted with `SpreadsheetConverter` (first non-empty sheet, header-width trimming). `@validate` streams the file to FastAPI and persists row and column counts.

**Profiling and suggestions.** `ProfileAnalysisJob` runs stage PROFILING (FastAPI `/v1/profile`, `ProfilePersister` writes `dataset_columns`) and stage DEFINITION (`ColumnDefinitionSuggester` writes `field_definitions` with `is_suggested=true` and a `suggestion_reason`).

**Run pipeline.** `RunAnalysisJob` executes `/v1/analyze`, `/v1/insights`, `/v1/visualizations/recommend` in order; `AnalysisResultPersister` writes evidence, insights and visualizations in one transaction; `DisplayLabelTranslator` adds English (and report-language) display labels for non-Latin headers and category values; when `AUTO_REPORT` is on the job dispatches `GenerateReportJob` immediately. The transient file is deleted when the run completes.

**Report pipeline.** `GenerateReportJob` runs AI_SYNTHESIS (`AiGateway::interpret`), CLAIM_VALIDATION (`RuleBasedClaimValidator`, with one repair round through `AiGateway::repair` for claims that contain literal numbers), and REPORTING (`ReportAssembler` places supported claims into executive summary, findings, metrics and caveats, grounded on charts by `ChartGrounding`). If the model is unavailable the report is still assembled from deterministic content and `ai_status` tells the UI why there is no narrative.

**Translation.** `TranslateReportJob` (queue `translations`, unique until processing, three attempts with back-off) pre-translates claim statements into the other languages; `ReportTranslationController` performs the same on demand with a strict second pass for sentences the model merged or altered.

**Charts.** `ChartCatalog` lists custom charts the definitions allow (totals only for additive measures), `ChartReasons` explains each recommendation in the user's language, `ChartThemes` holds the six palettes, and `ChartSvg` renders the same specs for the PDF.

**Housekeeping.** `StaleJobReaper` (scheduler) fails jobs that exceed `AI_JOB_TIMEOUT_SECONDS`; `TransientUploadStore::purgeExpired` removes files older than `UPLOAD_TTL_MINUTES`; `AnalysisDeletionService` deletes an analysis, its rows and its file.

### 5.7 Analytics client contract

`AnalyticsServiceClient` sends `X-Internal-Token` on every call, a 30 second default timeout, and maps transport failures to `ANALYTICS_UNAVAILABLE` and FastAPI error bodies to the same codes. File calls attach the transient file as `dataset.csv` with a `metadata` JSON field; JSON calls send evidence and definitions. Empty PHP arrays are restored to JSON objects before re-sending evidence so the strict Pydantic models accept them.

### 5.8 Configuration

| Variable | Purpose |
| --- | --- |
| `APP_KEY`, `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `TRUSTED_PROXIES` | Framework, CORS and proxy |
| `DB_*`, `DB_SSLMODE`, `REDIS_*`, `QUEUE_CONNECTION=redis` | Storage |
| `ANALYTICS_BASE_URL`, `ANALYTICS_INTERNAL_TOKEN`, `ANALYTICS_TIMEOUT_SECONDS` | FastAPI link |
| `AI_ENABLED`, `AI_LLM_DRIVER` (`ollama` or `anthropic`), `AI_PROMPT_VERSION`, `AI_JOB_TIMEOUT_SECONDS`, `AI_CLAIM_VALIDATOR` | AI gateway |
| `OLLAMA_BASE_URL`, `OLLAMA_MODEL`, `OLLAMA_TIMEOUT`, `OLLAMA_TEMPERATURE`, `OLLAMA_SEED`, `OLLAMA_NUM_CTX`, `OLLAMA_THINK` | Qwen via Ollama |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `ANTHROPIC_TIMEOUT`, `ANTHROPIC_MAX_TOKENS`, `ANTHROPIC_CONTEXT_TOKENS` | Claude |
| `AUTO_REPORT`, `PRETRANSLATE_REPORTS`, `UPLOAD_TTL_MINUTES`, `UPLOAD_MAX_MB`, `REQUIRE_VERIFIED_EMAIL` | Product behaviour |
| `MAIL_*` | Verification mail (Mailpit locally, SES planned) |

### 5.9 Tests

PHPUnit feature tests cover every route group (auth, upload, validation, profile, definitions, suggestions, run, status, insights, visualizations, chart selection, report, PDF, translation, traceability, email verification, deletion) with a `FakeLlmClient` and a fake analytics client, plus unit tests for the lifecycle, validator, placeholder resolver, payload builder and the Anthropic client. Run with `php artisan test`.

---

## 6. Analyst project (Python FastAPI)

### 6.1 Purpose and boundaries

The analytics service is the calculation engine. It is stateless, internal only, and deterministic: the same file and definitions always produce the same evidence ids and values. It reads the uploaded file from a spooled temporary path, computes, responds, and deletes the file.

### 6.2 Technology stack

| Concern | Choice |
| --- | --- |
| Framework | FastAPI, Pydantic v2 strict models, uvicorn |
| Data | Polars for parsing and aggregation, NumPy |
| Statistics | SciPy (t-test, Welch, Mann-Whitney, Kruskal-Wallis, ANOVA, Levene, Spearman, chi-square), statsmodels (trend slope) |
| Config | pydantic-settings from environment |
| Quality | pytest (49 tests, 93% line coverage), ruff, frozen OpenAPI contract |

### 6.3 Source layout

```
analytics/app
  main.py                 create_app(): routers, error handlers, logging
  api/v1/                 validate.py, profile.py, analyze.py, insights.py, visualizations.py, deps.py, health
  core/                   config.py (Settings), security.py (X-Internal-Token), errors.py, logging.py
  schemas/                common.py (FieldDefinition, Evidence, Quality, Finding), requests.py, responses.py
  services/
    validation/           csv_reader.py, validator.py
    profiling/            inference.py, profiler.py
    analysis/             engine.py, roles.py, rules.py, descriptive.py, trend.py, groups.py,
                          association.py, quality.py, evidence.py
    insights/             detector.py, wording.py
    visualization/        recommender.py
  utils/tempfiles.py
analytics/tests           unit/, contract/, golden/ (tiny.csv, retail_sales.csv)
analytics/scripts         export_openapi.py, output_validation.py
```

### 6.4 API specification

All endpoints require `X-Internal-Token` (constant-time comparison) except `/health`. File endpoints accept multipart with `file` and a `metadata` JSON string parsed into `DatasetMetadata { analysis_id, definitions[], options{seed, max_insights} }`. Uploads are spooled to disk with a size limit (`MAX_UPLOAD_MB`, 413 when exceeded) and removed after the request.

| ID | Endpoint | Request | Response |
| --- | --- | --- | --- |
| PY-API-001 | GET `/health` | | service, version, status |
| PY-API-003 | POST `/v1/validate` | file + metadata | `ValidateResponse { valid, row_count, column_count, columns[], warnings[], errors[] }` |
| PY-API-002 | POST `/v1/profile` | file + metadata | `ProfileResponse { dataset{rows, columns, completeness, duplicate_rows}, columns[ColumnProfile], quality_findings[] }` |
| PY-API-004 | POST `/v1/analyze` | file + metadata (definitions, options) | `AnalyzeResponse { evidence[Evidence], summary{evidence_count, families_run, skipped[]} }` |
| PY-API-005 | POST `/v1/insights` | JSON `{ analysis_id, evidence[], definitions[], options }` | `InsightsResponse { insights[Insight] }` |
| PY-API-006 | POST `/v1/visualizations/recommend` | JSON `{ analysis_id, evidence[], insights[], definitions[] }` | `VisualizationResponse { charts[ChartSpec] }` |

Errors use `{ error: { code, message, details[] } }` with codes such as `ANALYTICS_INVALID_DATASET`, `ANALYTICS_INSUFFICIENT_DATA`, `ANALYTICS_UNAUTHORIZED`, `ANALYTICS_PAYLOAD_TOO_LARGE`.

### 6.5 CSV reading and validation

`csv_reader.read_csv_bytes` decodes UTF-8 (with or without BOM), UTF-16, then latin-1 as a fallback; sniffs the delimiter among comma, semicolon, tab and pipe; treats `NA`, `null`, `-`, empty and similar tokens as nulls; drops rows that cannot be aligned to the header and counts them. `validator.validate_dataset` returns:

| Kind | Code | Condition |
| --- | --- | --- |
| error | `NO_HEADER` | no columns |
| error | `EMPTY_COLUMN_NAME` | blank header cells |
| error | `DUPLICATE_COLUMN` | repeated header |
| error | `NO_DATA_ROWS` | header only |
| warning | `ENCODING_FALLBACK` | latin-1 used |
| warning | `DUPLICATE_ROWS` | identical rows present |
| warning | `RAGGED_ROWS` | rows dropped for width mismatch |
| warning | `MOSTLY_EMPTY_COLUMN` / `HIGH_NULL_RATE` | null rate at or above 90% / 50% |
| warning | `CONSTANT_COLUMN` | a single distinct value |

Any error stops the pipeline at validation; warnings are shown and processing continues.

### 6.6 Type inference and profiling

`inference.infer_column` classifies each column as `integer`, `numeric`, `boolean`, `date`, `datetime`, `category`, `identifier`, `text` or `unknown` using thresholds from `rules.py` (95% parse share for numeric and date, category when at most 20 distinct values that repeat or at most 5% distinct ratio up to 100, identifier when at least 95% unique over 20 or more rows). Thousands separators and currency symbols are stripped before numeric parsing; several date formats are tried.

`profiler.profile_frame` returns dataset totals (rows, columns, completeness, duplicate rows), per-column null count and rate, distinct count, and derived stats (min, max, mean, median, standard deviation, percentiles for numbers; range for dates; top share for categories). Values of text and identifier columns are never returned. Quality findings: `MOSTLY_EMPTY_COLUMN`, `HIGH_NULL_RATE`, `CONSTANT_COLUMN`, `MIXED_TYPES`, `DUPLICATE_ROWS`.

### 6.7 Roles and analysis families

`roles.resolve_roles` combines inferred types with the user's definitions (definitions win): measures (NUMBER, CURRENCY, PERCENTAGE), dimensions (CATEGORY, BOOLEAN), time (DATE), excluded (TEXT, IDENTIFIER, IGNORE). At most 8 measures and 5 dimensions are analysed.

`engine.analyze_frame` runs each family only when its precondition holds and records skips with a reason:

| Family | Precondition | Methods | Evidence metrics (examples) |
| --- | --- | --- | --- |
| descriptive | at least 1 row | count, sum, mean, median, std, min, max, histogram (10 bins), IQR outliers | `row_count`, `sum`, `mean`, `median`, `std`, `outlier_count` |
| trend | a date column and a measure | granularity choice (day to year, 6 to 60 periods), period series, average growth, total change, latest change, slope | `period_series`, `average_period_growth`, `total_change`, `latest_period_change` |
| group_comparison | a dimension and a measure | group aggregates (2 to 20 groups, min 5 rows each), test chosen by sample size and Levene: Welch t, Mann-Whitney, ANOVA, Kruskal-Wallis; Cohen's d | `group_aggregates`, `top_group_share`, `top_bottom_mean_difference`, `group_difference_test` |
| association | two measures or two dimensions | Spearman correlation (30+ pairs), chi-square with Cramer's V (expected counts check) | `spearman_correlation`, `cramers_v` |
| quality | any rows | completeness, duplicates, null rates, constant columns, type parse failures | `completeness`, `duplicate_rows`, `null_rate`, `type_parse_failures` |

A fixed seed is set even though no step is stochastic, and results are de-duplicated by evidence id.

### 6.8 Evidence model

Every result is an `Evidence` record:

```
evidence_id      ev_ + 12 hex chars, SHA-256 of (metric, method, sorted source columns, parameters)
analysis_family  descriptive | trend | group_comparison | association | statistical_test | quality
metric, method   e.g. average_period_growth / period_over_period_growth
value            scalar or null; display_value formatted with unit or percent
source_columns, metric_column, dimension_column
parameters       inputs needed to reproduce the number
quality          { status: acceptable | warning | insufficient, notes[] }
payload          aggregates only: group rows, period points, test statistics
```

Because the id is deterministic, re-running an analysis yields the same ids, and claims and charts can reference evidence stably across runs.

### 6.9 Insight detection and ranking

`insights.detector.detect` turns evidence into candidate insights of six types: `TREND_CHANGE`, `GROUP_DIFFERENCE`, `CONCENTRATION`, `ASSOCIATION`, `DISTRIBUTION`, `DATA_QUALITY`. Thresholds come from `rules.py` (growth at least 5% per period, latest change 10%, top share 40%, group difference 20% with p at most 0.05, correlation at least 0.5, Cramer's V at least 0.3, missing rate 10%). Each candidate is scored `0.5 * magnitude + 0.3 * support + 0.2 * quality`, ranked, and capped at `max_insights` (default 10). Headlines are produced by `wording.py` from templates that avoid causal words; a test asserts that no causal vocabulary appears.

### 6.10 Visualization recommendation

`visualization.recommender.recommend` maps insights to chart specs: line for trends, bar for group differences and concentrations, histogram for distributions, table for quality or when a chart would mislead. Each `ChartSpec` carries `type`, `title`, `x` and `y` axes with labels and units, `unit_missing` when the definition had no unit, `series` of aggregated points, `evidence_ids`, and `insight_rank`. The frontend renders the spec; the backend renders the same spec as SVG for the PDF. Scatter and pie exist in the chart type enumeration; pie is added by users at chart review for additive measures.

### 6.11 Tests and contract

- Unit tests: CSV reader, validator, inference and profiler, engine golden values (hand-verified `tiny.csv`, and `retail_sales.csv` cross-checked against Python's `statistics` module), determinism, insights wording, visualizations, endpoints, security, logging, and the output-validation scenarios.
- Contract test: the OpenAPI schema is frozen in `docs/contracts/fastapi-openapi.json`; any change must be exported deliberately with `scripts/export_openapi.py`.
- `scripts/output_validation.py` prints validation status, totals, missing values, duplicate rows and numeric columns for sample files as execution evidence.

```bash
cd analytics
.venv/bin/python -m pytest --cov=app --cov-report=term-missing
```

---

## 7. LLM integration (Qwen via Ollama, Claude in production)

### 7.1 Role of the model

The model is used in four places, always with structured input and schema-constrained output, and never with dataset rows.

| Use | Service | Stage | Input | Output |
| --- | --- | --- | --- | --- |
| Column meaning suggestions | `ColumnDefinitionSuggester` | DEFINITION | header names and profile stats, in batches of 12 | business name, semantic type, unit, aggregation, one-sentence reason |
| Narrative interpretation | `AiGateway` with `ExecutiveInterpretationPrompt` | AI_SYNTHESIS | evidence payload with ready-made sentences and placeholders | claims (EXECUTIVE_SUMMARY, FINDING, CAVEAT) referencing evidence ids |
| Display labels | `DisplayLabelTranslator` | after run, and per language | non-Latin headers and category values | short Latin or target-language labels |
| Narrative translation | `ClaimTranslator`, `TranslateReportJob` | after report | claim templates with placeholders | translated templates with identical placeholders |

### 7.2 Provider abstraction

```php
interface LlmClient {
    public function chat(array $messages, array $schema, array $options = []): LlmResult;
    public function chatMany(array $requests): array;   // concurrent batch, same shape per item
}
```

| Driver | Class | Transport | Schema enforcement | Concurrency |
| --- | --- | --- | --- | --- |
| `ollama` (development) | `OllamaClient` | `POST {OLLAMA_BASE_URL}/api/chat`, `stream:false` | Ollama `format` parameter carries the JSON schema | sequential |
| `anthropic` (production) | `AnthropicClient` | Claude Messages API with `x-api-key` and `anthropic-version` | a single tool named `emit` whose `input_schema` is the JSON schema, with `tool_choice` forcing it | `Http::pool` for `chatMany` |

Both return `LlmResult { content (decoded JSON), model, input_tokens, output_tokens, duration_ms, truncated }` and raise `LlmUnavailableException` with `AI_UNAVAILABLE`, `AI_TIMEOUT` or `AI_HTTP_ERROR`. `AI_LLM_DRIVER` selects the driver; tests use `FakeLlmClient`.

Qwen set-up (development): `ollama pull qwen3:8b` (or a smaller `qwen3:4b`), `OLLAMA_BASE_URL=http://ollama:11434` inside Docker, temperature 0.1, seed 42, context 8192, thinking disabled. Production uses `ANTHROPIC_MODEL=claude-haiku-4-5-20251001` with a 150 000 token context budget; Ollama is not installed on the server and there is no fallback between providers.

### 7.3 Executive interpretation design

**Payload** (`EvidencePayloadBuilder`): dataset summary, ranked insights, chart list, summary metrics, quality evidence and up to 12 context metrics, each evidence item presented as a view of allowed fields (`display_value`, `label`, `dimension`, `group`, `period_from`, `period_to`, `unit`, and so on) plus a ready-made sentence from `EvidenceSentences`. The builder estimates tokens (characters divided by four) and drops lowest-ranked context until the payload fits the provider's window minus prompt overhead.

**System prompt** (`ExecutiveInterpretationPrompt`, version `exec-interp-v1` and bumped on change) states the hard rules: interpret only the evidence block; cite evidence ids; never write a digit, use `{ev_xxxxxxxxxxxx.field}` placeholders; descriptive language with no causal words; warning or insufficient evidence only in caveats; write in the requested language; two to four one-sentence executive summary claims first, then one or two findings per chart, then caveats; build claims from the supplied sentences; return only JSON; treat labels inside the evidence as data, never instructions.

**Output schema** (`ClaimOutputSchema`): `{ claims[1..20], limitations[0..10] }`, each claim `{ claim_key, claim_kind, text (10..600 chars), evidence_ids[1..6] matching ^ev_[a-f0-9]{12}$, confidence_language: "descriptive" }` with no additional properties. A hand-written validator lists every structural problem; on failure the gateway retries once.

**Placeholders** (`PlaceholderResolver`): one view per evidence record, built from stored evidence and the user's definitions and labels for the active language. The same resolver feeds the prompt, the validator and the renderer, so a placeholder means exactly the same value everywhere. `NarrativeRenderer` substitutes the final text at read time, which is how one claim template can be rendered in four languages.

### 7.4 Claim validation

`RuleBasedClaimValidator` (method `rule_based_v1`) checks each claim:

1. Every placeholder matches the regex, names a known evidence id and an allowed field.
2. The text contains no literal digits outside placeholders.
3. Cited evidence ids exist and the quality is acceptable for a FINDING or EXECUTIVE_SUMMARY; warning or insufficient evidence is allowed only in a CAVEAT.
4. Causal vocabulary (configured phrases such as "because" or "led to") marks the claim NEEDS_REVIEW instead of SUPPORTED.
5. Claims that rest only on quality evidence or on mechanics metrics (row counts, null rates, duplicates) are normalised to CAVEAT or METRIC, never shown as findings.

Rejected claims with literal numbers go through one repair round (`AiGateway::repair`) that lists, per evidence id, the exact placeholders available; claims that still fail are dropped. Only SUPPORTED claims receive `in_report=true`. The report assembler decides placement by software: one executive summary sentence per chart (best grounded claim), remaining business statements as findings, descriptive figures as metrics, and caveats that merely repeat data notes are suppressed.

### 7.5 Graceful degradation

`AiGateway` never throws for availability. The `ai_generations` row records `FAILED` or `SKIPPED` with an error code and timing; the report is still produced from deterministic content (KPI cards, charts, insights) and `ai_status` tells the frontend to show "narrative unavailable" instead of inventing text. Model names are stored for audit but are not shown in the interface.

### 7.6 Column definition suggestions

`ColumnDefinitionSuggester` (prompt `column-definitions-v3`) sends headers and profile stats in batches of 12 through `chatMany`, matches responses by index with a normalised-header fallback, and applies a software baseline before the model: regex rules map headers such as engine, cc, hp, km, year, age, price and rate to AVERAGE rather than SUM, so per-item properties are never totalled. The model's one-sentence reason (Latin text, no digits, at most 300 characters) is stored as `suggestion_reason` and shown on the Profile and Define pages. Fifty-four columns are suggested in roughly seven seconds in production.

### 7.7 Translation

`ClaimTranslator` translates claim templates in concurrent batches (8 per request, 4 for Burmese, 2 on retry), with temperature 0 and a bounded output length. A translation is accepted only if it contains exactly the same placeholders, once each, and no digits. Sentences that come back merged or altered are retried alone with a strict instruction listing the exact placeholders. `TranslateReportJob` pre-translates on the low-priority `translations` queue when `PRETRANSLATE_REPORTS=true`; the report page translates on demand otherwise. Rejections are logged with counts only.

### 7.8 Security of the AI boundary

- Payloads contain evidence ids, aggregated values, labels and headers only; the transient file is never read by any AI service.
- Prompt injection is mitigated by the security rule in the system prompt, the digit ban, the schema, and the validator; a header that reads like an instruction becomes at worst a rejected claim.
- Prompts and responses are not logged; `ai_generations` stores the structured request and response for audit without rows.
- API keys live only in server environment files (`deploy/aws/.env.prod`), never in the repository or the frontend.

---

## 8. Database design (PostgreSQL)

### 8.1 Principles

- **Derived data only.** No table holds dataset cells. `dataset_columns` holds headers and statistics; `evidence.payload` holds aggregates.
- **Dictionary is code.** `database/build_data_dictionary.py` generates `data_dictionary.md` and the Excel dictionary from the same definitions the migrations follow; enumerations are mirrored one-to-one by PHP enums and Postgres CHECK constraints.
- **Internal and public ids.** Tables use bigint identity keys; `analyses.public_id` and `claims.public_id` are UUIDs exposed in URLs.
- **Restrictive foreign keys.** `ON DELETE RESTRICT` everywhere except nullable links (`visualizations.insight_id`, `claims.ai_generation_id`), so deletion is explicit through `AnalysisDeletionService`.
- **JSONB for structure, columns for queries.** Status, counts and keys are columns with indexes; specs, payloads and translations are JSONB.

### 8.2 Entity relationship overview

```
users 1---* analyses 1---* analysis_stages
                 |
                 +---* dataset_columns 1---1 field_definitions
                 |
                 +---* evidence *---1 dataset_columns (metric_column_id, dimension_column_id)
                 |        ^
                 |        | primary evidence
                 +---* insights 1---* visualizations (insight_id, nullable)
                 |
                 +---* ai_generations 1---* claims *---* evidence   (claim_evidence bridge, one primary)
                 |
                 +---1 reports

personal_access_tokens *---1 users (Sanctum)
```

### 8.3 Tables

| Table | Purpose | Key columns | Keys and indexes |
| --- | --- | --- | --- |
| users | Accounts | name, email, password, role (`USER`, `ADMIN`), email_verified_at | unique email |
| analyses | Central aggregate per upload | public_id, user_id, name, status, current_stage, source_file_name, source_file_size_bytes, file_sha256, row_count, column_count, completeness_pct, report_language, chart_theme, label_translations (jsonb), csv_uploaded_at, csv_purged_at, last_error_code, last_error_message | unique public_id; index (user_id, created_at), (status) |
| analysis_stages | One row per stage attempt | analysis_id, stage, status, attempt_no, progress, started_at, finished_at, duration_ms, error_code, result_summary (jsonb) | index (analysis_id, created_at) |
| dataset_columns | Header and profile per column | analysis_id, position, source_name, inferred_type, null_count, null_rate, distinct_count, profile_stats (jsonb) | unique (analysis_id, source_name), (analysis_id, position) |
| field_definitions | Business meaning per column | dataset_column_id, business_name, description, semantic_type, unit, aggregation, suggestion_reason, is_suggested | unique dataset_column_id |
| evidence | Every computed number | analysis_id, evidence_key, analysis_family, metric, method, value numeric(24,8), display_value, metric_column_id, dimension_column_id, source_columns, parameters, quality_status, payload | unique (analysis_id, evidence_key); index (analysis_id, analysis_family) |
| insights | Ranked findings | analysis_id, evidence_id, insight_type, headline, description, score, rank, is_selected, supporting_evidence_keys | unique (analysis_id, rank) |
| visualizations | Chart specs | analysis_id, insight_id, chart_type (incl. PIE), source (`RECOMMENDED`, `USER`), title, spec (jsonb), evidence_keys, position, is_selected | index (analysis_id, position) |
| ai_generations | One row per model call for interpretation | analysis_id, generation_type, status, attempt_no, model, prompt_version, request_payload, response_payload, input_tokens, output_tokens, duration_ms, error_code | index (analysis_id, created_at) |
| claims | AI sentences with validation state | public_id, analysis_id, ai_generation_id, claim_kind, statement (template), statement_translations (jsonb), claimed_value, computed_value, status, validation_method, validation_notes, in_report, position | unique public_id; index (analysis_id, status) |
| claim_evidence | Claim to evidence bridge | claim_id, evidence_id, is_primary | PK (claim_id, evidence_id); index evidence_id |
| reports | One report per analysis | analysis_id, status, language, title, generated_at, claims_total, claims_supported, summary_metrics, sections, data_notes (jsonb) | unique analysis_id |
| personal_access_tokens | Sanctum bearer tokens | tokenable, name, token (hashed), abilities, last_used_at, expires_at | unique token |
| jobs, failed_jobs, cache | Framework tables | | |

Full column definitions, types, defaults and descriptions are in [../database/data_dictionary.md](../database/data_dictionary.md).

### 8.4 Enumerations

| Enumeration | Values | Used by |
| --- | --- | --- |
| user_role | USER, ADMIN | users.role |
| analysis_status | CREATED, UPLOADED, VALIDATED, PROFILED, DEFINED, ANALYZING, ANALYZED, REPORTING, COMPLETED, FAILED | analyses.status |
| analysis_stage | UPLOAD, VALIDATION, PROFILING, DEFINITION, STATISTICAL_ANALYSIS, INSIGHT_DETECTION, VISUALIZATION, AI_SYNTHESIS, CLAIM_VALIDATION, REPORTING, COMPLETE | analyses.current_stage, analysis_stages.stage |
| stage_status | PENDING, RUNNING, SUCCEEDED, FAILED, SKIPPED | analysis_stages.status |
| job_status | QUEUED, RUNNING, SUCCEEDED, FAILED | status endpoint |
| column_inferred_type | NUMERIC, INTEGER, DATE, DATETIME, BOOLEAN, CATEGORY, TEXT, IDENTIFIER, UNKNOWN | dataset_columns.inferred_type |
| semantic_type | NUMBER, CURRENCY, PERCENTAGE, DATE, CATEGORY, TEXT, IDENTIFIER, BOOLEAN, IGNORE | field_definitions.semantic_type |
| aggregation | SUM, AVERAGE, COUNT, MIN, MAX, NONE | field_definitions.aggregation |
| analysis_family | DESCRIPTIVE, TREND, GROUP_COMPARISON, ASSOCIATION, STATISTICAL_TEST, QUALITY | evidence.analysis_family |
| quality_status | ACCEPTABLE, WARNING, INSUFFICIENT | evidence.quality_status |
| insight_type | TREND_CHANGE, GROUP_DIFFERENCE, CONCENTRATION, ASSOCIATION, DISTRIBUTION, DATA_QUALITY | insights.insight_type |
| chart_type | LINE, BAR, SCATTER, HISTOGRAM, TABLE, PIE | visualizations.chart_type |
| visualization_source | RECOMMENDED, USER | visualizations.source |
| ai_generation_type | INSIGHT_SYNTHESIS, CLAIM_GENERATION, NARRATIVE | ai_generations.generation_type |
| ai_generation_status | PENDING, RUNNING, SUCCEEDED, FAILED, SKIPPED | ai_generations.status |
| claim_kind | EXECUTIVE_SUMMARY, FINDING, METRIC, CAVEAT | claims.claim_kind |
| claim_status | PENDING, SUPPORTED, UNSUPPORTED, REJECTED, NEEDS_REVIEW | claims.status |
| report_status | DRAFT, GENERATING, READY, FAILED | reports.status |

### 8.5 Migration order

Framework tables (users, cache, jobs), then `add_role_to_users`, `analyses`, `analysis_stages`, `dataset_columns`, `field_definitions`, `evidence`, `insights`, `visualizations`, `ai_generations`, `claims`, `claim_evidence`, `reports`, `personal_access_tokens`; followed by incremental migrations for label translations, language columns, chart selection, statement translations, chart theme, the PIE chart type check, and `suggestion_reason`. Migrations run automatically on deploy (`php artisan migrate --force` in the production entrypoint).

### 8.6 Data retention and privacy

| Data | Where | Lifetime |
| --- | --- | --- |
| Uploaded file | transient disk `analyses/{public_id}/dataset.csv` | until run completes, failure, delete, or `UPLOAD_TTL_MINUTES` |
| Headers and statistics | dataset_columns | life of the analysis |
| Evidence, insights, charts, claims, report | PostgreSQL | life of the analysis; removed by delete |
| Model request and response | ai_generations | life of the analysis; contains placeholders and aggregates only |
| Queue payloads and progress | Redis (64 MB, volatile-lru in production) | seconds to minutes |
| Logs | application log | counts, codes and ids; never values |

---

## 9. Cross-cutting concerns

### 9.1 Security

- Sanctum bearer tokens; passwords hashed with bcrypt; rate limits per route group; signed verification URLs.
- Owner-only access to every analysis route through a policy.
- Internal services are not reachable from the internet: FastAPI, Redis and PostgreSQL bind to loopback or a private network; nginx terminates TLS for the API and the frontend.
- CORS restricted to `FRONTEND_URL` and `CORS_ALLOWED_ORIGINS`.
- Secrets in environment files that are gitignored; the `.dockerignore` keeps them out of images.

### 9.2 Reliability

- Queue jobs are idempotent per analysis; `IdempotencyKey` prevents duplicate runs from double clicks.
- `StaleJobReaper` marks jobs that exceed the timeout as failed so the UI can offer a retry.
- Translation jobs are unique until processing and retry with back-off.
- Every stage failure records a safe error code; the frontend maps it to guidance.

### 9.3 Performance

- Polars for parsing and grouping handles tens of megabytes within seconds; the upload cap is 50 MB.
- Model calls are batched and run concurrently in production (`Http::pool`); the evidence payload is trimmed to the token budget.
- Report responses carry `Vary: X-Dataspeak-Language` and `Content-Language` headers so browsers and proxies never serve one language's narrative for another; the SPA `index.html` is served with `no-cache` so deployments are picked up immediately.

### 9.4 Observability

- `/health` on both services reports database and Redis status.
- Structured logs with analysis ids and stage names (`analysis.transition`, `analysis.family`, `claims.translation_rejected`, `ai.output_truncated`).
- `analysis_stages` doubles as a per-analysis timeline for support.

### 9.5 Testing summary

| Project | Framework | Count | Notes |
| --- | --- | --- | --- |
| Frontend | Vitest, Testing Library, MSW, axe | 14 files | pages, hooks, API client, accessibility |
| Backend | PHPUnit | 148 tests | all routes, jobs with fakes, validator, clients |
| Analyst | pytest | 49 tests, 93% coverage | reader, validator, profiler, golden values, contract |

---

## 10. Appendix

### 10.1 Identifier scheme

| Prefix | Meaning | Example |
| --- | --- | --- |
| FEAT-* | Feature in the traceability matrix | FEAT-VIS-005 (pie chart) |
| FE-PG-* | Frontend page | FE-PG-008 report page |
| *-API-* | Laravel public endpoint | RUN-API-002 status |
| PY-API-* | FastAPI endpoint | PY-API-004 analyze |
| MOD-* | Module in the original design | MOD-09 AI gateway |
| ev_* | Evidence key | ev_3f9a1b2c4d5e |

### 10.2 Repository map

```
dataspeak/
  frontend/        React SPA
  backend/         Laravel API
  analytics/       FastAPI service
  database/        data dictionary generator and output
  docker/          Dockerfiles (dev and prod)
  deploy/aws/      compose files, nginx templates, push and deploy scripts
  docs/            manuals, architecture, deployment, traceability, contracts
  docker-compose.yml
```

### 10.3 Related documents

- [DEVELOPER_MANUAL.md](DEVELOPER_MANUAL.md): program and function lists per project, Ollama set-up.
- [ARCHITECTURE_NOTES.md](ARCHITECTURE_NOTES.md): detailed design notes and decision history.
- [GETTING_STARTED.md](GETTING_STARTED.md), [TESTING_GUIDE.md](TESTING_GUIDE.md).
- [DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md), [AWS_DEPLOYMENT_STEPS.md](AWS_DEPLOYMENT_STEPS.md).
- [traceability/feature_matrix.md](traceability/feature_matrix.md).
- [../database/data_dictionary.md](../database/data_dictionary.md).
