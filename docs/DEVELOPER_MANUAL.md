# Data Speak Developer Manual

Version 2.0, 5 October 2026. This manual lists every program in the three codebases with its functions and what
each one does, the integration APIs between them, and how to set up the AI model server. It is generated from the
code as it stands on this date; file paths are relative to the repository root.

Companion documents: [GETTING_STARTED.md](GETTING_STARTED.md) (install and run),
[ARCHITECTURE_NOTES.md](ARCHITECTURE_NOTES.md) (architecture, lifecycle, AI boundary, dated change log),
[TESTING_GUIDE.md](TESTING_GUIDE.md), [DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md) (production on AWS), the data dictionary (`Data_Speak_Data_Dictionary.xlsx`, generated from
`database/build_data_dictionary.py`) and the frozen FastAPI contract in `contracts/`.

**Product rules that every program follows.** Software calculates, the AI interprets, validation verifies. Raw
customer data is transient: no program logs, persists or displays raw rows. The browser talks only to the Laravel
API; the analytics service and the model server are internal. Data-dictionary enumerations are authoritative. No
narrative is ever invented: every number in a report comes from calculated evidence through a placeholder.

**Contents**

1. [Frontend (React + TypeScript)](#1-frontend-react--typescript)
2. [Backend (PHP Laravel)](#2-backend-php-laravel)
   2.1 Program list, 2.2 Integration API list with the analytics FastAPI, 2.3 Integration API list with Ollama
3. [Analyst service (FastAPI)](#3-analyst-service-fastapi)
   3.1 API list, 3.2 Integration with Ollama
4. [Ollama set-up](#4-ollama-set-up)
5. [Appendix: identifiers, known gaps](#5-appendix)

---

## 1. Frontend (React + TypeScript)

Stack: React 19, Vite 8, TypeScript 5.7 (strict), Tailwind CSS 4, React Router 7, TanStack Query 5, Recharts 3,
lucide-react icons. Source in `frontend/src`. Scripts: `npm run dev` (port 5173), `lint`, `format:check`,
`typecheck`, `test` (Vitest + MSW), `build`.

Enforced rules: the browser calls only `VITE_API_BASE_URL` (default `http://localhost:8000/api/v1`); raw CSV
content never reaches `console` or browser storage; the Sanctum token lives in memory, mirrored to
`sessionStorage` only; backend enum values are never rendered (label tables in `src/utils`); charts draw the
backend specification and compute nothing; only backend-validated claims are shown.

### 1.1 Application shell and routing

| Program | File | Functions | Explanation |
|---|---|---|---|
| App | `src/app/App.tsx`, `src/main.tsx` | `App({initialEntries?, queryClient?})` | Provider tree: `I18nProvider` → `QueryClientProvider` → router (`MemoryRouter` in tests, `BrowserRouter` otherwise) → `AuthProvider` → `useRoutes(routes)`. |
| Routes | `src/app/routes.tsx` | route table | See the table below. `AppShell` is the layout route for every analysis page. |
| Guards | `src/app/guards.tsx` | `RequireAuth`, `RequireVerified`, `RedirectIfAuthenticated` | Redirect to `/login` (keeping `state.from`), to `/verify-email` for an unverified user, or to `/analyses` for a logged-in user on the auth pages. Show `LoadingState` while the session is restored. |
| Query client | `src/config/queryClient.ts` | `createQueryClient()` | No retry on 401/403/404/409/422, otherwise two retries; `staleTime` 10 s; no refetch on window focus; mutations never retry. |
| Environment | `src/config/env.ts` | `env.apiBaseUrl`, `env.isDev` | Reads `VITE_API_BASE_URL`. |

Route table:

| Path | Page | Guards | Step guard |
|---|---|---|---|
| `/login` | LoginPage (FE-PG-001) | RedirectIfAuthenticated | |
| `/register` | RegisterPage (FE-PG-002) | RedirectIfAuthenticated | |
| `/verify-email` | VerifyEmailPage (FE-PG-010) | RequireAuth | |
| `/` | redirect to `/analyses` | RequireAuth, RequireVerified, AppShell | |
| `/analyses` | AnalysisDashboardPage (FE-PG-003) | same | |
| `/analyses/new` | NewAnalysisPage (FE-PG-004) | same | supports `?id=` for re-upload |
| `/analyses/:id/profile` | DataProfilePage (FE-PG-005) | same | `useAnalysisGuard(2)` |
| `/analyses/:id/define` | DataDefinitionPage (FE-PG-006) | same | `useAnalysisGuard(3)` |
| `/analyses/:id/analyze` | AnalysisRunPage (FE-PG-007) | same | `useAnalysisGuard(4)` |
| `/analyses/:id/report` | ReportPage (FE-PG-008) | same | `useAnalysisGuard(5)` |
| `/analyses/:id/report/claims/:claimId` | ReportPage, traceability panel (FE-PG-009) | same | `useAnalysisGuard(5)` |
| `*` | redirect to `/analyses` | | |

Step guard: `useAnalysisGuard(step)` (`src/pages/useAnalysisGuard.ts`) loads the analysis and, when the requested
step is beyond `furthestStep(status)`, replaces the URL with `stepPath(id, allowed)`. `furthestStep` maps
CREATED/UPLOADED → 1, VALIDATED → 2, PROFILED → 3, DEFINED/ANALYZING/ANALYZED/FAILED → 4 (chart review happens on
the Analyze step), REPORTING/COMPLETED → 5.

### 1.2 Pages (`src/pages`)

| Id | Program | Functions and behaviour | Uses |
|---|---|---|---|
| FE-PG-001 | `LoginPage.tsx` | Email and password form. 401 shows "The email or password is incorrect"; 422 maps field errors. Shows a banner for `?verified=1`, `already` or `invalid`. On success navigates to `state.from` or `/analyses`. | `useAuth().login`, `describeError`, AuthForm pieces |
| FE-PG-002 | `RegisterPage.tsx` | Name, email, password, confirmation. Client checks (password ≥ 8, match). Calls `register`, then goes to `/verify-email`. | `useAuth().register` |
| FE-PG-010 | `VerifyEmailPage.tsx` | "I have confirmed my email" re-reads `/me` and continues once verified; "Send the email again" calls the resend endpoint (special messages for 429 and 503); logout link for a wrong address; redirects at once if already verified. | `useAuth`, `authService.resendVerification` |
| FE-PG-003 | `AnalysisDashboardPage.tsx` | Paginated table of the user's analyses (name, file, status badge, rows, created). Continue (to `continuePath`) or Report button, Delete with `ConfirmDialog`, `EmptyState` when empty. | `useAnalysesList`, `useDeleteAnalysis` |
| FE-PG-004 | `NewAnalysisPage.tsx` | Create → upload CSV/XLSX → validate, phases idle/creating/uploading/validating/done. `?id=` re-uploads to an existing analysis. Name auto-filled from the file name. Shows which Excel sheet was used. A 422 `CSV_VALIDATION_FAILED` becomes an invalid validation summary. | `analysisService.create`, `datasetService.upload/validate`, upload components |
| FE-PG-005 | `DataProfilePage.tsx` | When VALIDATED starts the profile job once and polls (`AnalysisStatusPoller`, mode `profile`, stop at PROFILED), with friendly texts for a failed job. Then overview cards, column table, quality issues, continue action. | `useProfile`, `profileService.run`, `deriveIssues` |
| FE-PG-006 | `DataDefinitionPage.tsx` | Edits field definitions, prefilled from the profile (`initialDefinitions`). "Suggest again" calls DEF-API-002 and replaces only untouched or non-Latin rows; runs once automatically when a name is still non-Latin. Save validates, PUTs, invalidates caches, navigates to Analyze. | `definitionService.save/suggestNames`, DefinitionValidation |
| FE-PG-007 | `AnalysisRunPage.tsx` | DEFINED: start action "Analyze and recommend charts". Processing: poller (mode `run`, or `report`). ANALYZED: `ChartReviewPanel` with "Write the executive summary". Just completed: `CompletionCelebration`. FAILED: `AnalysisFailurePanel` (retry or rebuild the report). Completed: Review charts, Run again, Open the report. | `useRunAnalysis`, `useGenerateReport` |
| FE-PG-008/009 | `ReportPage.tsx` | Generate button when ANALYZED, poller while REPORTING, failure panel when FAILED. When COMPLETED: summary metric cards, executive summary, one `ChartSummaryCard` per chart (with the report's colour theme), "Also worth knowing", "Data notes and limitations", verification count, Rewrite summary, Export PDF. Language follow-up: when the interface language differs from the report language and the translation is incomplete it calls translate or polls (2 s for 10 tries, then 5 s, up to 90). `:claimId` opens `TraceabilityPanel`. | `useReport`, `useClaim`, `reportService.translate`, `ExportPdfAction` |
| | `AuthForm.tsx` | `AuthCard`, `TextField`, `PasswordField`, `FormError` shared by the auth pages. | |

### 1.3 Components (`src/components`)

Layout:

| Id | Program | Props | Explanation |
|---|---|---|---|
| FE-CMP-001 | `layout/AppShell.tsx` | | Authenticated layout: skip link, `Sidebar`, mobile top bar, `<main id="main">` with the routed page. |
| FE-CMP-002 | `layout/Sidebar.tsx` | `open, onClose` | Navigation (Analyses, New analysis), `LanguageSwitcher`, user menu with Log out. |
| FE-CMP-003 | `layout/AnalysisStepper.tsx` | `current, analysisId?, status?` | Five-step navigation; a step is clickable only when allowed by `furthestStep`. |
| FE-CMP-004 | `layout/PageHeader.tsx` | `title, subtitle?, actions?` | Page title block. |
| FE-CMP-005 | `layout/StatusBadge.tsx` | `status` | Translated status label with a tone colour; the enum is never shown. |
| FE-CMP-006 | `layout/LoadingState.tsx` | `label?` | `role="status"` spinner. |
| FE-CMP-007 | `layout/ErrorState.tsx` | `error, onRetry?, title?` | `role="alert"` box with `describeError`, the error code as a reference, optional retry. |
| FE-CMP-008 | `layout/ConfirmDialog.tsx` | `open, title, body, confirmLabel?, danger?, busy?, onConfirm, onCancel` | Native `<dialog>` confirmation. |
| FE-CMP-009 | `layout/LanguageSwitcher.tsx` | `compact?` | Interface language select (English, Thai, Chinese, Burmese). |
| | `layout/Button.tsx`, `layout/EmptyState.tsx` | | Shared button (variants, loading) and empty-list message. |

Upload, profile and define:

| Id | Program | Explanation |
|---|---|---|
| FE-UPL-001 | `upload/CsvDropzone.tsx` | Drag-and-drop or picker for `.csv`/`.xlsx`, checked by `acceptFile`; never reads the file contents. |
| FE-UPL-002 | `upload/AnalysisNameField.tsx` | Analysis name input (max 255). |
| FE-UPL-003 | `upload/UploadValidationSummary.tsx` | Valid/invalid header, counts, error and warning lists from DATA-API-002. |
| FE-UPL-004 | `upload/PrivacyNotice.tsx` | Static notice on transient handling of raw data. |
| FE-UPL-005 | `upload/SampleDatasetAction.tsx` | Loads `/samples/retail_sales_sample.csv` as a `File`. |
| FE-PRO-001 | `profile/DatasetOverviewCards.tsx` | Rows, Columns, Completeness, Quality notes cards. |
| FE-PRO-002 | `profile/ColumnProfileTable.tsx` | Column, suggested meaning (AI or software), detected type, missing %, summary text. |
| FE-PRO-003 | `profile/QualityIssueList.tsx` | Warning and insufficient items. |
| FE-PRO-004 | `profile/ProfileContinueAction.tsx` | "Continue to definitions", with a warning when blocking issues exist. |
| FE-DEF-001 | `define/FieldDefinitionTable.tsx` | Per-row business name, semantic type, unit, aggregation; marks suggested rows "check it". |
| FE-DEF-002 | `define/SemanticTypeSelect.tsx` | Select over `SEMANTIC_TYPES` with translated labels. |
| FE-DEF-003 | `define/DefinitionValidation.tsx` | Functions `suggestedType`, `initialDefinitions`, `validateDefinitions` (Latin business name required, CURRENCY needs a unit, unit ≤ 32, at least one measure), `isMeasure`. |
| FE-DEF-004 | `define/DefinitionSaveAction.tsx` | "Save and continue" with form-level error. |

Analyze and report:

| Id | Program | Explanation |
|---|---|---|
| FE-ANL-001 | `analyze/AnalysisStartAction.tsx` | Start button. |
| FE-ANL-002 | `analyze/AnalysisProgress.tsx` | Stage list per mode (profile: PROFILING, DEFINITION; run: STATISTICAL_ANALYSIS, INSIGHT_DETECTION, VISUALIZATION; report: AI_SYNTHESIS, CLAIM_VALIDATION, REPORTING), overall progress bar, live region for stage changes. |
| FE-ANL-003 | `analyze/AnalysisStatusPoller.tsx` | Wraps `useAnalysisStatus`; calls `onStopped` once per job; ignores data cached before it mounted. |
| FE-ANL-004 | `analyze/AnalysisFailurePanel.tsx` | Friendly message per error code (ANALYTICS_UNAVAILABLE, ANALYTICS_INSUFFICIENT_DATA, ANALYTICS_INVALID_DATASET, ANALYTICS_UNSUPPORTED_TYPE, REPORT_GENERATION_FAILED, JOB_INTERRUPTED) with Retry, Build the report again, Upload again, Review definitions. |
| FE-ANL-005 | `analyze/ChartReviewPanel.tsx` | Colour theme picker (six palettes). Two tabs: **Recommended charts** (keep/untick with previews) and **Custom required charts** (add any chart from VIS-API-002 with a data preview, remove, plus custom charts saved earlier). Confirm saves `{selected, add, theme}` through VIS-API-003 and starts the report. |
| | `analyze/CompletionCelebration.tsx` | "All done" with an automatic continue. |
| FE-RPT-001 | `report/ExecutiveSummary.tsx` | Renders SUPPORTED EXECUTIVE_SUMMARY claims with numbered "How we know" links; notes for `no_valid_claims`, `unavailable`, `disabled`. |
| FE-RPT-003 | `report/SummaryMetricCard.tsx` | KPI card (label, display value, unit). |
| FE-RPT-004 | `report/ChartRenderer.tsx` | Recharts rendering of the backend specification: LINE (first theme colour), BAR/HISTOGRAM (palette cycling, angled or shortened labels), PIE (percent labels, legend); SCATTER, TABLE and empty specs fall back to a table. "Unit not defined" badge. |
| FE-RPT-005 | `report/ClaimLink.tsx` | Link to the claim traceability route. |
| FE-RPT-006 | `report/TraceabilityPanel.tsx` | "How we know" dialog: statement, verification badge and reasons, evidence cards (metric, subject, value, method, reliability), collapsed technical details (template, check, model and prompt, evidence ids). |
| FE-RPT-007 | `report/ExportPdfAction.tsx` | Downloads the server-rendered PDF (RPT-API-003) in the current interface language. |
| | `report/ChartSummaryCard.tsx` | A chart with its "What this chart shows" readings and claim links. |

### 1.4 Hooks (`src/hooks`)

| Id | Program | Functions | Explanation |
|---|---|---|---|
| | `useAnalyses.ts` | `keys`, `useAnalysesList`, `useAnalysis`, `useProfile`, `useInsights`, `useVisualizations`, `useChartOptions`, `useReport`, `useClaim`, `useSelectCharts`, `useInvalidateAnalysis`, `useStartJob`, `useRunAnalysis`, `useGenerateReport`, `useDeleteAnalysis` | TanStack Query hooks, keys centralised. Insights, visualizations, report and claim keys include the locale because the API localises them. `useStartJob` removes the status and report caches so a new poller never stops on stale data. Run and report mutations send a fresh `Idempotency-Key`. |
| FE-HOOK-001 | `useAnalysisStatus.ts` | `POLL_MS`, `isStopped`, `useAnalysisStatus` | Polls RUN-API-002 every 2 s (then `poll_seconds` from the API), pauses in background tabs, stops on COMPLETED/FAILED, on `stopAt`, or on a FAILED job. |
| FE-HOOK-002 | `useAuth.tsx` | `AuthProvider`, `useAuth` → `user, isAuthenticated, isLoading, login, register, logout, refresh` | Restores the session from `/me`, registers the 401 handler that clears the session and the query cache. |

### 1.5 Services (`src/services`)

| Id | Program | Functions → endpoint |
|---|---|---|
| FE-SVC-001 | `apiClient.ts` | `request`, `api.get/post/put/delete/upload`, `download`, `ApiError` (`status, code, details, fieldErrors()`), `setUnauthorizedHandler`, `describeError`. Adds `Accept: application/json`, `X-Dataspeak-Language`, `Authorization: Bearer`. Parses the `{error:{code,message,details}}` envelope; network failure → `NETWORK_ERROR`. `download` returns `{blob, fileName}` from `Content-Disposition`. |
| FE-SVC-002 | `auth.service.ts` | `register` → POST `/auth/register`; `login` → POST `/auth/login`; `logout` → POST `/auth/logout`; `me` → GET `/me`; `resendVerification` → POST `/auth/email/resend`. |
| FE-SVC-003 | `analysis.service.ts` | `list` → GET `/analyses`; `create` → POST `/analyses`; `get` → GET `/analyses/:id`; `remove` → DELETE `/analyses/:id`; `run` → POST `/analyses/:id/run` `{language}` with `Idempotency-Key`; `status` → GET `/analyses/:id/status`. |
| FE-SVC-004 | `dataset.service.ts` | `upload` → POST multipart `/analyses/:id/dataset` (returns `{analysis, source}`); `validate` → POST `/analyses/:id/validate`. |
| FE-SVC-005 | `profile.service.ts` | `run` → POST `/analyses/:id/profile` (202); `get` → GET `/analyses/:id/profile`. |
| FE-SVC-006 | `definition.service.ts` | `save` → PUT `/analyses/:id/definitions`; `suggestNames` → POST `/analyses/:id/definitions/suggest-names`. |
| FE-SVC-007 | `report.service.ts` | `insights` → GET `/insights`; `visualizations` → GET `/visualizations`; `chartOptions` → GET `/visualizations/options`; `selectCharts` → PUT `/visualizations/selection` `{selected, add, theme?}`; `generate` → POST `/report` `{language}`; `get` → GET `/report`; `translate` → POST `/report/translate` `{language}`; `pdf` → GET `/report/pdf` (download); `claim` → GET `/claims/:claimId`. All under `/analyses/:id`. |

### 1.6 Internationalisation (`src/i18n`, `src/lib/locale.ts`)

| Program | Functions | Explanation |
|---|---|---|
| `i18n/core.ts` | `t(text, vars?)`, `currentLocale()`, `activateLocale(l)` | English source strings are the keys; `t` looks the string up in the active dictionary and falls back to English, then fills `{name}` tokens. |
| `i18n/th.ts`, `zh.ts`, `my.ts` | dictionaries | Thai, Simplified Chinese and Burmese translations of every interface string. |
| `i18n/I18nProvider.tsx`, `context.ts` | `I18nProvider`, `useI18n()` → `{locale, setLocale, t}` | `setLocale` activates the dictionary, persists it and remounts the tree (`key={locale}`); sets `<html lang>`. |
| `lib/locale.ts` | `LOCALES`, `LOCALE_NAMES`, `isLocale`, `readLocale`, `writeLocale` | Locale from memory, `localStorage['dataspeak.locale']`, then `navigator.language`, default `en`. |

The language reaches the backend as the `X-Dataspeak-Language` header on every request and as the `language` body
field of the run and report calls.

### 1.7 Utilities, libraries and types

| Program | Functions | Explanation |
|---|---|---|
| `utils/format.ts` | `formatDate`, `formatInt`, `formatPct`, `formatBytes`, `formatMeasure`, `newIdempotencyKey`, `truncateLabel`, `distinguishLabels` | Display formatting; long category labels sharing a prefix are shortened to their distinguishing tail. |
| `utils/statusLabels.ts` | `STATUS_LABELS`, `STAGE_LABELS`, `TERMINAL_STATUSES`, type and claim label tables, `describeReason`, `statusLabel`, `stageLabel` | Business wording for every enum. |
| `utils/evidenceWording.ts` | `metricLabel`, `methodLabel`, `dataNoteSentence` | Business wording for evidence metrics and methods, data-note sentences. |
| `utils/steps.ts` | `STEPS`, `furthestStep`, `stepPath`, `continuePath` | Workflow steps and the step guard rules. |
| `utils/chartThemes.ts` | `CHART_THEMES`, `DEFAULT_CHART_THEME`, `isChartTheme`, `themeColors` | Six colour palettes, identical to the backend list. |
| `utils/files.ts` | `MAX_UPLOAD_MB`, `ACCEPTED_EXTENSIONS`, `isSpreadsheet`, `acceptFile` | Upload acceptance rules. |
| `utils/naming.ts`, `profileSummary.ts`, `quality.ts` | `nameFromFile`, `humanize`, `hasNonLatinScript`, `summarize`, `deriveIssues` | Name helpers, per-column summary text, quality issue derivation. |
| `lib/logger.ts` | `logger.debug/info/warn/error` | Dev-only JSON lines; redacts `file, csv, rows, row, cells, cell, content, sample` and any object or Blob value. |
| `lib/session.ts` | `session.token/userId/set/clear` | Token in memory, mirrored to `sessionStorage['dataspeak.session']`. |
| `types/analysis.types.ts` (FE-TYPE-001), `dataset.types.ts` (FE-TYPE-002), `report.types.ts` (FE-TYPE-003), `api.types.ts` | interfaces | Exact mirrors of the Laravel resources: `Analysis`, `AnalysisDetail`, `StatusResponse`, `ProfileResponse`, `FieldDefinition`, `Report`, `Claim`, `Visualization`, `ChartOption`, `Envelope`, `Paginated`. |

### 1.8 Tests (`src/test` and co-located files)

`workflow.integration.test.tsx` (login → upload → profile → define → analyze → chart review with theme and custom
chart → report → PDF → traceability, plus the step-guard redirect), `privacy.test.tsx` (no FastAPI URL, sentinel
cell never reaches console or storage, token only in sessionStorage), `a11y.test.tsx` (axe), `i18n.test.tsx`,
`reportLanguage.test.tsx`, `rebuildReport.test.tsx`, `suggestNames.test.tsx`, `verifyEmail.test.tsx`,
`files.test.ts`, `format.test.ts`, `useAnalysisStatus.test.tsx`, `apiClient.test.ts`, `logger.test.ts`,
`statusLabels.test.ts`. The MSW fake of the Laravel contract lives in `src/test/msw/handlers.ts`.

---

## 2. Backend (PHP Laravel)

Stack: PHP 8.4, Laravel 13, Sanctum, PostgreSQL 17, Redis (queue, cache, session), dompdf, OpenSpout, Pint,
PHPUnit 12. Source in `backend/`. All routes are under `/api/v1` with the `api` middleware group
(`ForceJsonResponse`, `SetLocale`). Errors use one envelope: `{"error":{"code","message","details?"}}`.

### 2.1 Program list with function list

#### 2.1.1 API routes (`routes/api.php`)

| Method | Path (`/api/v1` prefix) | Program | Middleware | Id |
|---|---|---|---|---|
| GET | `/health` | HealthController | | |
| POST | `/auth/register` | AuthController@register | throttle:auth | AUTH-API-001 |
| POST | `/auth/login` | AuthController@login | throttle:auth | AUTH-API-002 |
| POST | `/auth/logout` | AuthController@logout | auth:sanctum | AUTH-API-003 |
| GET | `/me` | AuthController@me | auth:sanctum | AUTH-API-004 |
| GET | `/auth/verify-email/{id}/{hash}` | EmailVerificationController@verify | throttle:verification, signed link | AUTH-API-005 |
| POST | `/auth/email/resend` | EmailVerificationController@resend | auth:sanctum, throttle:verification | AUTH-API-006 |
| GET, POST | `/analyses` | AnalysisController@index, @store | auth:sanctum, verified.api | ANL-API-001/002 |
| GET, DELETE | `/analyses/{analysis}` | AnalysisController@show, @destroy | same | ANL-API-003/004 |
| POST | `/analyses/{analysis}/dataset` | DatasetController@upload | + throttle:upload | DATA-API-001 |
| POST | `/analyses/{analysis}/validate` | DatasetController@validate | same | DATA-API-002 |
| POST, GET | `/analyses/{analysis}/profile` | ProfileController@run, @show | same | PROF-API-001/002 |
| PUT | `/analyses/{analysis}/definitions` | DefinitionController@update | same | DEF-API-001 |
| POST | `/analyses/{analysis}/definitions/suggest-names` | DefinitionSuggestionController | + throttle:ai-suggest | DEF-API-002 |
| POST | `/analyses/{analysis}/run` | RunController@run | + throttle:run, idempotent | RUN-API-001 |
| GET | `/analyses/{analysis}/status` | RunController@status | same | RUN-API-002 |
| GET | `/analyses/{analysis}/insights` | InsightController@index | same | INS-API-001 |
| GET | `/analyses/{analysis}/visualizations` | VisualizationController@index | same | VIS-API-001 |
| GET | `/analyses/{analysis}/visualizations/options` | VisualizationController@options | same | VIS-API-002 |
| PUT | `/analyses/{analysis}/visualizations/selection` | VisualizationController@select | same | VIS-API-003 |
| POST | `/analyses/{analysis}/report` | ReportController@generate | + throttle:report, idempotent | RPT-API-001 |
| GET | `/analyses/{analysis}/report` | ReportController@show | same | RPT-API-002 |
| GET | `/analyses/{analysis}/report/pdf` | ReportPdfController | + throttle:report | RPT-API-003 |
| POST | `/analyses/{analysis}/report/translate` | ReportTranslationController | + throttle:ai-suggest | RPT-API-004 |
| GET | `/analyses/{analysis}/claims/{claim}` | ClaimController@show | same | TRC-API-001 |

"same" means `auth:sanctum` and `verified.api`; "+" adds to those. Analyses and claims are addressed by UUID
`public_id`. Rate limiters (`AppServiceProvider`): auth 5/min by IP, upload 10/min, run 6/min, report 6/min,
ai-suggest 10/min, verification 3/min. Scheduled commands (`routes/console.php`): `dataspeak:purge-uploads` every
five minutes, `dataspeak:reap-stale-jobs` every minute.

#### 2.1.2 Controllers (`app/Http/Controllers/Api/V1`)

| Program | Functions | Explanation |
|---|---|---|
| `AuthController` | `register`, `login`, `logout`, `me` | Register creates the user (role USER), a Sanctum token and sends the verification mail; returns `{token, user, verification{required, email_sent}}` (201). Login returns `{token, user}` or 401 `INVALID_CREDENTIALS`. Logout revokes the current token. |
| `EmailVerificationController` | `verify(id, hash)`, `resend`, static `send(User)` | Verify is a signed browser link that redirects to `{FRONTEND_URL}/login?verified=1|already|invalid`. Resend answers 200 `already_verified`, 202 `sent` or 503 `failed`. |
| `HealthController` | `__invoke` | PostgreSQL and Redis checks; `{status: ok|degraded, service, version, checks}`. |
| `AnalysisController` | `index`, `store`, `show`, `destroy` | List (paginated, newest first), create (201), detail (stage history and job progress), delete through `AnalysisDeletionService` (204). |
| `DatasetController` | `upload`, `validate` | Upload: refuse while processing (`ANALYSIS_BUSY`), clear derived results, store the transient file (XLSX converted to CSV), move to UPLOADED, return `meta.source`. Validate: call the analytics service, 422 `CSV_VALIDATION_FAILED` with details when invalid, else store counts and move to VALIDATED. |
| `ProfileController` | `run`, `show` | Run (202) is idempotent while a profile job is active; dispatches `ProfileAnalysisJob`. Show returns `ProfileResource` or 409 `STEP_NOT_READY`. |
| `DefinitionController` | `update` | Validates, rejects unknown columns (`UNKNOWN_COLUMN`), saves definitions with `is_suggested=false`, moves to DEFINED. |
| `DefinitionSuggestionController` | `__invoke` | Asks `ColumnDefinitionSuggester` for business names, meanings, units and aggregations; stores nothing; returns items plus `meta{ai_status, model, prompt_version, ai_count}`. |
| `RunController` | `run`, `status` | Run (202): needs definitions, status at least DEFINED or FAILED, file present; stores `report_language`; moves to ANALYZING and dispatches `RunAnalysisJob`. Status: reaps stale jobs, returns `{status, stage, progress, job, is_terminal, last_error, poll_seconds}`. |
| `InsightController` | `index` | Insights with localized software sentences for non-English locales. |
| `VisualizationController` | `index`, `options`, `select` | Charts; addable chart options from `ChartCatalog` with full data for previews; selection saves kept ids, adds user charts (`source=USER`) and the colour theme. Errors `UNKNOWN_CHART_OPTION`, `NO_CHARTS_SELECTED`. |
| `ReportController` | `generate`, `show` | Generate (202): `assertCanBuildReport`, `language` in en/th/zh/my, moves to REPORTING and dispatches `GenerateReportJob`. Show returns `ReportResource` in the interface language. |
| `ReportPdfController` | `__invoke` | Builds the PDF in the interface language; headers `Content-Language`, `Content-Disposition`, `Cache-Control: no-store`. |
| `ReportTranslationController` | `__invoke` | 202 `in_progress` while a background translation runs; otherwise translates labels and statements synchronously and returns counts. |
| `ClaimController` | `show` | Claim with evidence, validation reasons and generation metadata; uses the stored translation for the interface language when present. |

#### 2.1.3 Requests, resources, middleware, exceptions, policy

| Program | Explanation |
|---|---|
| `Requests/LoginRequest`, `RegisterRequest`, `StoreAnalysisRequest`, `UploadDatasetRequest`, `UpdateDefinitionsRequest`, `RunAnalysisRequest` | Validation rules. Upload accepts csv/xlsx up to `UPLOAD_MAX_MB`. Definitions require a Latin-script business name, valid semantic type and aggregation, unit ≤ 32, and a unit for CURRENCY. Run accepts `language`. |
| `Resources/AnalysisResource`, `AnalysisDetailResource`, `ProfileResource`, `FieldDefinitionResource`, `InsightResource`, `EvidenceResource`, `VisualizationResource`, `ClaimResource`, `ReportResource`, `UserResource` | JSON shapes mirrored by the frontend types. `ReportResource` carries `language`, `display_language`, `translation{complete, translated, total, in_progress}`, `chart_theme`, `ai{status, note, model, prompt_version}`, summary metrics, data notes, selected charts with readings, and SUPPORTED in-report claims. |
| `Middleware/ForceJsonResponse`, `SetLocale`, `IdempotencyKey`, `EnsureEmailIsVerifiedJson` | JSON everywhere; locale from `X-Dataspeak-Language` or `?lang`; replay of a POST with the same `Idempotency-Key` for 24 h (`Idempotent-Replayed: true`); 403 `EMAIL_NOT_VERIFIED`. |
| `Exceptions/ApiException`, `LifecycleConflictException` (409: `STEP_NOT_READY`, `UPLOAD_REQUIRED`, `ANALYSIS_BUSY`, `INVALID_TRANSITION`), `AnalyticsServiceException` (503 `ANALYTICS_UNAVAILABLE`, 502 `ANALYTICS_BAD_RESPONSE`, 422 for upstream dataset errors), `ServiceNotConfiguredException` (501), `ApiExceptionRenderer` | Error mapping: 422 `VALIDATION_FAILED`, 401 `UNAUTHENTICATED`, 403 `FORBIDDEN`, 404 `NOT_FOUND`, 405, 429 `RATE_LIMITED`, 500 `INTERNAL_ERROR`. |
| `Policies/AnalysisPolicy` | `view`, `update`, `delete`: owner only. |
| `Logging/PrivacyProcessor`, `PrivacyTap` | Redact `file, csv, rows, row, cells, cell, content, sample, payload_rows` from every log line. |
| `Support/Locale`, `Support/ChartThemes`, `Support/ResolvesOwnedAnalysis` | Supported locales and script checks; the six chart palettes; owner-scoped analysis lookup (404 for other users). |

#### 2.1.4 Services (`app/Services`)

Analytics and lifecycle:

| Program | Functions | Explanation |
|---|---|---|
| `Analytics/AnalyticsServiceClient` | `validate`, `profile`, `analyze`, `insights`, `recommendVisualizations` | The only client of the FastAPI service (section 2.2). |
| `Analytics/DefinitionPayload` | `for(Analysis)` | Definitions as `{column, business_name, semantic_type, unit, aggregation}`. |
| `Analytics/ProfilePersister` | `persist`, `inferredType` | Upserts `dataset_columns`, sets row and column counts and completeness. |
| `Analytics/AnalysisResultPersister` | `clear`, `persistEvidence`, `persistInsights`, `persistVisualizations` | Stores evidence, insights and recommended charts; clears derived results before a rerun. |
| `Analytics/ProgressStore` | `start`, `update`, `isActive`, `get`, `clear` | Job progress in the cache (`dataspeak:analysis:{id}:job`): kind run/profile, status, stage, progress, error. |
| `AnalysisDeletionService` | `delete` | Deletes an analysis and every dependent row, the transient file and the progress entry. |
| `Lifecycle/AnalysisLifecycle` | `canTransition`, `transition`, `assertAtLeast`, `canBuildReport`, `assertCanBuildReport`, `assertNotProcessing`, `beginStage`, `completeStage`, `failStage`, `fail` | The only writer of `analyses.status`; enforces the state machine (CREATED → UPLOADED → VALIDATED → PROFILED → DEFINED → ANALYZING → ANALYZED → REPORTING → COMPLETED, FAILED and re-entry rules) and records stage attempts. |
| `Lifecycle/StaleJobReaper` | `reap`, `reapProfiling`, `reapAll`, `deadlineSeconds` | Marks a processing analysis FAILED with `JOB_INTERRUPTED` when its running stage exceeds the job timeout plus grace; marks a dead profile job failed without changing the status. |

Uploads:

| Program | Functions | Explanation |
|---|---|---|
| `Uploads/TransientUploadStore` | `store`, `exists`, `absolutePath`, `delete`, `purgeExpired` | Raw file on the `transient` disk (`storage/app/tmp/analyses/{id}/dataset.csv`), SHA-256, purge by TTL; never a database column. |
| `Uploads/SpreadsheetConverter` | `isSpreadsheet`, `toCsv` | XLSX to CSV with OpenSpout, largest worksheet; errors `SPREADSHEET_UNREADABLE`, `SPREADSHEET_EMPTY`. |

AI (the only code that talks to the model, section 2.3):

| Program | Functions | Explanation |
|---|---|---|
| `Ai/Contracts/LlmClient` | `chat(messages, schema, options)`, `modelTag()` | Interface; `OllamaClient` is bound when `AI_LLM_DRIVER=ollama`. |
| `Ai/Clients/OllamaClient` | `chat`, `modelTag` | Schema-constrained chat call, `<think>` stripping, typed failures. |
| `Ai/AiGateway` | `interpret(analysis, resolver, persist, locale)`, `repair(generation, claims, resolver)` | Executive interpretation with up to two attempts and one repair pass; records every attempt in `ai_generations`; never throws for availability problems. |
| `Ai/Prompts/ExecutiveInterpretationPrompt` | `version`, `system`, `user`, `messages`, `repairMessage`, `retryMessage` | Versioned prompt (`exec-interp-v7`): ten hard rules, placeholders instead of numbers, security rule. |
| `Ai/Schemas/ClaimOutputSchema` | `schema`, `problems` | JSON schema `{claims[1..20]{claim_key, claim_kind, text, evidence_ids, confidence_language}, limitations}` and a strict parser. |
| `Ai/EvidencePayloadBuilder` | `build` | Evidence, insights, charts and placeholders for the prompt, trimmed to the context window; no raw rows. |
| `Ai/PlaceholderResolver` | `for`, `resolve`, `viewFor`, `all` | Resolves `{ev_xxx.field}` placeholders (display_value, label, unit, dimension, group, period, period_from, period_to) per locale. |
| `Ai/EvidenceSentences` | `for(evidence, resolver, locale)` | Software-written sentence per metric from `lang/{locale}/sentences.php`. |
| `Ai/ClaimTranslator` | `translate(analysis, locale, attempt)`, `options`, `batchSize`, `normalizeText`, `acceptable` | Translates verified statements (never re-interprets) in batches; accepts only when placeholders match, no digits appear and the text is in the target script. |
| `Ai/DisplayLabelTranslator` | `translate(analysis, locale)`, `candidates`, `batchSize`, `needsTranslation` | Per-language map of column and category labels in `analyses.label_translations`. |
| `Ai/ColumnDefinitionSuggester` | `suggest(columns, datasetName)`, `apply(analysis)` | Suggested business names, meanings, units and aggregations at profiling, on top of a software baseline. |
| `Ai/LlmResult`, `Ai/LlmUnavailableException` | | Result value object; failure codes `AI_UNAVAILABLE`, `AI_TIMEOUT`, `AI_HTTP_ERROR`. |

Validation and reports:

| Program | Functions | Explanation |
|---|---|---|
| `Validation/RuleBasedClaimValidator` | `validate(claim, resolver, locale)`, `method` | Verifies every claim: evidence references, placeholder fields, no digits outside placeholders, right language, no causal wording, direction against the evidence sign, quality of cited evidence. Rejects, flags for review or supports; informational codes for completed citations and reclassification as a caveat. |
| `Validation/ValidationResult` | `accepted` | Status, reasons, method, normalised claim. |
| `Reporting/NarrativeRenderer` | `render(template, resolver)` | Fills placeholders; unresolvable ones render as `[unavailable]`. |
| `Reports/ReportAssembler` | `assemble(analysis, generation, aiStatus, locale)` | Writes the report row: summary metrics, sections (executive summary, findings, limitations, chart summaries), data notes. |
| `Reports/ChartCatalog` | `all`, `options`, `build` | Every chart the stored evidence supports: line (period series), bar total and average (group aggregates), pie (share of total for additive measures with 2 to 8 non-negative parts), histogram, correlation table, missing-values bar. |
| `Reports/ChartGrounding` | `for`, `chartFor`, `chartsFor`, `evidenceKeysFor` | Maps claims to the kept charts so each chart gets its readings. |
| `Reports/ChartTitles`, `Reports/DisplayLabels` | `localize`; `for`, `mapFor`, `text`, `row`, `isLatin` | Localised chart titles; label substitution per language with English business names as the fallback. |
| `Reports/Pdf/ReportPdfBuilder` | `build(report, request)`, `fileName` | dompdf A4 report: cover, letterhead, charts, footer with timestamp and page numbers, script fonts (Noto Sans Thai, Myanmar, SC), colour theme palette. |
| `Reports/Pdf/ChartSvg`, `ChartHtml`, `ScriptSpans`, `PdfWording` | `render`, `renderWithKey`, `color`; `wantsHtml`, `bars`; `html`; `dataNote`, `metric` | SVG bars, lines, histograms and pies; HTML bars for long or non-Latin labels; font spans per script; localized note wording. |

#### 2.1.5 Jobs (`app/Jobs`)

| Program | Queue and limits | Steps |
|---|---|---|
| `ProfileAnalysisJob(analysisId, jobId)` | default queue, timeout 300 s, unique per analysis | PROFILING stage: call `/v1/profile`, persist columns; DEFINITION stage: AI column suggestions; move to PROFILED. On failure the analysis stays VALIDATED and the job error is kept in `ProgressStore`. |
| `RunAnalysisJob(analysisId, jobId)` | default, timeout 600 s | Clear results; STATISTICAL_ANALYSIS (`/v1/analyze`); INSIGHT_DETECTION (`/v1/insights`); VISUALIZATION (`/v1/visualizations/recommend`); English label map; move to ANALYZED (then REPORTING when `AUTO_REPORT=true`). The transient file is deleted afterwards unless the failure was a transient analytics error. |
| `GenerateReportJob(analysisId, jobId, language)` | default, timeout 900 s | Delete old claims; AI_SYNTHESIS (`AiGateway::interpret`); CLAIM_VALIDATION (validate, repair repairable rejections, place claims against charts); REPORTING (`ReportAssembler`); move to COMPLETED; queue `TranslateReportJob` for the other languages when `PRETRANSLATE_REPORTS=true`. |
| `TranslateReportJob(analysisId, locale, attempt)` | `translations` queue (lower priority), timeout 540 s, up to 3 attempts with 60 s × attempt delay | Translate labels and verified statements for one language; keeps a cache flag so the page polls instead of starting a second translation. |

The worker runs `php artisan queue:work redis --queue=default,translations --tries=1 --timeout=600`, so
user-facing jobs always run before background translations.

#### 2.1.6 Console commands, models, enumerations

Commands: `dataspeak:ai-eval {analysis} {--runs=3} {--json}` (live model evaluation without persistence),
`dataspeak:purge-uploads {--ttl=}`, `dataspeak:reap-stale-jobs {--force} {--analysis=}`,
`dataspeak:translate-labels {analysis} {--language=en} {--show}`.

Models (`app/Models`): `User`, `Analysis` (public_id, status, current_stage, dataset metadata, `label_translations`,
`report_language`, `chart_theme`), `AnalysisStageRecord`, `DatasetColumn`, `FieldDefinition`, `Evidence`,
`Insight`, `Visualization` (chart_type, source, is_selected, spec), `AiGeneration`, `Claim` (statement,
`statement_translations`, status, validation notes, in_report), `Report`.

Enumerations (`app/Enums`, authoritative in the data dictionary): `AnalysisStatus`, `AnalysisStage`, `StageStatus`,
`JobStatus`, `ColumnInferredType`, `SemanticType`, `Aggregation`, `AnalysisFamily`, `QualityStatus`, `InsightType`,
`ChartType` (LINE, BAR, SCATTER, HISTOGRAM, TABLE, PIE), `VisualizationSource`, `AiGenerationType`,
`AiGenerationStatus`, `ClaimKind`, `ClaimStatus`, `ReportStatus`, `UserRole`.

### 2.2 Integration API list with the analytics FastAPI

Caller: `app/Services/Analytics/AnalyticsServiceClient.php` only. Configuration in `config/services.php`
(`analytics`): `ANALYTICS_BASE_URL` (default `http://127.0.0.1:8001`; in Docker `http://analytics:8000`),
`ANALYTICS_INTERNAL_TOKEN`, `ANALYTICS_TIMEOUT_SECONDS` (30). Every call sends `X-Internal-Token`, accepts JSON,
uses a 5 s connect timeout, and retries twice (250 ms) on connection errors only.

| # | Call | Contract id | Request | Response fields used | Laravel caller and stage |
|---|---|---|---|---|---|
| 1 | POST `/v1/validate` (multipart) | PY-API-003 | `file` (transient CSV as `dataset.csv`), `metadata` JSON `{analysis_id}` | `valid`, `errors[]{code, message, column}`, `warnings[]`, `row_count`, `column_count` | `DatasetController@validate`, stage VALIDATION (synchronous) |
| 2 | POST `/v1/profile` (multipart) | PY-API-002 | `file`, `metadata` `{analysis_id, definitions[]}` | `dataset{rows, columns, completeness}`, `columns[]{name, position, inferred_type, null_count, null_rate, distinct_count, stats}` | `ProfileAnalysisJob`, stage PROFILING |
| 3 | POST `/v1/analyze` (multipart) | PY-API-004 | `file`, `metadata` `{analysis_id, definitions[], options{seed 42, max_insights 10}}` | `evidence[]{evidence_id, analysis_family, metric, method, value, display_value, metric_column, dimension_column, source_columns, parameters, quality.status, payload}`, `summary.families_run` | `RunAnalysisJob`, stage STATISTICAL_ANALYSIS |
| 4 | POST `/v1/insights` (JSON) | PY-API-005 | `{analysis_id, evidence[], definitions[], options{max_insights}}` | `insights[]{insight_type, headline, description, score, rank, primary_evidence_id, evidence_ids}` | `RunAnalysisJob`, stage INSIGHT_DETECTION |
| 5 | POST `/v1/visualizations/recommend` (JSON) | PY-API-006 | `{analysis_id, evidence[], insights[], definitions[]}` | `visualizations[]{type, title, x, y, unit_missing, series, evidence_ids, insight_rank}` | `RunAnalysisJob`, stage VISUALIZATION |

`definitions[]` comes from `DefinitionPayload::for()`. Error handling: a connection failure or a 5xx without an
error code → 503 `ANALYTICS_UNAVAILABLE`; a body that is not JSON → 502 `ANALYTICS_BAD_RESPONSE`; upstream codes
`ANALYTICS_INVALID_DATASET`, `ANALYTICS_UNSUPPORTED_TYPE`, `ANALYTICS_INSUFFICIENT_DATA` → 422; other codes → 502.
In the run job a transient code keeps the CSV so the run can be retried; any other failure deletes it, and the
analysis goes to FAILED with the upstream code in `last_error_code`. Logs carry method, analysis id, duration and
status only, never payloads. The FastAPI `/health` endpoint is not called by the backend.

### 2.3 Integration API list with Ollama

Client: `app/Services/Ai/Clients/OllamaClient.php`, bound to the `LlmClient` interface when `AI_LLM_DRIVER=ollama`.
Configuration in `config/services.php` (`ollama`) and `config/dataspeak.php` (`ai`):

| Key | Env var | Default |
|---|---|---|
| base_url | `OLLAMA_BASE_URL` | `http://127.0.0.1:11434` (Docker: `http://host.docker.internal:11434`) |
| model | `OLLAMA_MODEL` | `qwen3:8b` |
| timeout | `OLLAMA_TIMEOUT` | 300 s |
| temperature / seed / num_ctx / think | `OLLAMA_TEMPERATURE`, `OLLAMA_SEED`, `OLLAMA_NUM_CTX`, `OLLAMA_THINK` | 0.1 / 42 / 8192 / false |
| ai.enabled, ai.driver | `AI_ENABLED`, `AI_LLM_DRIVER` | true, ollama |
| ai.prompt_version | `AI_PROMPT_VERSION` | `exec-interp-v7` |
| ai.pretranslate | `PRETRANSLATE_REPORTS` | true |
| ai.job_timeout_seconds | `AI_JOB_TIMEOUT_SECONDS` | 900 |
| ai.validator | `AI_CLAIM_VALIDATOR` | rule_based |

The only Ollama endpoint used is **`POST {OLLAMA_BASE_URL}/api/chat`** with body
`{model, messages, stream: false, format: <JSON schema>, think, options{temperature, seed, num_ctx[, num_predict]}}`.
The response `message.content` is stripped of `<think>` blocks and decoded as JSON into `LlmResult` (`json`,
`rawContent`, `model`, `inputTokens` ← `prompt_eval_count`, `outputTokens` ← `eval_count`, `durationMs`).
Failures map to `AI_TIMEOUT` (timed-out connection), `AI_UNAVAILABLE` (other connection errors) and
`AI_HTTP_ERROR` (non-2xx); `AiGateway` adds `AI_DISABLED`, `AI_NO_EVIDENCE` and `AI_SCHEMA_INVALID`. All are stored
in `ai_generations.error_code`; none is shown to users. The report's `ai.status` is `ready`, `no_valid_claims`,
`unavailable` or `disabled`, and the pipeline always completes without the model.

| # | Call (prompt version) | Messages | Output schema | Options | Used by |
|---|---|---|---|---|---|
| 1 | Executive interpretation (`exec-interp-v7`) | `ExecutiveInterpretationPrompt::system()` + `user()` with `<evidence_json>`; a retry appends the assistant output and the problems | `ClaimOutputSchema` (claims with placeholders, limitations) | config defaults, timeout 300; up to two attempts | `AiGateway::interpret` from `GenerateReportJob` (AI_SYNTHESIS), `dataspeak:ai-eval` |
| 2 | Claim repair (same version) | system + `repairMessage` with a placeholder menu (`<repair_json>`) | `ClaimOutputSchema` | config defaults; one call | `AiGateway::repair` from `GenerateReportJob` |
| 3 | Statement translation (`claim-translate-v1`) | rules + `<sentences_json>` | `{claims:[{id, text}]}` | temperature 0.0 (0.3 on retry), num_ctx 8192, num_predict 400 × sentences, timeout 180; batches of 8 (4 for Burmese, 2 on retry) | `ClaimTranslator` from `TranslateReportJob`, `ReportTranslationController` |
| 4 | Display label translation (`display-labels-v1`) | rules + `<labels_json>` | `{labels:[{original, translation}]}` | temperature 0.0, num_predict 250 × labels, timeout 180; batches of 25 (12 for Burmese) | `DisplayLabelTranslator` from `RunAnalysisJob`, `GenerateReportJob`, `TranslateReportJob`, `dataspeak:translate-labels` |
| 5 | Column definition suggestions (`column-definitions-v1`) | rules + `<columns_json>` (column, detected type, distinct values, missing share, range) | `{columns:[{column, business_name, semantic_type, unit, aggregation, description}]}` | temperature 0.0, timeout 120 | `ColumnDefinitionSuggester` from `ProfileAnalysisJob`, `DefinitionSuggestionController` |

What the model receives: evidence records, insight texts with placeholders, chart titles, column labels and
category values. It never receives dataset rows. What the model returns is never shown unverified: claims pass
`RuleBasedClaimValidator`, translations pass `ClaimTranslator::acceptable`, labels pass the script and length
checks, and suggestions are accepted only when compatible with the inferred type.

---

## 3. Analyst service (FastAPI)

Stack: Python 3.12, FastAPI, Pydantic 2, Polars, NumPy, SciPy, statsmodels, scikit-learn. Source in
`analytics/app`. The service owns validation, profiling, deterministic statistics, evidence, insight detection and
chart recommendation. It does not own authentication or workflow state and it calls no LLM. It is reachable only
on loopback (`127.0.0.1:8001` on the host, `analytics:8000` inside Docker) and every `/v1` route requires the
`X-Internal-Token` header (`ANALYTICS_INTERNAL_TOKEN`). Logging redacts `file, csv, rows, row, cells, cell,
content, payload_rows, sample` and never echoes cell values, including in error messages.

Start: Docker `uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload`; native
`uvicorn app.main:app --reload --host 127.0.0.1 --port 8001`. Interactive docs at `/docs` (internal use).
Settings (`app/core/config.py`): `analytics_internal_token`, `dataspeak_tmp_dir` (`./tmp`; `/tmp/dataspeak` in
Docker, tmpfs), `max_upload_mb` (50), `log_level`.

### 3.1 API list

| Id | Method and path | Request | Response | Explanation |
|---|---|---|---|---|
| PY-API-001 | GET `/health` (no token) | | `{status: "ok", service: "dataspeak-analytics", version: "1.0"}` | Readiness check. |
| PY-API-003 | POST `/v1/validate` (multipart) | `file` CSV, `metadata` JSON (`DatasetMetadata{analysis_id, definitions[], options}`) | `ValidateResponse{valid, row_count, column_count, columns[], warnings[], errors[]}` | Structural validation. Errors `NO_HEADER`, `EMPTY_COLUMN_NAME`, `DUPLICATE_COLUMN`, `NO_DATA_ROWS`; warnings `ENCODING_FALLBACK`, `DUPLICATE_ROWS`, `RAGGED_ROWS`, `MOSTLY_EMPTY_COLUMN`, `HIGH_NULL_RATE`, `CONSTANT_COLUMN`. 413 when over `MAX_UPLOAD_MB`. |
| PY-API-002 | POST `/v1/profile` (multipart) | same | `ProfileResponse{dataset{rows, columns, completeness, duplicate_rows}, columns[]{name, position, inferred_type, null_count, null_rate, distinct_count, stats}, quality_findings[]}` | Type inference and per-column statistics; derived metadata only, no samples or category labels. 422 `ANALYTICS_INVALID_DATASET` when validation fails. |
| PY-API-004 | POST `/v1/analyze` (multipart) | same | `AnalyzeResponse{analysis_id, evidence[], summary{evidence_count, families_run[], skipped[]}}` | Runs the analysis families (descriptive, trend, group comparison, association, statistical tests, quality) and returns evidence records with deterministic ids. 422 `ANALYTICS_INSUFFICIENT_DATA` for zero rows; 500 `ANALYTICS_METHOD_FAILED` when a method cannot complete. |
| PY-API-005 | POST `/v1/insights` (JSON) | `InsightsRequest{analysis_id, evidence[], definitions[], options{max_insights}}` | `InsightsResponse{insights[]{insight_type, headline, description, score, rank, primary_evidence_id, evidence_ids}}` | Rule-based insight detection and ranking over evidence; no CSV involved. Unknown fields are rejected (422). |
| PY-API-006 | POST `/v1/visualizations/recommend` (JSON) | `VisualizationRequest{analysis_id, evidence[], insights[], definitions[]}` | `VisualizationResponse{visualizations[]{type, title, x, y, unit_missing, series[], evidence_ids, insight_rank}}` | Up to eight chart specifications (line, bar, histogram, table, missing-values bar) built from aggregated payloads. |

Error envelope: `{"error":{"code","message","details?"}}` with codes `ANALYTICS_INVALID_DATASET`,
`ANALYTICS_INSUFFICIENT_DATA`, `ANALYTICS_UNSUPPORTED_TYPE` (422), `ANALYTICS_METHOD_FAILED`,
`ANALYTICS_INTERNAL_ERROR` (500), `ANALYTICS_UNAUTHORIZED` (401), `ANALYTICS_PAYLOAD_TOO_LARGE` (413). The frozen
OpenAPI document is `docs/contracts/fastapi-openapi.json` (regenerate with `python -m scripts.export_openapi`).

Program list (`analytics/app`):

| Program | Functions | Explanation |
|---|---|---|
| `main.py` | `create_app` | Builds the app, error handlers, health and v1 routers. |
| `api/health.py`, `api/v1/{validate,profile,analyze,insights,visualizations}.py`, `api/v1/deps.py` | route handlers; `parse_metadata`, `spool_upload`, `Timer` | Spool the upload to a per-request temp directory (always deleted), parse metadata, time the call. |
| `core/config.py`, `core/security.py`, `core/errors.py`, `core/logging.py` | `Settings`, `require_internal_token`, `AnalyticsError`, `install_error_handlers`, `configure_logging`, `RedactFilter`, `JsonFormatter` | Settings, token check (constant-time compare), error envelope, privacy-safe JSON logging. |
| `services/validation/csv_reader.py`, `validator.py` | `read_csv_bytes`, `read_csv_path`, `sniff_delimiter`, `validate_dataset` | Encoding detection (UTF-8 BOM, UTF-16, latin-1 fallback), delimiter sniffing, null tokens, structural findings. |
| `services/profiling/inference.py`, `profiler.py` | `infer_column`, `infer_frame`, `column_stats`, `profile_frame` | Type inference (numeric, integer, boolean, date, datetime, category, identifier, text, unknown) and column statistics. |
| `services/analysis/rules.py` | constants | Every threshold: type shares, category limits, minimum rows and periods, test sample sizes, insight thresholds, ranking weights, `HISTOGRAM_BINS = 10`, `SEED = 42`. |
| `services/analysis/roles.py`, `engine.py`, `evidence.py` | `resolve_roles`, `analyze_frame`, `evidence_id`, `make_evidence`, formatting helpers | Column roles (measure, dimension, time) with user definitions overriding inference; family orchestration; deterministic `ev_` + 12 hex ids. |
| `services/analysis/descriptive.py`, `trend.py`, `groups.py`, `association.py`, `quality.py` | `run` | Descriptive statistics and histograms; period series, growth, OLS slope; group aggregates, shares, Welch/Mann-Whitney/ANOVA/Kruskal tests with effect sizes; Spearman and Pearson correlation, Cramér's V; completeness, duplicates, null rates, constants, parse failures, IQR outliers. |
| `services/insights/detector.py`, `wording.py` | `detect`, templates, `CAUSAL_WORDS` | Candidate insights scored 0.5 magnitude + 0.3 support + 0.2 quality, ranked; English wording with no causal language. |
| `services/visualization/recommender.py` | `recommend` | Charts for ranked insights first, then remaining series, then a missing-values bar; `unit_missing` when no unit is defined. |
| `utils/tempfiles.py` | `request_tempdir`, `tempdir_entries` | Per-request temp directories, removed on success and failure. |

Tests: `cd analytics && pytest` (unit tests for health, security, logging leak guard, CSV reader, inference and
profile, rules and test selection, golden datasets, endpoints; a contract test that freezes the OpenAPI file).

### 3.2 Integration with Ollama

None. The analytics service makes no HTTP calls to any model server and has no LLM dependency (`httpx` appears only
as a test dependency for FastAPI's test client). Every AI call in Data Speak is made by the Laravel backend
(section 2.3), which sends the analytics service's evidence, never dataset rows, to the model. This separation is
deliberate: the service that sees raw data has no network path to the model, and the service that talks to the
model never sees raw data.

---

## 4. Ollama set-up

Data Speak uses a local Ollama server running a Qwen model. The server is internal only and is never exposed
publicly; the backend and the queue worker are its only clients.

### 4.1 Install and pull the model

Install Ollama from ollama.com (version 0.3x or later, which supports structured JSON outputs). Then:

```bash
ollama --version            # 0.3x or later
ollama pull qwen3:8b        # about 5 GB; use qwen3:4b on machines with less than 16 GB RAM
ollama list                 # the tag must appear here
curl http://127.0.0.1:11434/api/tags   # the server answers with the pulled models
```

Hardware: 16 GB RAM is comfortable for `qwen3:8b`; on smaller machines pull `qwen3:4b` and set
`OLLAMA_MODEL=qwen3:4b`. On macOS keep Ollama native, because Docker has no GPU access there; on Linux with an
NVIDIA GPU the compose container can be used with the NVIDIA Container Toolkit.

### 4.2 Choose how the backend reaches it

| Set-up | `OLLAMA_BASE_URL` | Notes |
|---|---|---|
| Native Ollama on the host, backend in Docker (default, recommended on macOS) | `http://host.docker.internal:11434` | Set in the root `.env`; compose adds the `host.docker.internal:host-gateway` mapping. |
| Native Ollama, backend run natively | `http://127.0.0.1:11434` | `backend/.env`. |
| Ollama in the compose container (Linux) | `http://ollama:11434` | Start with `docker compose --profile ollama up -d`, then `docker compose exec ollama ollama pull qwen3:8b`. Models persist in the `ollama_models` volume. |

Relevant variables (root `.env` for Docker, `backend/.env` for native): `OLLAMA_BASE_URL`, `OLLAMA_MODEL`
(`qwen3:8b`), `OLLAMA_TIMEOUT` (300), `OLLAMA_TEMPERATURE` (0.1), `OLLAMA_SEED` (42), `OLLAMA_NUM_CTX` (8192),
`OLLAMA_THINK` (false), `AI_ENABLED` (true), `AI_LLM_DRIVER` (ollama), `AI_PROMPT_VERSION` (exec-interp-v7),
`AI_JOB_TIMEOUT_SECONDS` (900), `PRETRANSLATE_REPORTS` (true). After changing them restart the backend and the
worker: `docker compose up -d backend backend-worker`.

### 4.3 Verify

```bash
# from the host
curl http://127.0.0.1:11434/api/tags
# from inside the backend container
docker compose exec backend php -r 'echo file_get_contents(getenv("OLLAMA_BASE_URL")."/api/version");'
# live test against the model (needs the model pulled)
cd backend && php artisan test --group=ollama
# quality evaluation on a completed analysis
docker compose exec backend php artisan dataspeak:ai-eval <analysis-id> --runs=5 --json
```

The evaluation reports `schema_valid_rate` (expected 1.0), `claim_acceptance_rate`, rejection reasons by rule and
average latency (about 25 to 35 seconds per generation with `qwen3:8b` on a laptop).

### 4.4 Behaviour when the model is unavailable

The pipeline never depends on the model to finish. If Ollama is unreachable, times out, or returns output that
fails the schema twice, the report is still assembled from the calculated findings with `ai.status =
"unavailable"` (or `no_valid_claims` when every claim was rejected), profiling still completes with the software
baseline for column suggestions, and translations fall back to the original language. Check
`docker compose logs backend-worker` for `ai.unreachable` with code `AI_TIMEOUT` or `AI_UNAVAILABLE`, confirm
`OLLAMA_BASE_URL` points at the right host for your set-up, and confirm the model tag is pulled. Setting
`AI_ENABLED=false` skips the model entirely and marks reports `disabled`.

Performance notes: Thai and especially Burmese output is token-heavy for the model, so translation calls run in
small batches with an output cap and retry with a delay on the low-priority `translations` queue. Burmese quality
from an 8B model is uneven; a larger model is the lever there.

---

## 5. Appendix

### 5.1 Identifier scheme

Frontend programs carry FE ids in their first-line comment (FE-PG pages, FE-CMP layout, FE-UPL, FE-PRO, FE-DEF,
FE-ANL, FE-RPT components, FE-HOOK, FE-SVC, FE-TYPE). Laravel endpoints carry API ids (AUTH, ANL, DATA, PROF, DEF,
RUN, INS, VIS, RPT, TRC). FastAPI endpoints carry PY-API ids. Features are FEAT-* and traced in
`traceability/feature_matrix.md`.

### 5.2 Known gaps noted while compiling this manual

- The `api` rate limiter (120/min) is defined but not attached to any route; the specific limiters above are what
  apply.
- `RunAnalysisRequest.options.max_insights` is validated but not forwarded; the run uses
  `config('dataspeak.analysis_options')` (seed 42, max_insights 10).
- `dataspeak.ai.report_language` exists in config but nothing reads it; the report language comes from the
  interface language at run time.
- The backend never calls FastAPI `/health` or Ollama `/api/tags`; use the commands in section 4.3 for checks.
- The analytics recommender does not propose pie charts itself; users add them at chart review.
