# Data Speak Architecture and Design Notes

Version 1.0, 3 October 2026. Covers everything implemented in the repository as of this date:
the repository foundation, the data-dictionary-driven schema, the Laravel application API and the
FastAPI analytics service. The React frontend is still the Figma Make prototype and is described only
as far as its tooling goes.

For installing and running the project see [GETTING_STARTED.md](GETTING_STARTED.md). For the program lists,
function lists and integration API lists see [DEVELOPER_MANUAL.md](DEVELOPER_MANUAL.md); this file keeps the
architecture narrative, the design decisions and the dated change log (the "added 4 October 2026" paragraphs).

---

## 1. What Data Speak is

Data Speak turns one structured business CSV into validated analytical evidence, ranked findings,
chart specifications and an executive report. The four rules that shape every design decision:

**Software calculates. AI interprets. Validation verifies. Raw customer CSV data is transient.**

In practice this means:

- Every number shown to a user comes from a deterministic computation in the FastAPI service and is
  stored as an **evidence** record with its method and parameters. Nothing invents numbers.
- The AI Gateway (not yet built) will receive evidence records, never dataset rows.
- Uploaded CSV files live only in a temporary location and are deleted when the analysis run
  finishes, when the analysis is deleted, or when a TTL expires. No database table, cache, log line
  or Docker volume ever holds raw rows or cell values.
- The browser talks to Laravel only. FastAPI is an internal service reachable from Laravel alone.

## 2. Architecture

```
React + TypeScript (5173)  ->  Laravel /api/v1 (8000)  ->  PostgreSQL 17   derived metadata only
                                     |                 ->  Redis 7        queue jobs + progress
                                     |
                                     v  X-Internal-Token
                           FastAPI analytics (internal, 127.0.0.1:8001 locally)
                           Polars . SciPy . statsmodels . scikit-learn
                                     |
                                     v
                           evidence -> insights -> chart specs  -> Laravel persists
                                                                -> report assembly
                           AI Gateway / claim validation: interfaces only (planned)
```

Request flow for one analysis: upload (Laravel stores a transient file) -> validate (Laravel calls
FastAPI `/v1/validate`) -> profile (`/v1/profile`, Laravel persists `dataset_columns`) -> definitions
(user maps columns to business meaning) -> run (Laravel queues `RunAnalysisJob`, which calls
`/v1/analyze`, `/v1/insights`, `/v1/visualizations/recommend` and persists results) -> report
(Laravel assembles from persisted evidence) -> traceability (claim -> evidence).

## 3. Repository layout

| Path | Contents |
| --- | --- |
| `frontend/` | Vite + React + TypeScript. Prototype screens plus production tooling (ESLint, Prettier, Vitest). |
| `backend/` | Laravel 13 application API. Migrations live in `backend/database/migrations`. |
| `analytics/` | FastAPI analytics service. |
| `database/` | `build_data_dictionary.py` (schema source of truth as code) and the generated `data_dictionary.md`. |
| `docs/` | Specification documents, the generated `Data_Speak_Data_Dictionary.xlsx`, `conventions/`, `contracts/`, `traceability/`, this manual. |
| `tests/` | Cross-service tests. `e2e/smoke_workflow.py` drives the real stack. |
| `docker/` | One Dockerfile per service plus the backend entrypoint. |
| `.github/workflows/ci.yml` | CI: four independent jobs (frontend, backend, analytics, compose config). |

Identifier scheme used in code comments, docblocks and test names (`FEAT-*`, `MOD-*`, `*-API-*`,
`PY-API-*`, `FE-*`, `NFR-*`): see [conventions/identifiers.md](conventions/identifiers.md).

## 4. Local stack (Docker Compose)

| Service | Image | Port on host | Notes |
| --- | --- | --- | --- |
| `frontend` | node:24-alpine | 5173 | Vite dev server, source bind-mounted. |
| `backend` | php:8.4-cli-alpine | 8000 | PHP built-in server with Laravel's router script. |
| `backend-worker` | same image | none | `php artisan queue:work redis --queue=default,translations`. Processes the profiling, analysis and report jobs first, then background pre-translations. |
| `analytics` | python:3.12-slim | 127.0.0.1:8001 | uvicorn with `--reload`. Loopback only, by design. |
| `postgres` | postgres:17 | 127.0.0.1:5432 | UTF8 encoding, named volume `postgres_data`. |
| `redis` | redis:7-alpine | 127.0.0.1:6379 | No persistence. |

Design points worth knowing:

- **Why not `artisan serve`.** Laravel's `ServeCommand` passes only a short allow-list of
  environment variables to its PHP workers. In a container that means `DB_HOST=postgres` would be
  dropped and the workers would read the bind-mounted `.env` instead. The web container therefore
  runs `php -S` with `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
  started from `public/` because that script uses the current directory as the public path.
- **Vendor and node_modules are anonymous volumes.** They are created from the image on first start
  and are *not* refreshed by a rebuild. After changing `composer.json` or `package.json`, recreate
  with `docker compose up -d --force-recreate -V <service>` (`-V` renews anonymous volumes).
- **The worker caches code.** After editing backend PHP, run `docker compose restart backend-worker`.
  The web container and the analytics container pick up changes automatically.
- **Transient storage.** `backend/storage/app/tmp` is a gitignored project path bind-mounted into
  both the web and worker containers, so a file stored by the web request is visible to the job. It
  is deliberately not a tmpfs (a tmpfs is per container). The analytics scratch directory
  `/tmp/dataspeak` *is* a tmpfs, mounted with `mode=1777` because the service runs as a non-root
  user. The only named volume in the project is `postgres_data`.
- **AI Gateway placeholder.** A commented service block in `docker-compose.yml` documents the
  expected image and env names (`AI_GATEWAY_BASE_URL`, `AI_GATEWAY_TOKEN`). Nothing starts.

## 5. Database

### 5.1 The data dictionary is code

No dictionary spreadsheet existed, so the schema is authored in
[`database/build_data_dictionary.py`](../database/build_data_dictionary.py). Running it regenerates
`docs/Data_Speak_Data_Dictionary.xlsx` (sheets: Tables, Columns, Relationships, Constraints,
Enumerations, Decisions, MigrationOrder, FrameworkTables) and `database/data_dictionary.md`.

Workflow for any schema change:

1. Edit the Python file (tables, columns, enums, decisions).
2. `analytics/.venv/bin/python database/build_data_dictionary.py` (needs `openpyxl`).
3. Write or edit the migration in `backend/database/migrations` to match.
4. Update the PHP enum in `backend/app/Enums` if an enumeration changed.
5. `php artisan migrate`, `migrate:rollback`, `migrate` against Postgres.

Never edit the spreadsheet by hand; it is overwritten.

### 5.2 Tables

Thirteen Data Speak tables plus Laravel framework tables:

`users` (extended with `role`), `analyses`, `analysis_stages`, `dataset_columns`,
`field_definitions`, `evidence`, `insights`, `visualizations`, `ai_generations`, `claims`,
`claim_evidence`, `reports`, `personal_access_tokens` (Sanctum).

Decisions recorded in the dictionary's Decisions sheet:

- **Keys.** bigint identity primary keys everywhere. `analyses.public_id` and `claims.public_id` are
  UUIDs used in URLs. Evidence is addressed by `evidence_key` (the deterministic `ev_...` string
  FastAPI emits), unique per analysis.
- **Enumerations.** VARCHAR columns with named CHECK constraints (`chk_<table>_<column>`), applied
  only on PostgreSQL. SQLite (used by the test suite) gets the columns without the constraints.
- **Foreign keys.** `ON DELETE RESTRICT` on every evidence or traceability relationship. `SET NULL`
  only for `visualizations.insight_id` and `claims.ai_generation_id`. Deleting an analysis is an
  explicit, ordered, transactional operation in `AnalysisDeletionService`.
- **Traceability.** `claim_evidence` is a bridge (claim to many evidence rows, one marked primary),
  a deliberate deviation from the ERD's single foreign key.
- **No raw data.** JSONB columns (`profile_stats`, `payload`, `spec`, `result_summary`, report
  sections) hold aggregates, method metadata and specifications only.

The full column list is in [`database/data_dictionary.md`](../database/data_dictionary.md).

### 5.3 Enumerations (authoritative values)

| Enumeration | Values |
| --- | --- |
| analysis_status | CREATED, UPLOADED, VALIDATED, PROFILED, DEFINED, ANALYZING, ANALYZED, REPORTING, COMPLETED, FAILED |
| analysis_stage | UPLOAD, VALIDATION, PROFILING, DEFINITION, STATISTICAL_ANALYSIS, INSIGHT_DETECTION, VISUALIZATION, AI_SYNTHESIS, CLAIM_VALIDATION, REPORTING, COMPLETE |
| stage_status | PENDING, RUNNING, SUCCEEDED, FAILED, SKIPPED |
| job_status | QUEUED, RUNNING, SUCCEEDED, FAILED |
| column_inferred_type | NUMERIC, INTEGER, DATE, DATETIME, BOOLEAN, CATEGORY, TEXT, IDENTIFIER, UNKNOWN |
| semantic_type | NUMBER, CURRENCY, PERCENTAGE, DATE, CATEGORY, TEXT, IDENTIFIER, BOOLEAN, IGNORE |
| aggregation | SUM, AVERAGE, COUNT, MIN, MAX, NONE |
| analysis_family | DESCRIPTIVE, TREND, GROUP_COMPARISON, ASSOCIATION, STATISTICAL_TEST, QUALITY |
| quality_status | ACCEPTABLE, WARNING, INSUFFICIENT |
| insight_type | TREND_CHANGE, GROUP_DIFFERENCE, CONCENTRATION, ASSOCIATION, DISTRIBUTION, DATA_QUALITY |
| chart_type | LINE, BAR, SCATTER, HISTOGRAM, TABLE |
| claim_kind | EXECUTIVE_SUMMARY, FINDING, METRIC, CAVEAT |
| claim_status | PENDING, SUPPORTED, UNSUPPORTED, REJECTED, NEEDS_REVIEW |
| report_status | DRAFT, GENERATING, READY, FAILED |
| ai_generation_type / status | INSIGHT_SYNTHESIS, CLAIM_GENERATION, NARRATIVE / PENDING, RUNNING, SUCCEEDED, FAILED, SKIPPED |
| user_role | USER, ADMIN |

FastAPI emits the lowercase forms (`"trend"`, `"acceptable"`, `"line"`); Laravel upper-cases on
persistence. The PHP enums live in `backend/app/Enums`.

## 6. Backend (Laravel)

### 6.1 Structure

```
backend/app
  Console/Commands/PurgeExpiredUploads.php    dataspeak:purge-uploads, scheduled every 5 minutes
  Contracts/                                  AiInterpretationService, ClaimValidationService (interfaces)
  Enums/                                      one backed enum per dictionary enumeration
  Exceptions/                                 ApiException hierarchy + ApiExceptionRenderer
  Http/Controllers/Api/V1/                    one controller per resource group
  Http/Middleware/                            ForceJsonResponse, IdempotencyKey
  Http/Requests/                              Form Requests (all input validation)
  Http/Resources/                             API Resources (all output shaping)
  Jobs/RunAnalysisJob.php                     the analysis pipeline
  Logging/                                    PrivacyProcessor, PrivacyTap
  Models/                                     Eloquent models (AnalysisStageRecord = table analysis_stages)
  Policies/AnalysisPolicy.php
  Services/
    Lifecycle/AnalysisLifecycle.php           THE state machine
    Uploads/TransientUploadStore.php          the only code that touches CSV bytes
    Analytics/AnalyticsServiceClient.php      the only FastAPI client
    Analytics/ProfilePersister.php            profile response -> dataset_columns
    Analytics/AnalysisResultPersister.php     evidence/insights/visualizations persistence
    Analytics/ProgressStore.php               job progress in the cache (Redis)
    Analytics/DefinitionPayload.php           stored definitions -> contract objects
    Reports/ReportAssembler.php
    Ai/NotConfigured*.php                     placeholder bindings that throw 501
    AnalysisDeletionService.php
  Support/ResolvesOwnedAnalysis.php           owner-scoped lookup (404 for other users)
backend/config/dataspeak.php                  upload limits, TTL, poll cadence, analysis options
backend/routes/api.php                        the route table
```

### 6.2 Route table

All routes are under `/api/v1`, return JSON regardless of `Accept`, and use the envelopes
`{"data": ..., "meta"?: ...}` and `{"error": {"code", "message", "details"?}}`.

| ID | Method | Path | Controller | Guards |
| --- | --- | --- | --- | --- |
| health | GET | `/health` | HealthController | none |
| AUTH-API-001 | POST | `/auth/register` | AuthController@register | throttle:auth (5/min/IP) |
| AUTH-API-002 | POST | `/auth/login` | AuthController@login | throttle:auth |
| AUTH-API-003 | POST | `/auth/logout` | AuthController@logout | auth:sanctum |
| AUTH-API-004 | GET | `/me` | AuthController@me | auth:sanctum |
| AUTH-API-005 | GET | `/auth/verify-email/{id}/{hash}` | EmailVerificationController@verify | signed, time-limited link; throttle:verification; redirects to `FRONTEND_URL/login?verified=1|already|invalid` |
| AUTH-API-006 | POST | `/auth/email/resend` | EmailVerificationController@resend | auth:sanctum, throttle:verification (3/min) |
| ANL-API-001 | GET | `/analyses` | AnalysisController@index | auth, paginated (`per_page` max 100) |
| ANL-API-002 | POST | `/analyses` | AnalysisController@store | auth, StoreAnalysisRequest |
| ANL-API-003 | GET | `/analyses/{id}` | AnalysisController@show | auth, policy view |
| ANL-API-004 | DELETE | `/analyses/{id}` | AnalysisController@destroy | auth, policy delete |
| DATA-API-001 | POST | `/analyses/{id}/dataset` | DatasetController@upload | throttle:upload (10/min), UploadDatasetRequest |
| DATA-API-002 | POST | `/analyses/{id}/validate` | DatasetController@validate | auth |
| PROF-API-001 | POST | `/analyses/{id}/profile` | ProfileController@run | auth, policy update, verified.api; requires VALIDATED + transient file; **202** and queues `ProfileAnalysisJob` (stages PROFILING, DEFINITION), progress via RUN-API-002; idempotent while the job is active |
| PROF-API-002 | GET | `/analyses/{id}/profile` | ProfileController@show | auth |
| DEF-API-001 | PUT | `/analyses/{id}/definitions` | DefinitionController@update | UpdateDefinitionsRequest |
| DEF-API-002 | POST | `/analyses/{id}/definitions/suggest-names` | DefinitionSuggestionController | auth, policy update, verified.api, throttle:ai-suggest (10/min); requires PROFILED; returns full suggested definitions, stores nothing |
| RUN-API-001 | POST | `/analyses/{id}/run` | RunController@run | throttle:run (6/min), idempotent, RunAnalysisRequest |
| RUN-API-002 | GET | `/analyses/{id}/status` | RunController@status | auth |
| INS-API-001 | GET | `/analyses/{id}/insights` | InsightController@index | auth |
| VIS-API-001 | GET | `/analyses/{id}/visualizations` | VisualizationController@index | auth |
| VIS-API-002 | GET | `/analyses/{id}/visualizations/options` | VisualizationController@options | auth, policy view; requires ANALYZED; charts the stored evidence can support that are not present yet, each with its full specification and series so the browser can draw it before it is saved |
| VIS-API-003 | PUT | `/analyses/{id}/visualizations/selection` | VisualizationController@select | auth, policy update; requires ANALYZED or COMPLETED, not processing; body `{selected: [ids], add: [option keys]}`; 422 `NO_CHARTS_SELECTED` / `UNKNOWN_CHART_OPTION` |
| RPT-API-001 | POST | `/analyses/{id}/report` | ReportController@generate | throttle:report (6/min), idempotent |
| RPT-API-002 | GET | `/analyses/{id}/report` | ReportController@show | auth |
| RPT-API-003 | GET | `/analyses/{id}/report/pdf` | ReportPdfController | auth, policy view, verified.api, throttle:report; requires COMPLETED; returns `application/pdf` |
| RPT-API-004 | POST | `/analyses/{id}/report/translate` | ReportTranslationController | auth, policy view, verified.api, throttle:ai-suggest; requires COMPLETED; body `{language}`; translates verified statements and labels into that language, synchronously |
| TRC-API-001 | GET | `/analyses/{id}/claims/{claimId}` | ClaimController@show | auth |

`{id}` is the analysis `public_id` UUID; `{claimId}` is the claim `public_id`.

HTTP code usage: 401 unauthenticated or bad credentials (`UNAUTHENTICATED`, `INVALID_CREDENTIALS`),
403 policy denial (`FORBIDDEN`), 404 not found including other users' analyses (`NOT_FOUND`),
409 lifecycle problems (`INVALID_TRANSITION`, `STEP_NOT_READY`, `ANALYSIS_BUSY`, `UPLOAD_REQUIRED`),
422 input or dataset validation (`VALIDATION_FAILED`, `CSV_VALIDATION_FAILED`, `UNKNOWN_COLUMN`,
`ANALYTICS_INVALID_DATASET`, `ANALYTICS_INSUFFICIENT_DATA`, `ANALYTICS_UNSUPPORTED_TYPE`),
429 (`RATE_LIMITED`), 501 planned capability (`SERVICE_NOT_CONFIGURED`), 502 analytics returned an
error (`ANALYTICS_METHOD_FAILED`, `ANALYTICS_INTERNAL_ERROR`, `ANALYTICS_BAD_RESPONSE`),
503 analytics unreachable (`ANALYTICS_UNAVAILABLE`). The mapping lives in
`App\Exceptions\ApiExceptionRenderer`.

### 6.3 Authorization model

Analyses are looked up with `ResolvesOwnedAnalysis::ownedAnalysis()`, which scopes the query to the
authenticated user. A non-owner therefore receives 404, not 403, so analysis IDs cannot be
enumerated. `AnalysisPolicy` is still checked afterwards as defence in depth. Tokens are Sanctum
personal access tokens; `logout` revokes only the current token.

### 6.4 Lifecycle state machine

`App\Services\Lifecycle\AnalysisLifecycle` is the only code that writes `analyses.status`.
`TRANSITIONS` lists the allowed targets for each status:

| From | To |
| --- | --- |
| CREATED | UPLOADED |
| UPLOADED | UPLOADED, VALIDATED, FAILED |
| VALIDATED | UPLOADED, PROFILED, FAILED |
| PROFILED | UPLOADED, PROFILED, DEFINED, FAILED |
| DEFINED | UPLOADED, PROFILED, DEFINED, ANALYZING, FAILED |
| ANALYZING | ANALYZED, FAILED |
| ANALYZED | UPLOADED, DEFINED, ANALYZING, REPORTING, FAILED |
| REPORTING | COMPLETED, FAILED |
| COMPLETED | UPLOADED, DEFINED, ANALYZING, REPORTING |
| FAILED | UPLOADED, PROFILED, DEFINED, ANALYZING, REPORTING |

Helpers: `transition()` (throws 409 `INVALID_TRANSITION`), `assertAtLeast()` (409 `STEP_NOT_READY`;
FAILED never satisfies it), `assertNotProcessing()` (409 `ANALYSIS_BUSY` during ANALYZING or
REPORTING), `beginStage()` / `completeStage()` / `failStage()` (rows in `analysis_stages` with
`attempt_no`, progress, duration and a counts-only `result_summary`), `fail()` (sets safe
`last_error_code` and `last_error_message`).

Step preconditions enforced by controllers: validate needs UPLOADED and a transient file; profile
needs VALIDATED and a file; definitions need PROFILED; run needs definitions saved and a file and
either DEFINED or later or FAILED; report needs ANALYZED or later. Re-uploading clears all derived
results and columns and returns the analysis to UPLOADED.

### 6.5 Transient CSV handling

`TransientUploadStore` is the only component that touches uploaded bytes.

- Disk `transient` (`config/filesystems.php`) rooted at `storage/app/tmp`, `serve => false`.
- Path: `analyses/{public_id}/dataset.csv`. The database stores only the original file name, size,
  SHA-256, `csv_uploaded_at` and `csv_purged_at`.
- File-level controls in `UploadDatasetRequest`: `.csv` extension, MIME in the configured list
  (plain CSV is detected as `text/plain`, which is why that type is allowed), non-empty, size limit
  `UPLOAD_MAX_MB` (default 50).
- Deleted: when the run job finishes successfully; when the job fails with a dataset error; on
  analysis delete; by `dataspeak:purge-uploads` after `UPLOAD_TTL_MINUTES` (default 60, scheduled
  every five minutes, skips analyses that are ANALYZING or REPORTING, also removes orphan dirs).
- Kept for retry: when the job fails because the analytics service was unreachable or returned a
  server-side error (`AnalyticsServiceException::isTransient()`), bounded by the TTL. Retrying from
  FAILED is allowed while the file exists; otherwise the run endpoint returns 409 `UPLOAD_REQUIRED`.
- Never logged: the log processors strip `file`, `csv`, `rows`, `row`, `cells`, `cell`,
  `content`, `sample`, `payload_rows` keys; the analytics client never logs bodies.

**Chart review at the Analyze step (added 4 October 2026, FEAT-VIS-003).** The run no longer chains into the
report by default (`AUTO_REPORT=false`): `RunAnalysisJob` stops at ANALYZED after the analytics service has
recommended charts. The Analyze page then shows every recommended chart with a preview (VIS-API-001 now carries
`source` RECOMMENDED|USER and `is_selected`), lets the user untick charts and add others from VIS-API-002, and
saves the choice with VIS-API-003 before requesting the report (RPT-API-001). Options come from
`Services/Reports/ChartCatalog`, a PHP mirror of the recommender: every chart is a specification over stored
evidence payloads (period series → line, group aggregates → bar total and bar average, distribution → histogram,
correlation → table, missing values → bar), so a user-added chart is as calculated as a recommended one and no
dataset rows are read. Added charts are stored as `visualizations` rows with `source = USER`. Everything that
builds or shows the report uses `Analysis::selectedVisualizations()` only: `ReportAssembler`, `ChartGrounding`,
`EvidencePayloadBuilder` (the model never hears about a removed chart), `ReportResource` and the PDF. From a
completed analysis, **Review charts** reopens the panel and **Rewrite the report with these charts** regenerates.
Dictionary: `visualizations.source`, `visualizations.is_selected`, enum `visualization_source`. Tests:
`ChartSelectionTest`; frontend `workflow.integration.test.tsx`.

**Chart review tabs (5 October 2026).** The panel is split into two tabs, *Recommended charts* (the system's
recommendations with keep/untick) and *Custom required charts* (charts the user adds from VIS-API-002, with the
add-a-chart picker, data preview and the custom charts saved earlier). Each tab shows how many of its charts go
into the report; the colour theme and the confirm button sit outside the tabs because they apply to both.

**Chart colour theme (added 5 October 2026, FEAT-VIS-004).** The chart review panel also offers six colour
themes (Data Speak green by default, ocean blue, sunset orange, plum violet, monochrome, vivid mix). The choice
is sent with VIS-API-003 as `theme`, stored in `analyses.chart_theme` and returned as `chart_theme` on the
analysis and report resources. Bars cycle through the palette, lines and histograms use its first colour. The web
charts (`ChartRenderer`, `theme` prop) and the PDF (`ChartSvg::$palette`, set by `ReportPdfBuilder`; `ChartHtml`
bars inline) use the same list, kept identical in `backend/app/Support/ChartThemes.php` and
`frontend/src/utils/chartThemes.ts`. Colours only: nothing about the data changes with the theme.

**Pie chart (added 5 October 2026, FEAT-VIS-005).** `PIE` joins the `chart_type` enumeration (migration
`2026_10_05_110000` widens the Postgres check constraint). `ChartCatalog` offers `pie:<evidence_key>` ("Share of
<measure> by <dimension>", `charts.share_by` in every language) for each group-aggregate evidence with two to
eight non-negative totals; the slices are the stored `sum` values and the percentages their ratio, so a pie is as
calculated as a bar. The recommender does not propose pies on its own; users add them at chart review. Web:
Recharts `PieChart` with the theme palette and a legend; PDF: `ChartSvg::pie` (arcs, in-slice shares, legend with
value and share; numbered key for non-Latin names as for bars).

**Languages (added 4 October 2026, FEAT-I18N-003).** English is the default; Thai, Simplified Chinese and
Burmese are available for the interface and for the report. Interface: `frontend/src/i18n` holds dictionaries
keyed by the English source string (`t('Analyses')`), a provider that remounts the tree on change, and a
switcher in the sidebar and on the auth pages; the choice is kept in `localStorage` (`lib/locale.ts`) and sent
on every request as `X-Dataspeak-Language`, which the `SetLocale` middleware turns into the app locale so
`/insights`, `/visualizations` and claims come back localised. Report: `POST /run` and `POST /report` accept
`language` (the frontend sends the interface language); it is stored on `analyses.report_language` and
`reports.language`. `GenerateReportJob` builds the display-label map for that language (`DisplayLabelTranslator`
with the locale, stored per locale under `label_translations`), asks the model to write in it (`Locale::name()`
in the prompt), validates with `WRONG_LANGUAGE` (text outside placeholders must use only that script plus
Latin, and for non-English must contain it), and assembles with localised sentences (`lang/{locale}/sentences.php`),
chart titles (`ChartTitles` re-renders the analytics patterns from `charts.php`), metric names, notes and PDF
headings (`report.php`). `ReportResource` renders everything software writes (chart titles and readings, key-figure labels, data notes,
insights) in the language the client asked for (`display_language`), while the model's claims keep the language
they were written in (`language`); `ReportPdfBuilder` renders with the display language set to the interface language of the request (`Content-Language` on the response), so the exported PDF matches what the page shows: headings, labels and chart titles in that language, translated statements where they exist and the original sentence otherwise. When the
interface language differs from `language`, the Report page calls RPT-API-004 (`POST /report/translate`) once per
analysis and language: `Services/Ai/ClaimTranslator` asks the model to *translate* each verified statement template,
never to re-interpret, and accepts a translation only if every placeholder survives unchanged (same ids, fields and
count), no digit appears outside placeholders and the text is in the target script; accepted translations are
stored in `claims.statement_translations` per language. `ClaimResource`, the report readings, the claim endpoint and
the PDF render the translation for the display language when it exists and the original sentence (in the report's
language, never mixed) otherwise; `translation.complete` tells the page whether anything is still pending. To make switching instant, `GenerateReportJob` queues `Jobs/TranslateReportJob` for every other supported language as
soon as a report is COMPLETED (`PRETRANSLATE_REPORTS`, default on). Those jobs go on the low-priority `translations`
queue and the worker is started with `--queue=default,translations`, so a backlog of translations (one to four minutes
of model time each) never delays a user's own profiling, analysis or report job. The job raises a cache flag that the resource
exposes as `translation.in_progress`, so the page polls the report instead of starting a second translation, and
RPT-API-004 answers 202 `in_progress` while a background job runs. `ClaimTranslator` works in small batches (eight
sentences, four when Burmese is involved, because Myanmar script is token-heavy for the model) and stores each
accepted sentence as soon as its batch returns; full-width punctuation carried over from a Chinese source (`。` at
the end of a Thai sentence) is normalised before the acceptance check instead of failing it. When the model times
out or returns invalid output with sentences still pending, the job re-queues itself (`attempt` 2 and 3, delayed one
and two minutes, lock type `ShouldBeUniqueUntilProcessing`) and keeps the in-progress flag up. Translation calls cap the
output (`num_predict` = 400 tokens per sentence) because the model can fall into a repetition loop in Myanmar script and
would otherwise run into the timeout; a retry runs at temperature 0.3 so it does not reproduce the same loop. Rejected
sentences (digits, lost placeholders, wrong script) are not retried on their own because the first attempt is deterministic.
`DisplayLabelTranslator` applies the same idea to labels: batches of 25 (12 for Burmese) with an output cap, the output
field is named `translation` (with `english` the model handed Burmese labels back in English), and `DisplayLabels::mapFor` layers the
English map under each language map, so a label the model could not render in that language shows its English
business name rather than the raw column header or category value. Only a report that was never pre-translated
(for example generated before this feature) pays the ten to thirty seconds at switch time. A regeneration is only
ever started by the user (Rewrite summary, chart review). PDF fonts: Noto Sans Thai and Myanmar always, Noto Sans SC only
for Chinese reports (17 MB variable font, loaded with 768 MB memory and glyph subsetting). Tests:
`ReportLanguageTest`, frontend `i18n.test.tsx`.

**English-only report (added 4 October 2026, FEAT-I18N-002).** Everything a reader sees in the report, chart series
included, is English. Three mechanisms: (1) the Define step refuses business names in other scripts (DEF-API-001
`regex` rule and the matching client check; the suggestions make this painless); (2) at the end of
`RunAnalysisJob`, `Services/Ai/DisplayLabelTranslator` collects every non-Latin business name and category value
from the persisted evidence payloads and chart series (aggregate labels, never rows), asks the model for English
equivalents under a strict schema (standard spellings for places and brands, Buddhist years converted), keeps only
Latin-only answers and stores the map in `analyses.label_translations`; `Services/Reports/DisplayLabels` then
applies it in every presentation path: `PlaceholderResolver` views (so rendered claims, `EvidenceSentences`, chart
summaries and the AI prompt payload are English), `VisualizationResource` (titles, axis labels, series values),
`InsightResource`, `EvidenceResource`, `ReportAssembler` labels, and therefore the PDF. Stored evidence, insights
and specifications keep the original values. The step never fails the run; `dataspeak:translate-labels <id>
--show` rebuilds the map for an analysis run before this feature, after which **Rewrite summary** regenerates the
narrative in English. (3) `RuleBasedClaimValidator` rejects any claim whose text outside placeholders is not Latin
(`NON_ENGLISH_TEXT`, repairable), so the executive summary cannot contain another script even if the model slips.

**Profiling is a queued job (changed 4 October 2026).** `POST /profile` answers 202 and dispatches
`Jobs/ProfileAnalysisJob`, which reports two stages through the progress store and RUN-API-002: `PROFILING`
(analytics service, derived columns persisted) and `DEFINITION` (suggested definitions, below). The browser shows
the same progress bar as the Analyze step. On failure the analysis stays VALIDATED with the file kept, the job
record carries `error: {code, message}` (for example `ANALYTICS_UNAVAILABLE`, `UPLOAD_REQUIRED`), the status
endpoint returns it and the Profile page offers **Try again**. A second POST while the job is queued or running
returns the same job id. `StaleJobReaper::reapProfiling()` marks a profiling job whose record stopped updating
(timeout `PROFILE_JOB_TIMEOUT_SECONDS`, 300 s, plus grace) as `JOB_INTERRUPTED` without touching the analysis
state. Tests: `ValidateAndProfileTest` (202 + status + profile read, failure through status, idempotent start).

**Suggested definitions at profiling (added 4 October 2026, FEAT-DEF-003).** The meaning of a column decides
what is analysed and charted (a registration year must not be summed; a plate number is an identifier; "ราคาเปิด"
is an opening price in THB), and type inference only sees values. So `ProfileAnalysisJob` calls
`Services/Ai/ColumnDefinitionSuggester::apply()` right after the profile is persisted. Software first builds a
baseline per column from the inferred type plus header hints (`_thb` → CURRENCY THB, `_pct` → PERCENTAGE %);
then one model call receives every column's header, detected type, distinct count, missing share and numeric
range (never rows) with a strict JSON schema and temperature 0, and proposes an English business name, a
semantic type from the dictionary enum, a unit, an aggregation and a one-line description. Each proposal is
accepted only when it is compatible with the profiled values (money or numbers only for numeric columns, DATE
only for dates, CATEGORY only up to 20 groups, names Latin-only and short); anything else keeps the baseline.
Results are stored as `field_definitions` with `is_suggested = true`; a definition the user confirmed
(`is_suggested = false`) is never overwritten, including on re-profiling. The Profile page shows the suggested
meaning per column and the Define page is prefilled from it; DEF-API-001 is still the only writer of confirmed
definitions. DEF-API-002 reruns the same suggester on demand without storing anything (the Define page's
**Suggest again**, which only touches rows the user has not edited). When the model is down, profiling still
succeeds with the software baseline and `suggestions.ai_status: unavailable` in the profile response. The model
call has its own 120 s timeout so profiling cannot hang on it. Category *values* are never translated; they are
data. Tests: `ValidateAndProfileTest` (suggestions persisted, incompatible proposals rejected, confirmed
definitions kept, outage fallback), `DefinitionSuggestionTest`; both use `tests/Support/FakeLlmClient`.

**Excel uploads (added 4 October 2026).** `.xlsx` is accepted next to `.csv` (`dataspeak.upload.allowed_extensions`).
`Services/Uploads/SpreadsheetConverter` streams the workbook with OpenSpout and writes the worksheet with the
largest table (data rows x header columns; cover or notes sheets lose) to the same transient `dataset.csv` path, so the analytics service, the contract and every
later stage stay CSV-only. Dates become `Y-m-d` (or `Y-m-d H:i:s`), booleans `true`/`false`, blank rows are
dropped, ragged rows are padded or trimmed to the header width, blank header cells become `column_N` and
duplicates get a numeric suffix. The response carries `meta.source` (`format`, `sheet`, `sheets_total`, `rows`,
`columns`); `source_file_name` keeps the workbook name and `file_sha256` fingerprints the uploaded workbook.
Unreadable or empty workbooks answer 422 `SPREADSHEET_UNREADABLE` / `SPREADSHEET_EMPTY` and the analysis keeps
its previous state. No cell value is logged. Legacy binary `.xls` is not supported (export as .xlsx or CSV).
Tests: `DatasetUploadTest` (conversion fixture built with the OpenSpout writer, rejection paths).

### 6.6 Analytics client and the run pipeline

`AnalyticsServiceClient` wraps Laravel's HTTP client: base URL and token from
`config/services.php` (`ANALYTICS_BASE_URL`, `ANALYTICS_INTERNAL_TOKEN`, `ANALYTICS_TIMEOUT_SECONDS`),
header `X-Internal-Token`, 5 second connect timeout, two retries on connection failures only,
CSV sent as a multipart `file` plus a `metadata` JSON string. Upstream `ANALYTICS_*` codes are mapped
in `AnalyticsServiceException::fromUpstream()`.

`RunAnalysisJob` (queue `redis`, unique per analysis for an hour, one attempt, 600 s timeout):

1. Load analysis; return silently unless it is ANALYZING.
2. Clear previous results (`AnalysisResultPersister::clear`).
3. Stage STATISTICAL_ANALYSIS: `/v1/analyze` with definitions and options; persist evidence
   (progress 10 to 55).
4. Stage INSIGHT_DETECTION: `/v1/insights` with the evidence; persist insights whose primary
   evidence exists (55 to 80).
5. Stage VISUALIZATION: `/v1/visualizations/recommend`; persist chart specs (80 to 100).
6. Transition to ANALYZED, mark the job SUCCEEDED. With `AUTO_REPORT=true` (default) move straight to
   REPORTING and dispatch `GenerateReportJob` with the same job id, so one `POST /run` produces the
   finished report; the frontend follows all six stages and opens the report on COMPLETED.
7. On any exception: fail the open stage, transition to FAILED with a safe code, mark the job FAILED.
8. `finally`: delete the transient file unless the failure was transient.

Progress for `GET /status` comes from `ProgressStore` (cache key
`dataspeak:analysis:{public_id}:job`, one-day TTL, holds job id, status, stage, progress only).

Empty JSON objects: PHP's `json_decode(assoc)` turns `{}` into `[]`. The job restores `parameters`
and `payload` to objects before re-sending evidence, and FastAPI also accepts `[]` for those fields.

### 6.7 Idempotency, rate limits, logging

- `Idempotency-Key` header on `POST /run` and `POST /report`: the first successful response for
  (user, path, key) is cached for `idempotency_ttl_hours` (24) and replayed with
  `Idempotent-Replayed: true`.
- Rate limiters are defined in `AppServiceProvider::boot()`: `api` 120/min, `auth` 5/min per IP,
  `upload` 10/min, `run` 6/min, `report` 6/min per user.
- Logging: the `json` channel (default in containers) writes JSON lines to stderr with
  `PrivacyProcessor`; `single`, `daily`, `stderr`, `errorlog` and `syslog` channels get the same
  processor through `PrivacyTap`. Log only IDs, state names, counts, durations and codes.

### 6.8 Reports and the AI boundary (updated 4 October 2026)

`POST /analyses/{id}/report` is asynchronous: it moves the analysis to REPORTING and dispatches
`GenerateReportJob`, which runs three stages and ends at COMPLETED. See section 6A for the AI layer.
`ReportAssembler` builds the `reports` row from persisted data only: summary metrics from
descriptive evidence, sections (executive summary, findings, limitations, charts), data notes, and
claim counts. Only SUPPORTED claims are referenced. It never computes or invents a value.

## 6A. AI interpretation, claim validation and narrative (MOD-09, MOD-10, MOD-11)

```
persisted evidence + insights
      -> EvidencePayloadBuilder (minimal JSON, trimmed to the context window)
      -> ExecutiveInterpretationPrompt (versioned system + user message, data in <evidence_json>)
      -> LlmClient (OllamaClient: POST /api/chat, stream:false, format:<schema>, think:false)
      -> ClaimOutputSchema::problems() strict parse, one retry with the problems appended
      -> RuleBasedClaimValidator per claim  -> SUPPORTED | NEEDS_REVIEW | REJECTED (+ reason codes)
      -> claims + claim_evidence persisted, SUPPORTED ones in_report
      -> ReportAssembler (ai_status) ; NarrativeRenderer substitutes placeholders at read time
```

**Numbers by reference.** The model never writes a digit. Claim text contains placeholders of the
form `{ev_xxxxxxxxxxxx.field}`; `PlaceholderResolver` builds the per-evidence view those fields read
from (`display_value`, `label`, `unit`, `dimension`, `group`, `period`, `period_from`, `period_to`,
whitelisted in `config/dataspeak.php`). The stored `claims.statement` keeps the placeholder form; the
API renders it (`statement`) and also returns the template (`statement_template`). Digit policy: any
Unicode digit outside a placeholder rejects the claim. No allow-list exists; years and quarters come
through the period placeholders.

**Files.**

| Path | Role |
| --- | --- |
| `app/Services/Ai/Contracts/LlmClient.php` | `chat(messages, schema, options): LlmResult`; swap point for a vLLM client |
| `app/Services/Ai/Clients/OllamaClient.php` | Native Ollama chat API; strips `<think>` blocks; typed `LlmUnavailableException` (AI_UNAVAILABLE, AI_TIMEOUT, AI_HTTP_ERROR) |
| `app/Services/Ai/Schemas/ClaimOutputSchema.php` | JSON schema sent as `format` and the strict hand-written parser |
| `app/Services/Ai/Prompts/ExecutiveInterpretationPrompt.php` | Versioned prompt (`AI_PROMPT_VERSION`, default `exec-interp-v7`; the iteration history and measurements are in `tests/ai-eval/README.md`) |
| `app/Services/Ai/EvidencePayloadBuilder.php` | Evidence and insights to minimal JSON; trims to `OLLAMA_NUM_CTX` minus overhead; records omissions |
| `app/Services/Ai/PlaceholderResolver.php` | Evidence view for prompt, validator and renderer |
| `app/Services/Ai/AiGateway.php` | Orchestration; writes `ai_generations` (model, prompt version, tokens, duration, `request_payload.meta` with temperature, seed, num_ctx, payload hash, attempts) |
| `app/Services/Validation/RuleBasedClaimValidator.php` | Rules 2 to 8 below; `rule_based_v1` |
| `app/Services/Validation/Contracts/ClaimValidator.php` | Interface; `AI_CLAIM_VALIDATOR=nli` resolves to not-configured (Planned, never faked) |
| `app/Services/Reporting/NarrativeRenderer.php` | Placeholder substitution |
| `app/Jobs/GenerateReportJob.php` | Stages AI_SYNTHESIS, CLAIM_VALIDATION, REPORTING |
| `app/Console/Commands/AiEvaluate.php` | `dataspeak:ai-eval <analysis> --runs=N --json` evaluation harness (see `tests/ai-eval/README.md`) |

**Model output schema.** `{ "claims": [Claim...], "limitations": [Claim...] }` where a Claim has
`claim_key`, `claim_kind` (EXECUTIVE_SUMMARY, FINDING or CAVEAT), `text` (10 to 600 characters),
`evidence_ids` (1 to 6 ids) and `confidence_language` fixed to `descriptive`. The executive summary is
returned as claims, not free text, so every sentence is validated and traced.

**Validation rules and reason codes.** Rule 1 (schema) is enforced by the gateway, with one retry.
Per claim: `UNKNOWN_EVIDENCE` and `NO_EVIDENCE_REFERENCE` (reject); `DIGITS_OUTSIDE_PLACEHOLDER`
(reject); `PLACEHOLDER_FIELD_NOT_ALLOWED`, `PLACEHOLDER_UNKNOWN_EVIDENCE`, `PLACEHOLDER_UNRESOLVABLE`
(reject) and `PLACEHOLDER_EVIDENCE_NOT_CITED` (review); `CAUSAL_LANGUAGE:<phrase>` (review, phrase list in
config); `LOW_QUALITY_EVIDENCE_AS_FINDING` (review) and `INSUFFICIENT_EVIDENCE_AS_FINDING` (reject) when a
non-CAVEAT claim rests only on WARNING or INSUFFICIENT evidence; `DIRECTION_CONTRADICTION:<id>` (reject)
when increase or decrease wording contradicts the sign of a directional metric (`latest_period_change`,
`average_period_growth`, `total_change`, `trend_slope`, `top_bottom_mean_difference`). Any reject
reason gives REJECTED; otherwise any review reason gives NEEDS_REVIEW; otherwise SUPPORTED.
`validation_notes` stores the codes joined by ` | `.

**Normalisation by the validator.** Two corrections touch a claim's metadata, never its text, and are
recorded as informational reason codes: `CITATION_COMPLETED:<id>` when a placeholder references known
evidence missing from `evidence_ids`, and `RECLASSIFIED_AS_CAVEAT` when a FINDING or
EXECUTIVE_SUMMARY rests only on quality-family or non-acceptable evidence, or only on dataset-mechanics
metrics such as row count, completeness or duplicates (it is then validated and shown as a limitation). Insight headlines and descriptions are passed to the model with their numbers
replaced by the originating placeholders; text that would still contain a digit is omitted. Context
evidence (shares, differences, growth, correlations) is included even below insight thresholds so the
summary can describe the overall picture.

**Sentences by software, placement by software.** Every evidence item in the payload carries a
`sentence` authored by `EvidenceSentences` with that record's placeholders already in the right slots
(sign-dependent verbs such as rose/fell are chosen from the stored value). The prompt (v6) tells the
model to compose claims from these sentences and only change connecting words. After validation,
`GenerateReportJob::place()` decides where each SUPPORTED statement goes regardless of the model's
label: statements whose evidence describes the same columns as one of the report's charts become
EXECUTIVE_SUMMARY in chart order (one per chart, at most five), other statements about shares, changes or associations become FINDING, plain
descriptive figures become METRIC with `in_report = false` (the KPI cards already show them), and
caveats that only repeat the data notes (record counts, completeness) are kept out of the narrative.
Reason codes `PLACED_BY_SOFTWARE:<kind>` and `NOT_IN_NARRATIVE` record these decisions.

**Per-chart summaries.** `ChartGrounding` links evidence to charts by the columns they describe and is
shared by claim placement and `ReportAssembler`. The assembler stores, per visualization, up to three
summary items: the model's checked statements grounded in that chart (`source: narrative`, with the
claim id) or, when there are none, calculated sentences from `EvidenceSentences` for the chart's own
evidence (`source: calculated`). The report API returns them as `visualizations[].summary`; the page
shows every chart with its reading beneath it, and the executive summary is rendered as one paragraph
across all charts.

**Repair round.** Claims rejected only for repairable reasons (`DIGITS_OUTSIDE_PLACEHOLDER`,
`PLACEHOLDER_FIELD_NOT_ALLOWED`, `PLACEHOLDER_UNRESOLVABLE`, `PLACEHOLDER_UNKNOWN_EVIDENCE`) are sent
back once, with the exact placeholder strings available for the evidence they cite
(`AiGateway::repair`, `ExecutiveInterpretationPrompt::repairMessage`). The model rewrites; software
never edits claim text. A rewrite that validates replaces the original and carries the extra reason
code `REPAIRED`; otherwise the original stays REJECTED. Identical claims returned in both lists are
stored once. Dataset-level figures (row count, completeness, duplicates) reach the model only as
evidence ids, never as raw numbers.

**Fallback.** If the model is unreachable, times out, fails the schema twice, or `AI_ENABLED=false`,
the generation is recorded as FAILED or SKIPPED with an error code, no claims are stored, and the
report is still assembled from deterministic content with `ai_status` set to `unavailable`,
`disabled` or (when every claim was rejected) `no_valid_claims`. The analysis ends at COMPLETED.
Posting to the report endpoint again regenerates the narrative; the previous claims are replaced.
No template prose is ever substituted for the narrative.

**Privacy.** The payload contains evidence ids, metrics, methods, display values, units, business
labels, quality flags, insight headlines and dataset counts. It never contains rows, samples, file
names or user identity. Business names are user text and are placed inside the delimited data block
with a system-prompt rule that nothing in it is an instruction. Prompt bodies are never logged; logs
carry analysis id, model, prompt version, duration, counts and codes. Ollama binds to loopback only.

**Configuration.** `services.ollama`: `OLLAMA_BASE_URL`, `OLLAMA_MODEL`, `OLLAMA_TIMEOUT`,
`OLLAMA_TEMPERATURE` (0.1), `OLLAMA_SEED` (42), `OLLAMA_NUM_CTX` (8192), `OLLAMA_THINK` (false).
`dataspeak.ai`: `AI_ENABLED`, `AI_LLM_DRIVER` (ollama), `AI_PROMPT_VERSION` (exec-interp-v7), `AI_REPORT_LANGUAGE`
(English), `AI_JOB_TIMEOUT_SECONDS` (900), `AI_CLAIM_VALIDATOR` (rule_based), placeholder whitelist,
causal phrase list, directional metrics and direction word lists.

**Tests.** `OllamaClientTest` (request shape, think stripping, malformed JSON, refused, timeout, HTTP
error), `ClaimOutputSchemaTest`, `RuleBasedClaimValidatorTest` (one test per rule, Thai and Myanmar
placeholders), `GenerateReportJobTest` (full pipeline, schema retry, outage fallback, all-rejected,
disabled, prompt injection, sentinel through FastAPI fakes and Ollama fakes into database and logs).
`tests/Feature/Live/OllamaLiveTest.php` is in the `ollama` group, excluded by default; run
`php artisan test --group=ollama` with a live model.

### 6.9 Tests

`php artisan test` runs 62 tests on in-memory SQLite (`phpunit.xml`). `Tests\Support\ApiTestCase`
fakes the `transient` disk, points the analytics client at a fake host and provides `actingAsUser()`,
`csvFile()` (Thai and Myanmar headers plus a sentinel cell value) and `placeTransientFile()`.

Coverage by file: `AuthTest` (register, login, me, logout, throttle), `AnalysisTest` (CRUD, pagination,
404 for other users, ordered delete), `DatasetUploadTest` (file controls, state, re-upload clearing,
log-leak sentinel), `ValidateAndProfileTest` (Http fakes, 422 details, 503, Unicode headers),
`DefinitionsTest`, `RunAndStatusTest` (202, preconditions, idempotency, retry), `RunAnalysisJobTest`
(pipeline success, dataset failure, transient failure keeping the file, no-op), `ReportAndTraceabilityTest`,
`PurgeUploadsCommandTest`, `Unit/AnalysisLifecycleTest`, `Unit/AnalyticsServiceClientTest`
(every upstream code, timeout), `Unit/PrivacyProcessorTest`.

Add a new endpoint by: route in `routes/api.php` with its `*-API-*` ID, Form Request, controller
method using `ownedAnalysis()` and the lifecycle guards, API Resource, feature test covering
200, 401, 404, 409 and 422.

### 6.10 Email verification and PDF export (added 4 October 2026)

**Email verification (FEAT-AUTH-001).** `User` implements `MustVerifyEmail`. Registration creates the
account, issues a token and sends Laravel's `VerifyEmail` notification, re-worded for business users in
`AppServiceProvider` (`VerifyEmail::toMailUsing`). The link is a signed, time-limited API URL
(`VerifyEmail::createUrlUsing`, `VERIFICATION_LINK_MINUTES`, default 60) so it can be clicked from any mail
client; the API marks the address verified and redirects the browser to the React login page with
`?verified=1` (`already` if it was done before, `invalid` for a tampered or expired link). Unverified users
can log in, call `/me` and resend the email, but every analysis route sits behind the `verified.api`
middleware, which answers `403 EMAIL_NOT_VERIFIED` (JSON, never a redirect). Sending is wrapped so a mail
outage never fails registration; the response carries `verification.email_sent` and the user can resend.
`REQUIRE_VERIFIED_EMAIL=false` switches the gate off for closed environments. Mail settings live in
`config/mail.php` (`MAIL_*`); compose points them at the **mailpit** service (SMTP `mailpit:1025`, UI
http://127.0.0.1:8025, loopback only). Natively, `MAIL_MAILER=log` writes the email, link included, to
`storage/logs/laravel.log`. Tests: `EmailVerificationTest` (notification sent, 403 gate, signed link accept
and reject paths, resend, switch-off).

**PDF export (FEAT-RPT-003, RPT-API-003).** `ReportPdfController` → `Services/Reports/Pdf/ReportPdfBuilder`
renders `resources/views/pdf/report.blade.php` with dompdf (`barryvdh/laravel-dompdf`, PHP GD required;
the backend image installs it). The PDF is built from the same `ReportResource` data the web report shows,
so nothing is recomputed: cover page (Data Speak logo, analysis name, created and generated timestamps,
user name and email, source file, record count), a fixed letterhead with the logo on every page, and a
footer written with `page_text` ("Generated <timestamp> · Data Speak" left, "Page N of M" right). Body:
key figures, executive summary, every chart with "What this chart shows", other findings, data notes in the
same business wording as the frontend (`PdfWording` mirrors `utils/evidenceWording.ts`), and a "How we
know" table of the evidence behind each statement. Charts are drawn by `ChartSvg` from the stored
visualization series (bar, line, histogram; tables as HTML) and embedded as SVG data URIs, which is the
only SVG form dompdf renders. Fonts: Noto Sans, Noto Sans Thai and Noto Sans Myanmar are bundled in
`resources/pdf/fonts` and declared with `@font-face`; dompdf cannot fall back per glyph, so `ScriptSpans`
wraps Thai and Myanmar runs in spans that select those fonts. The SVG renderer inside dompdf only has the
Latin core fonts, so bar charts whose category names are non-Latin or longer than 16 characters are drawn as
horizontal HTML bars (`Pdf/ChartHtml`), where dompdf typesets each label in the right font; other charts keep
the SVG drawing, with a numbered key only as a last resort. The font cache is `storage/fonts` (gitignored). The frontend's `ExportPdfAction` fetches the file
with the bearer token (`apiClient.download`) and triggers a browser download named
`DataSpeak-<analysis>-<timestamp>.pdf`. Tests: `ChartSvgTest`, `ReportPdfTest` (content type, `%PDF`
magic, more than one page, 409 before completion, 404 for other users). Set `PDF_DUMP=/path/file.pdf`
when running `ReportPdfTest` to keep a copy for eyeballing.

**Interrupted jobs (NFR-REL-001).** Two guards keep an analysis from spinning forever when its queue job dies.
First, `config/queue.php` sets the Redis `retry_after` to 960 s, above the 900 s AI job timeout; the old 90 s
default made Redis redeliver a still-running report job, the duplicate failed with `MaxAttemptsExceeded` and
the analysis stayed in REPORTING. Second, `Services/Lifecycle/StaleJobReaper` marks a processing analysis
FAILED with `JOB_INTERRUPTED` when its RUNNING stage is older than job timeout plus
`STALE_JOB_GRACE_SECONDS` (120). It runs from the status endpoint (so the polling UI unblocks itself), from the
jobs' `failed()` hooks (hard timeouts, redeliveries) and from `dataspeak:reap-stale-jobs`, scheduled every
minute (`--force --analysis=<id>` for immediate operator recovery). A report-phase failure keeps the evidence,
so `AnalysisLifecycle::canBuildReport()` lets `POST /report` run again from FAILED; the detail resource exposes
`can_rebuild_report` and the Analyze page shows **Build the report again** instead of asking for a re-upload.
Compose gives the worker `stop_grace_period: 120s` so an ordinary restart lets the current job finish. Tests:
`StaleJobTest`.

## 7. Analytics service (FastAPI)

### 7.1 Structure

```
analytics/app
  main.py                      create_app(): logging, error handlers, routers
  core/config.py               Settings from env (ANALYTICS_INTERNAL_TOKEN, DATASPEAK_TMP_DIR, MAX_UPLOAD_MB, LOG_LEVEL)
  core/security.py             require_internal_token dependency (X-Internal-Token, constant-time compare)
  core/errors.py               AnalyticsError + handlers -> {"error": {...}}; validation errors never echo values
  core/logging.py              JSON lines, RedactFilter, allow-listed extra keys
  api/health.py                PY-API-001 (unauthenticated)
  api/v1/deps.py               metadata parsing, size-limited spooling into a per-request temp dir
  api/v1/{validate,profile,analyze,insights,visualizations}.py
  schemas/common.py            FieldDefinition, Evidence, Quality, Finding, ErrorResponse (extra="forbid")
  schemas/requests.py          DatasetMetadata, AnalysisOptions, InsightsRequest, VisualizationRequest
  schemas/responses.py         one response model per endpoint
  services/validation/         csv_reader.py (BOM, encodings, delimiter sniff, text-first parse), validator.py
  services/profiling/          inference.py (type inference), profiler.py (derived metadata only)
  services/analysis/           rules.py, roles.py, evidence.py, descriptive.py, trend.py, groups.py,
                               association.py, quality.py, engine.py
  services/insights/           detector.py (rules + scoring), wording.py (neutral templates)
  services/visualization/      recommender.py
  utils/tempfiles.py           request_tempdir() context manager (removed in finally)
analytics/scripts/export_openapi.py   writes docs/contracts/fastapi-openapi.json
analytics/tests/{unit,golden,contract}
```

Python 3.9 compatible syntax is used throughout (the local venv is 3.9; the Docker image is 3.12).

### 7.2 Request handling

Dataset endpoints take multipart `file` plus a `metadata` form field containing JSON
(`analysis_id`, optional `definitions`, optional `options`). The upload is streamed into a
per-request directory under `DATASPEAK_TMP_DIR` with a hard byte limit (413
`ANALYTICS_PAYLOAD_TOO_LARGE`), parsed, processed, and the directory is removed in `finally` on
success and on failure. `/v1/insights` and `/v1/visualizations/recommend` take JSON bodies of derived
evidence and never a file. Every `/v1/*` route requires the internal token (401
`ANALYTICS_UNAUTHORIZED`); `/health` does not.

### 7.3 CSV reading and validation

`csv_reader.read_csv_bytes()` strips a UTF-8 BOM, decodes UTF-8 (UTF-16 with BOM, latin-1 as a
flagged last resort), sniffs the delimiter among `, ; tab |`, reads **every column as text** with
Polars (`truncate_ragged_lines`), normalises null tokens (`NA`, `null`, `-`, empty) and header
whitespace, and records duplicate and empty headers. Reading everything as text keeps type inference
explicit and testable.

`validator.validate_dataset()` errors: `NO_HEADER`, `EMPTY_COLUMN_NAME`, `DUPLICATE_COLUMN`,
`NO_DATA_ROWS`. Warnings: `ENCODING_FALLBACK`, `DUPLICATE_ROWS`, `RAGGED_ROWS`, `HIGH_NULL_RATE`,
`MOSTLY_EMPTY_COLUMN`, `CONSTANT_COLUMN`. Structural failures return 200 with `valid:false`; an
unparseable or empty file returns 422 `ANALYTICS_INVALID_DATASET`.

### 7.4 Type inference (profiling/inference.py)

Order per column, using thresholds from `rules.py`: numeric or integer (at least 95% of non-null
values parse after removing thousands separators, one leading currency symbol and a trailing `%`;
letters are never stripped, so `C01000` stays text), boolean (two values from yes/no/true/false/y/n/
1/0), date then datetime (first format with at least 95% parse success), category (at most 20
distinct values that repeat, or at most 5 values in total, or distinct ratio at most 5% up to 100
values), identifier (at least 20 rows and at least 95% unique), otherwise text. Definitions override
inference at analysis time.

Profiling returns row and column counts, completeness, duplicate rows, and per column the inferred
type, null count and rate, distinct count and type-appropriate stats: numeric min/max/mean/median/
p25/p75/std, date min/max/span, boolean true rate, category `top_share` and `top_count` (counts
only, no labels), text and identifier nothing. Quality findings: `HIGH_NULL_RATE` (10%+),
`MOSTLY_EMPTY_COLUMN` (90%+), `CONSTANT_COLUMN`, `MIXED_TYPES`, `DUPLICATE_ROWS` (1%+).

### 7.5 Column roles (analysis/roles.py)

Each column becomes a measure, dimension, time axis or is excluded. A definition wins: NUMBER,
CURRENCY, PERCENTAGE are measures (only if the values are numeric); DATE is time; CATEGORY and
BOOLEAN are dimensions (at most 20 groups); TEXT, IDENTIFIER and IGNORE are excluded. Without a
definition, the inferred type decides the same way. `ColumnRole.sums` is true for SUM or COUNT
aggregation, or for CURRENCY without a stated aggregation; it decides whether totals or means are the
meaningful aggregate. Labels and units come from definitions and are never guessed.

### 7.6 Analysis families and method selection (analysis/rules.py)

| Family | Preconditions | Methods and evidence metrics |
| --- | --- | --- |
| descriptive | any rows; up to 8 measures, 5 dimensions | `row_count`; per measure `count`, `sum` (when sums), `mean`, `median`, `min`, `max`, `std` (3+ rows), `distribution` (p25, p75, 10 equal-width histogram bins); per dimension `distinct_count`, `top_category_share` with group counts |
| trend | one time column and a measure; at least 6 periods | granularity preference month, quarter, week, year, day within 6 to 60 periods; `period_series` (sum or mean per period), `latest_period_change`, `average_period_growth` (geometric mean of ratios when all positive), `total_change`, `trend_slope` (statsmodels OLS, 8+ periods, p-value and R squared in payload) |
| group_comparison | dimension with 2 to 20 groups and a measure | `group_aggregates` (count, sum, mean, share per group), `top_group_share` (when sums), `top_bottom_mean_difference` (relative) |
| statistical_test | groups with at least 5 rows each | two groups: Welch t-test at 30+ per group, else Mann-Whitney U at 8+; three or more: Levene then one-way ANOVA at 30+ per group when variances are homogeneous, else Kruskal-Wallis at 8+; effect sizes Cohen's d, rank-biserial, eta squared, epsilon squared; otherwise `none_applicable` with INSUFFICIENT quality |
| association | numeric pairs with 30+ complete rows (strongest 10 by Spearman kept); dimension pairs with at least 80% of expected cell counts at 5 or more | `spearman_correlation` (Pearson also in payload), `cramers_v` (chi-square) |
| quality | always | `completeness`, `duplicate_rows`, `null_rate` per column with nulls, `constant_column`, `type_parse_failures`, `outlier_count` (IQR fence, k = 1.5) |

When a family's preconditions fail, `engine.analyze_frame()` records it in `summary.skipped` and
individual metrics emit an evidence item with `quality.status = "insufficient"` and a reason in
`quality.notes` instead of a weak number. Quality is `warning` when the measure has 10% or more
missing values or a group has fewer than 5 rows.

### 7.7 Evidence model

`evidence.make_evidence()` builds every record. The identifier is deterministic:
`"ev_" + sha256(metric | method | sorted source columns | sorted parameters)[:12]`, so the same input
and configuration reproduce the same ids, and the determinism test can compare whole payloads.
`value` keeps full precision; `display_value` is formatted (`fmt_number`, `fmt_pct`, `fmt_p`) with
the unit from definitions. `payload` holds aggregates only (period series, group aggregates, test
statistics, histogram bins). Category labels appear only for dimension columns, never for TEXT or
IDENTIFIER columns.

### 7.8 Insight detection and ranking (insights/detector.py)

Rules over evidence (thresholds in `rules.py`):

| Insight type | Trigger |
| --- | --- |
| trend_change | `average_period_growth` magnitude at least 5%, or `latest_period_change` at least 10% |
| concentration | `top_group_share` at least 40% |
| group_difference | `top_bottom_mean_difference` at least 20%; support from the matching statistical test's p-value |
| association | Spearman magnitude at least 0.5, or Cramér's V at least 0.3 |
| data_quality | `null_rate` at least 10%, or duplicate share at least 1% |
| distribution | outlier share at least 5% |

Score = 0.5 x magnitude + 0.3 x support + 0.2 x quality. Support is 1.0 for p < 0.01, 0.8 for
p < 0.05, 0.2 for a non-significant test, 0.5 when no test applied. Quality is 1.0 for ACCEPTABLE,
0.6 for WARNING; INSUFFICIENT evidence never produces an insight. Near-duplicates are collapsed on
(type, source columns), output is capped at `max_insights` (default 10, hard cap 10), ranks are
assigned by descending score with deterministic tie-breaks. Wording comes from
`insights/wording.py`; `CAUSAL_WORDS` is asserted absent in tests.

### 7.9 Visualization recommendation (visualization/recommender.py)

Chart specs only, no rendering. Trend insights become `line` charts from `period_series`;
concentration and group difference become `bar` charts from `group_aggregates` (sums or means);
distribution becomes a `histogram` from the histogram bins; numeric association becomes a `table`
of statistics (no scatter, because raw points would be raw rows). Remaining slots (8 maximum) are
filled with the strongest un-charted series, groups and distributions, plus a "Missing values by
column" bar when any column has nulls. Units come from definitions; `unit_missing` is true when none
is known. Series lengths are always far below the row count.

### 7.10 Tests and the frozen contract

`pytest` runs 41 tests: CSV reader edge cases (BOM, delimiters, empty, header only, duplicates,
ragged rows, null tokens), inference and profiling (including no labels in category stats), rules
and test selection, golden datasets (`tests/golden/tiny.csv` with hand-checkable values,
`tests/golden/retail_sales.csv` cross-checked against Python's `statistics` module), determinism,
insight rules and causal-word guard, visualization units, endpoint round trip, temp cleanup on
success and failure, payload limit, log-leak sentinel, strict request models, and the OpenAPI
contract. After changing any schema, run `python -m scripts.export_openapi` and review the diff of
`docs/contracts/fastapi-openapi.json`; the contract test fails until the file is regenerated.

Add a new analysis family by: a module in `services/analysis/` exposing `run(df, roles)`, constants
in `rules.py`, registration in `engine.py` with an explicit precondition and skip reason, a detector
rule if it should produce insights, a golden test with independently computed expectations.

## 8. Laravel to FastAPI contract

The complete request and response shapes are in
[contracts/analytics-contract.md](contracts/analytics-contract.md); the machine-readable version is
`contracts/fastapi-openapi.json`. Key points: `X-Internal-Token` on every `/v1/*` call; dataset
endpoints are multipart with a JSON `metadata` string; evidence objects are passed back unchanged
from analyze to insights and visualizations; lowercase enum strings from FastAPI are upper-cased by
Laravel; empty objects may arrive as `[]`.

## 9. Frontend (React + TypeScript)

Production implementation of the Frontend Program List (FE-* ids are in a top-of-file comment in
every program). Stack: Vite, React 19, TypeScript strict, Tailwind CSS v4, React Router 7,
TanStack Query 5, Recharts, lucide-react. The Figma Make export is kept untouched in
`frontend/prototype-reference/` for visual reference and is excluded from tooling.

```
frontend/src
  app/            App (providers + router), routes.tsx (route map), guards.tsx (RequireAuth, RedirectIfAuthenticated)
  pages/          FE-PG-001..010: Login, Register, AnalysisDashboard, NewAnalysis, DataProfile, DataDefinition, AnalysisRun, Report (+ claim route), VerifyEmail
  components/
    layout/       AppShell, Sidebar, AnalysisStepper, PageHeader, StatusBadge, LoadingState, ErrorState, EmptyState, ConfirmDialog, Button
    upload/       CsvDropzone, AnalysisNameField, UploadValidationSummary, PrivacyNotice, SampleDatasetAction
    profile/      DatasetOverviewCards, ColumnProfileTable, QualityIssueList, ProfileContinueAction
    define/       FieldDefinitionTable, SemanticTypeSelect, DefinitionValidation, DefinitionSaveAction
    analyze/      AnalysisStartAction, AnalysisProgress (aria-live), AnalysisStatusPoller, AnalysisFailurePanel
    report/       ExecutiveSummary, FindingCard, SummaryMetricCard, ChartRenderer, ClaimLink, TraceabilityPanel, ExportPdfAction
  services/       apiClient.ts (FE-SVC-001) + auth, analysis, dataset, profile, definition, report services
  hooks/          useAuth (FE-HOOK-002), useAnalysisStatus (FE-HOOK-001), useAnalyses (query keys and mutations)
  types/          analysis.types.ts, dataset.types.ts, report.types.ts, api.types.ts (mirror the Laravel resources exactly)
  utils/          statusLabels.ts (the only enum-to-label map), steps.ts (stepper gating), format.ts, naming.ts, files.ts, quality.ts, profileSummary.ts
  lib/            session.ts (token storage), logger.ts (privacy-safe logging), cn.ts
  config/         env.ts (VITE_API_BASE_URL only), queryClient.ts
  test/           Vitest setup, MSW handlers (in-memory fake of the Laravel contract), integration, privacy and axe tests
```

Behaviour rules that are enforced in code and tests:

- **One upstream.** `apiClient.ts` reads `VITE_API_BASE_URL` and nothing else; there is no FastAPI
  URL anywhere in the bundle (privacy test). Errors are normalised to `ApiError` with status, code and
  field details; 401 clears the session and the auth guard redirects to login; 409 messages are shown
  verbatim because they explain the lifecycle conflict; 422 maps to field-level messages.
- **Token storage.** The Sanctum token lives in memory and is mirrored to `sessionStorage` only
  (`lib/session.ts`, the single file allowed to touch storage by ESLint). It is cleared when the tab
  closes. Query data is never persisted. The CSV `File` object lives in component state until upload
  and its contents are never read in the browser.
- **Polling.** `useAnalysisStatus` polls every `poll_seconds` (2 s) while processing, stops on COMPLETED
  and FAILED, on the step's own end state (ANALYZED for the analyze page), on unmount, and pauses while
  the tab is hidden. `AnalysisProgress` announces stage changes through a polite live region, not
  every tick.
- **Stepper gating.** `utils/steps.ts` derives the furthest allowed step from the backend status; pages
  use `useAnalysisGuard(step)` to redirect when a step is not yet allowed. CREATED and UPLOADED go to
  `/analyses/new?id=` to (re)upload.
- **Labels.** `utils/statusLabels.ts` maps every dictionary status, stage, inferred type, semantic type,
  insight type, quality status, claim status and validation reason code to business wording. Raw
  enums are never rendered (tested for all values).
- **Charts.** `ChartRenderer` draws the backend spec (LINE, BAR, HISTOGRAM; TABLE and unknown types fall
  back to a table) with units from the spec and a "Unit not defined" badge when `unit_missing` is
  true. Nothing is computed client-side.
- **Business wording.** `utils/evidenceWording.ts` maps metric and method names to plain-language
  labels for the traceability panel and data notes; raw identifiers, the model name and the
  placeholder template appear only under a collapsed Technical details section.
- **Report.** Generation is asynchronous: the page posts, then polls until COMPLETED. Only
  SUPPORTED claims arrive from the API and only those are rendered; the executive summary shows the
  `ai.status` note when the narrative is unavailable. `ExportPdfAction` is disabled with a
  coming-soon state.

Commands: `npm run dev`, `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm test`,
`npm run build`. Tests use MSW handlers under `src/test/msw/`, which are never bundled.

## 10. CI

`.github/workflows/ci.yml` runs on pushes to `main` and `develop` and on pull requests:

- frontend: `npm ci`, lint, format check, typecheck, test, build (Node 24).
- backend: composer install, `pint --test`, `php artisan test` (PHP 8.4, SQLite).
- analytics: `pip install -r requirements-dev.txt`, `ruff check`, `ruff format --check`, `pytest`
  (Python 3.12, `ANALYTICS_INTERNAL_TOKEN` set).
- compose-config: `docker compose config` validates the stack definition.

## 11. Conventions

- Identifiers: [conventions/identifiers.md](conventions/identifiers.md). Put the ID in the
  docblock or top-of-file comment; use it in test names (`test_py_api_001_health_contract`).
- Logging and privacy: [conventions/logging-and-privacy.md](conventions/logging-and-privacy.md).
  Structured JSON, allow-listed fields, no bodies, no cell values, transient storage rules.
- Code style: Pint (laravel preset), Ruff (line length 120, Python 3.9 target), Prettier (single
  quotes, 100 columns).
- Secrets only from environment. Every service has a committed `.env.example` and no committed `.env`.

## 12. Known gaps and decisions to revisit

- The data dictionary is a draft written by the implementation team, not a stakeholder document.
  Review the Decisions sheet; in particular the semantic type vocabulary, the `claim_evidence` bridge,
  and the public UUID convention.
- Category labels appear in evidence payloads and chart series for dimension columns (defined or
  inferred category, at most 20 values). Text and identifier values never appear. Confirm this policy.
- After a transient analytics outage the upload is kept for retry until the TTL; dataset errors
  delete it immediately.
- `NFR-SEC-001` is used for two requirements in the source documents; the repository tracks the
  "FastAPI internal only" one as `NFR-SEC-001b`.
- `docs/traceability/feature_matrix.md` predates the implementation and must be re-audited.
- Production deployment (HTTPS, managed Postgres, a worker process manager, object storage policy
  for transient uploads on multi-host setups) is not covered by the compose file.
- The NLI claim validator is an interface slot only; validation is rule-based. A vLLM client is not written yet (the `LlmClient` interface is the seam).

## 13. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Backend container exits with `Class "Laravel\Sanctum\Sanctum" not found` or similar | Stale anonymous vendor volume. `docker compose up -d --force-recreate -V backend backend-worker`. |
| `/api/v1/health` says database or redis `down` inside Docker | The web process is not seeing container env. Make sure the backend command is the `php -S` one from `docker-compose.yml`, not `artisan serve`. |
| Run stays ANALYZING forever | No worker, or the worker is running old code. `docker compose logs backend-worker`, then `docker compose restart backend-worker`. |
| Analytics returns `ANALYTICS_INTERNAL_ERROR` on every dataset call | Scratch directory not writable. The tmpfs must be mounted with `mode=1777`; check `docker compose exec analytics touch /tmp/dataspeak/x`. |
| `docker compose up` or `docker start` hangs while `docker ps` works | Docker Desktop engine wedged. Quit Docker Desktop; if processes remain, `pkill -f com.docker.backend` and relaunch. Volumes survive. |
| `php artisan test` says `Could not open input file: vendor/phpunit/phpunit/phpunit` | Run it from the `backend/` directory. |
| Contract test fails after a schema change | Regenerate: `python -m scripts.export_openapi`, review the diff, commit it. |
| Upload rejected as not CSV | The MIME list in `config/dataspeak.php` must include `text/plain`; the file must have a `.csv` extension and be non-empty. |
