# Data Speak Feature Traceability Matrix

Audit date: 2026-10-03. Source of feature IDs: Data_Speak_Feature_List.docx v1.0. Audited commit: main b997096 plus uncommitted working tree.

Status definitions follow the audit prompt: Implemented requires production code **and** a passing automated test; code without a test is reported as Partially implemented - untested.

## Summary

| Status | Documented (v1.0) | Verified |
| --- | --- | --- |
| Implemented | 0 | 0 |
| Partially implemented - untested | 0 | 0 |
| Prototyped | 8 | 13 |
| Designed | 16 | 11 |
| Planned | 5 | 5 |

No feature is Implemented. The repository contains zero automated tests that exercise a Data Speak feature: backend has the two Laravel example tests, frontend has no test runner, analytics has only the health contract test added by this audit (failing).

The data dictionary column is Unknown for every row because `Data_Speak_Data_Dictionary.xlsx` does not exist in the repository or anywhere on this machine.

## Matrix

| Feature ID / name | Scope | Documented status | Verified status | Module(s) | Laravel endpoint(s) | FastAPI endpoint(s) | Frontend program(s) | DB tables | Code paths | Tests | Dependencies met? | Gaps / notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| FEAT-AUTH-001 User Registration | MVP | Prototyped | Prototyped | MOD-01 | AUTH-API-001 | - | FE-PG-002 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/LoginScreen.tsx (register tab); frontend/src/services/api.ts register() [mock]; backend/database/migrations/0001_01_01_000000_create_users_table.php (framework default) | None | n/a | No Laravel endpoint, no Sanctum, no API routes file. Mock register resolves any input. |
| FEAT-AUTH-002 User Login/Logout | MVP | Prototyped | Prototyped | MOD-01 | AUTH-API-002, AUTH-API-003, AUTH-API-004 | - | FE-PG-001, FE-HOOK-002 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/LoginScreen.tsx; frontend/src/App.tsx handleAuth/handleLogout; frontend/src/services/api.ts login() [mock] | None | n/a | Mock login accepts any non-empty credentials. No token, no session, no protected routes. |
| FEAT-ANL-001 Analysis Dashboard | MVP | Prototyped | Prototyped | MOD-02 | ANL-API-001 | - | FE-PG-003, FE-CMP-005 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/DashboardScreen.tsx; frontend/src/components/StatusBadge.tsx; frontend/src/services/api.ts listAnalyses() [mock] | None | n/a | Four hard-coded analyses. Status enum is lowercase and includes 'generating', which the state model lacks. |
| FEAT-ANL-002 Create Analysis | MVP | Prototyped | Prototyped | MOD-02 | ANL-API-002 | - | FE-PG-004, FE-UPL-002 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/UploadScreen.tsx (name field, auto-fill from filename) | None | n/a | Creation is implicit in the mock upload; no analysis shell request. |
| FEAT-UPL-001 CSV Upload | MVP | Prototyped | Prototyped | MOD-03 | DATA-API-001 | - | FE-UPL-001 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/UploadScreen.tsx (dropzone); frontend/src/services/api.ts uploadCsv() [mock] | None | n/a | SCOPE CREEP: accepts .xlsx and .xls (UploadScreen.tsx lines 9, 18, 38, 94, 123). Excel is out of MVP scope. Nothing is transmitted. |
| FEAT-UPL-002 CSV Validation | MVP | Designed | Designed | MOD-03 | DATA-API-002 | PY-API-003 | FE-UPL-003 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None. Branch origin/feature/csv-validation adds only docs/requirements/csv-validation.md containing the word 'Test'. UploadScreen.tsx does a client-side extension and 50 MB size check only. | None | Blocked: needs FEAT-UPL-001 real upload | No structural validation anywhere. |
| FEAT-UPL-003 Transient Raw Data Handling | MVP | Designed | Designed | MOD-03 | DATA-API-001 | PY-API-002/003/004 (temp-file disposal) | FE-UPL-004 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None. frontend/src/screens/UploadScreen.tsx line 131 shows the privacy sentence only. | None | n/a | No temp storage, TTL, cleanup or purge exists. Nothing today persists CSV because nothing uploads. |
| FEAT-PRO-001 Dataset Profiling | MVP | Designed | Prototyped | MOD-04 | PROF-API-001, PROF-API-002 | PY-API-002 | FE-PG-005, FE-PRO-001..004 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/ProfileScreen.tsx (412 lines); frontend/src/services/api.ts getProfile() [mock] | None | Blocked: needs validated transient CSV | STATUS CHANGE Designed -> Prototyped: UI exists. Inline fabricated figures (48,215 rows, 98.6% completeness, histogram buckets at lines 110-112, 232, 235). No profiling code in analytics/. |
| FEAT-DEF-001 Business Field Definitions | MVP | Prototyped | Prototyped | MOD-05 | DEF-API-001 | - | FE-PG-006, FE-DEF-001, FE-DEF-004 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/DefineScreen.tsx (389 lines); frontend/src/services/api.ts getDefaultDefinitions()/saveDefinitions() [mock] | None | Blocked: needs profiled columns | Save is a 400 ms delay. |
| FEAT-DEF-002 Semantic Types and Units | MVP | Designed | Prototyped | MOD-05 | DEF-API-001 | - | FE-DEF-002, FE-DEF-003 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/DefineScreen.tsx; frontend/src/types.ts ColumnRole/ColumnUnit/AggregationType | None | Blocked: needs profiled columns | STATUS CHANGE Designed -> Prototyped. CONFLICT: prototype uses roles Measure/Dimension/Time/Identifier/Ignore and a fixed unit list including a hard-coded baht; spec uses semantic_type currency/date/category/number/text with free unit. Dictionary must decide. |
| FEAT-ANA-001 Descriptive Statistics | MVP | Designed | Designed | MOD-06 | RUN-API-001 | PY-API-004 | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None. analytics/app/main.py has only / and /health. | None | Blocked: needs validated dataset + definitions | No analysis code. SciPy/statsmodels/Polars are installed in analytics/.venv but unused. |
| FEAT-ANA-002 Trend Analysis | MVP | Designed | Designed | MOD-06 | RUN-API-001 | PY-API-004 | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None | None | Blocked | No code. |
| FEAT-ANA-003 Group Comparison | MVP | Designed | Designed | MOD-06 | RUN-API-001 | PY-API-004 | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None | None | Blocked | No code. |
| FEAT-ANA-004 Association Analysis | MVP | Designed | Designed | MOD-06 | RUN-API-001 | PY-API-004 | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None | None | Blocked | No code. |
| FEAT-INS-001 Insight Detection | MVP | Designed | Designed | MOD-07 | INS-API-001 | PY-API-005 | FE-RPT-002 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None (report UI shows mock insights, see FEAT-RPT-001) | None | Blocked: needs deterministic evidence | No code. |
| FEAT-INS-002 Insight Ranking | MVP | Designed | Designed | MOD-07 | INS-API-001 | PY-API-005 | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None | None | Blocked | No code. Prototype 'rank' field is mock. |
| FEAT-VIS-001 Visualization Recommendation | MVP | Designed | Designed | MOD-08 | VIS-API-001 | PY-API-006 | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None | None | Blocked: needs evidence + semantic metadata | No code. |
| FEAT-VIS-002 Chart Rendering | MVP | Prototyped | Prototyped | MOD-08 | VIS-API-001 | - | FE-RPT-004 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/components/ChartCard.tsx (Recharts line/bar); frontend/src/screens/ProfileScreen.tsx (bar charts) | None | Blocked | PRODUCT RULE 4 FAIL: currency hard-coded (8 occurrences in ChartCard.tsx, 17 in ProfileScreen.tsx). Chart data embedded in mock, not a backend spec. |
| FEAT-AI-001 AI Insight Synthesis | MVP | Planned | Planned | MOD-09 | - | - | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None. No LLM client in frontend/, backend/ or analytics/ (grep for openai/anthropic/qwen/vllm/llm: no hits). | None | Blocked: needs evidence | Correctly absent. |
| FEAT-AI-002 Structured Claim Generation | MVP | Planned | Planned | MOD-09 | - | - | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None | None | Blocked | Correctly absent. |
| FEAT-VAL-001 Claim Validation | MVP | Planned | Planned | MOD-10 | - | - | FE-RPT-002 (display only) | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None. frontend/src/screens/ReportScreen.tsx and components/TraceabilityPanel.tsx display mock claim status supported/rejected. | None | Blocked: needs claims + evidence | Display-only; no validator. |
| FEAT-RPT-001 Executive Report | MVP | Prototyped | Prototyped | MOD-11 | RPT-API-001, RPT-API-002 | - | FE-PG-008, FE-RPT-001..003, FE-RPT-005 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/ReportScreen.tsx (240 lines); frontend/src/services/api.ts getReport() [mock] | None | Blocked: needs validated claims + metrics + specs | PRODUCT RULE 6 FAIL: fabricated narrative and figures (e.g. 'Total revenue reached 14.10M') shipped in a production module. |
| FEAT-RPT-002 Claim Traceability | MVP | Designed | Prototyped | MOD-12 | TRC-API-001 | - | FE-PG-009, FE-RPT-006 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/components/TraceabilityPanel.tsx (119 lines); mock evidence in services/api.ts | None | Blocked | STATUS CHANGE Designed -> Prototyped. Claim type requires evidenceId (types.ts) but only at type level with mock data. |
| FEAT-RPT-003 PDF Export | MVP/Stretch | Planned | Planned | MOD-11 | - | - | FE-RPT-007 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/ReportScreen.tsx line 115 renders an 'Export PDF' primary button with no handler. | None | Blocked | Button should be disabled with a coming-soon state per frontend prompt. |
| FEAT-OPS-001 Processing Status | MVP | Designed | Prototyped | MOD-02 | RUN-API-002 | - | FE-PG-007, FE-ANL-002, FE-ANL-003 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/AnalyzingScreen.tsx (simulated stage ticks, lines 30-63); frontend/src/components/Stepper.tsx | None | Blocked | STATUS CHANGE Designed -> Prototyped. Progress is a timer, not polling. No aria-live region anywhere (NFR-ACC-001). |
| FEAT-OPS-002 Retry/Failure Handling | MVP | Designed | Prototyped | MOD-02 | RUN-API-001, RUN-API-002 | - | FE-ANL-004 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | frontend/src/screens/AnalyzingScreen.tsx handleRetry (line 65); frontend/src/screens/DashboardScreen.tsx Retry action (line 132) | None | Blocked | STATUS CHANGE Designed -> Prototyped. Retry resets a local timer; no FAILED state transition exists server-side. |
| FEAT-SEC-001 Resource Authorization | MVP | Planned | Planned | MOD-01 | all /analyses/{id} routes | - | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None. No policies, middleware or auth routes in backend/app. | None | Blocked: needs auth | Correctly absent. |
| FEAT-SEC-002 Privacy-Safe Logging | MVP | Designed | Designed | MOD-03, MOD-06 | - | PY-API-002..006 | - | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | None. backend/config/logging.php is the framework default (stack/single, text format). analytics has no logging config. No redaction anywhere. | None | n/a | Overlaps NFR-OBS-001. Risk: frontend/vite.config.ts lines 63-171 can inject Google Analytics gtag when site.json sets googleAnalyticsId (currently unset). |
| FEAT-I18N-001 Unicode Data Labels | MVP | Designed | Designed | MOD-05, MOD-08 | - | - | FE-DEF-001, FE-RPT-004 | Unknown - Data_Speak_Data_Dictionary.xlsx not present in repo or on disk | Partial infrastructure only: running Postgres container reports server_encoding UTF8; backend/config/database.php pgsql charset utf8; frontend/src/index.css font stack is Inter + system-ui with no Noto Sans Thai or Noto Sans Myanmar; index.html lang attribute is a Figma placeholder. | None | n/a | No Thai/Myanmar fixture or test exists. |

## Layer gaps

Every feature that needs a Laravel endpoint, a FastAPI endpoint or a database table has no mapping in that layer. Only the frontend layer has code, and all of it is prototype code bound to `frontend/src/services/api.ts` mock data.

## Orphan code and endpoints (map to no feature)

- `analytics/app/main.py` `GET /` root route: not in the FastAPI endpoint catalogue.
- `analytics/app/main.py` `GET /health`: PY-API-001, infrastructure rather than a feature; returns `{"status":"ok"}` instead of the documented body.
- `backend/routes/web.php` `GET /` welcome view and the framework `/up` route.
- `backend/database/migrations/*` cache, jobs, sessions, password reset tables: Laravel infrastructure, acceptable, but not in the data dictionary until it exists.
- `analytics/.venv/` about 9,156 files staged in git.
- `frontend/index copy.html`, `frontend/package copy.json`, `frontend/tsconfig copy.json`: byte-identical duplicates.
- `frontend/src/imports/*.png`: two ChatGPT-generated images plus logo variants, unused by the spec.
- `frontend/vite.config.ts` Figma Make plugins including Google Analytics injection.
- `docker/docker-compose.yml`: one line of shell text, not a compose file.
- Branch `feature/csv-validation`: one doc file containing the word Test.

## Product rule results (Feature List Section 7)

| # | Rule | Result | Evidence |
| --- | --- | --- | --- |
| 1 | No critical numeric value invented by an LLM | Untested | No LLM and no analytics engine exist. Every number shown today is fabricated prototype data, which fails rule 6 rather than rule 1. |
| 2 | Raw rows not sent to external AI services | Pass (vacuous) | No AI client code exists in any service. No automated guard test. |
| 3 | Every final claim has an evidence relationship | Untested | `frontend/src/types.ts` Claim requires `evidenceId` at type level only; no backend relationship exists. |
| 4 | Charts display correct units and labels | Fail | Currency hard-coded in `ChartCard.tsx` (8) and `ProfileScreen.tsx` (17); `types.ts` fixes units to a baht literal. |
| 5 | Distinguish warnings, insufficient data, findings | Fail | No occurrence of warning, insufficient or not-enough wording in `frontend/src`; no quality status in any type. |
| 6 | Mock values never presented as production results | Fail | `services/api.ts` mock is imported by `LoginScreen`, `DashboardScreen`, `ReportScreen`, `TraceabilityPanel`; inline figures in `ProfileScreen.tsx` lines 110-112, 232, 235. These ship in the production bundle. |

## Scope boundary findings

- **Excel upload accepted** in `frontend/src/screens/UploadScreen.tsx` (.xlsx, .xls in accept list, extension regex, error copy, helper text). Out of MVP scope.
- No direct DB connectors, streaming, forecasting, NL querying, SSO or automated actions found.
- `pandas` is installed in `analytics/.venv` as a statsmodels dependency; no code uses it. Not creep, noted for the Polars rule.

## Tests added by this audit

- `analytics/tests/test_health_contract.py` (2 tests) and `analytics/pytest.ini`. Both tests fail: health body lacks `service` and `version`; undocumented `GET /` returns 200. Production code was not changed.
- No end-to-end, privacy, authorization or retry tests were written because no feature code exists at those layers. They are listed as gaps.

## Top gaps blocking MVP acceptance, in dependency order

1. Data dictionary missing: blocks migrations, every enum, and three downstream prompts.
2. Repository foundation not done: no CI, no compose for all services, venv staged in git, no lint or test runners in frontend and analytics.
3. No database schema beyond framework tables.
4. No analytics endpoints beyond health (validate, profile, analyze, insights, visualizations).
5. No Laravel API: auth, lifecycle, transient upload, orchestration, persistence.
6. Frontend not wired to any API; mock data in production modules; Excel accepted; no aria-live; no Thai/Myanmar fonts.
7. No privacy or authorization tests anywhere.
8. AI, claims, validation remain Planned, as documented.

## Recommended updates to Feature List v1.0 (proposed text only)

- Change status to Prototyped for FEAT-PRO-001, FEAT-DEF-002, FEAT-RPT-002, FEAT-OPS-001, FEAT-OPS-002, with the note 'Figma Make prototype, mock data'.
- FEAT-UPL-001: add 'CSV only; Excel explicitly rejected with a message' to the description so the prototype's Excel acceptance is a tracked defect.
- FEAT-DEF-002: state the authoritative semantic type vocabulary once the dictionary exists and retire the prototype role vocabulary.
- FEAT-SEC-002: cross-reference NFR-OBS-001 and add 'no third-party telemetry scripts in the frontend bundle'.
- FEAT-RPT-003: specify the disabled coming-soon state for the export control.
- Add a Verification Evidence column (code path plus test name) so status changes are auditable in future revisions.


## Status update, 4 October 2026 (post-audit implementation)

The rows above are the audit snapshot taken before implementation. Since then:

| Feature | Status | Where |
| --- | --- | --- |
| FEAT-AUTH-001 User Registration | Implemented with email verification (AUTH-API-001, 005, 006; FE-PG-002, FE-PG-010; `verified.api` middleware; Mailpit in compose) | `backend/app/Http/Controllers/Api/V1/EmailVerificationController.php`, `frontend/src/pages/VerifyEmailPage.tsx`, `EmailVerificationTest` |
| FEAT-AUTH-002 User Login/Logout | Implemented (Sanctum bearer tokens; unverified accounts are routed to the confirmation screen) | `AuthController`, `useAuth`, `AuthTest` |
| FEAT-I18N-001/002 Unicode data labels, English-only report | Implemented (Thai/Myanmar headers and values accepted end to end; English display labels built after the run via `DisplayLabelTranslator`, applied in every presentation path; `NON_ENGLISH_TEXT` validator rule) | `backend/app/Services/Reports/DisplayLabels.php`, `backend/app/Services/Ai/DisplayLabelTranslator.php` |
| FEAT-VIS-003 Chart review and user-chosen charts | Implemented (run stops at ANALYZED; VIS-API-002/003; `ChartCatalog`; report built from kept charts only) | `backend/app/Services/Reports/ChartCatalog.php`, `frontend/src/components/analyze/ChartReviewPanel.tsx`, `ChartSelectionTest` |
| FEAT-DEF-004 Reasoned column definitions | Implemented (model explains each meaning and aggregation; engine size, year, price per item are averaged, never totalled; software baseline uses header words) | `backend/app/Services/Ai/ColumnDefinitionSuggester.php`, `DefinitionSuggestionTest`, `frontend/src/components/define/FieldDefinitionTable.tsx` |
| FEAT-VIS-006 Chart reasons | Implemented (every recommended or offered chart explains, in the interface language, why it fits the column definitions: totals only for accumulating amounts, averages for per-item properties, lines for time series) | `backend/app/Services/Reports/ChartReasons.php`, `backend/lang/*/charts.php` (`reasons`), `ChartSelectionTest`, `frontend/src/components/analyze/ChartReviewPanel.tsx` |
| FEAT-VIS-005 Pie chart | Implemented (`PIE` in `chart_type`; `ChartCatalog` offers a share-of-total pie for every group aggregate with 2 to 8 non-negative parts; web `ChartRenderer` and PDF `ChartSvg::pie`; localized title `charts.share_by`) | `backend/app/Services/Reports/ChartCatalog.php`, `backend/app/Services/Reports/Pdf/ChartSvg.php`, `ChartSvgTest`, `ChartSelectionTest` |
| FEAT-VIS-004 Chart colour theme | Implemented (six palettes chosen at chart review, stored in `analyses.chart_theme`, applied to the web report and the PDF) | `backend/app/Support/ChartThemes.php`, `frontend/src/utils/chartThemes.ts`, `ChartSelectionTest::test_colour_theme_*`, `ChartSvgTest` |
| FEAT-I18N-003 Interface and report languages | Implemented (English default; Thai, Chinese, Burmese for every page, the narrative, software sentences, chart titles, labels and the PDF) | `frontend/src/i18n/`, `backend/lang/`, `backend/app/Support/Locale.php`, `ReportLanguageTest` |
| FEAT-RPT-003 PDF Export | Implemented (RPT-API-003; cover, letterhead, timestamped and numbered footer, SVG charts, Thai/Myanmar fonts; FE-RPT-007 enabled) | `backend/app/Services/Reports/Pdf/`, `resources/views/pdf/report.blade.php`, `frontend/src/components/report/ExportPdfAction.tsx`, `ReportPdfTest`, `ChartSvgTest` |
