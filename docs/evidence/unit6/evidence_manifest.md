# Unit 6 Evidence Manifest

Data Speak, UoPeople MSIT 5910, Unit 6. All items were captured on **2026-10-13** (Asia/Bangkok, UTC+07:00) from the local development stack of repository commit `c4a1951` on `main` plus the uncommitted working tree described in [01_test_environment.md](01_test_environment.md). Account used in every browser capture: a dedicated local test account ("Latency Test", `latency-test-<timestamp>@example.com`), created for this evaluation and marked verified locally. Dataset used everywhere: the committed synthetic file `analytics/tests/golden/retail_sales.csv` (240 rows, no customer data). No production system, account or data was touched. No token, password or key appears in any image, log or CSV; the token lived in a mode-600 scratch file outside the repository and was passed to scripts through an environment variable.

## How each kind of image was captured

| Kind | Method | Why it is authentic |
| --- | --- | --- |
| Application screenshots (E-series) | Playwright 1.60 (Python) driving headless Chromium against the running frontend at `http://localhost:5173`, which calls the running Laravel API and FastAPI containers; viewport 1440 px wide at device scale 2 | The page content is produced by the live application from real API responses; nothing was drawn or edited |
| Terminal screenshots (B01, B02, B03, S-series) | A real macOS Terminal.app window was opened by AppleScript, the command was executed in it, and the window was captured with the system `screencapture` tool after the command finished (helper script kept outside the repo); the Terminal profile is translucent, so the desktop shows faintly behind the text | Each image is an operating-system screenshot of a live terminal session; the same output was simultaneously written to a log file listed below |
| Charts (B04, B04b) | matplotlib rendering of the per-request CSV files at 300 DPI | Every point is a value from the CSV; the mean line is computed from the same rows |

Earlier versions of B01, B02 and B03 in this folder were images rendered from log files. They were **replaced** on 2026-10-13 by the genuine terminal screenshots listed below; the notes in 02, 03 and 04 were updated accordingly.

## Items

| File | What it shows | Actual source or execution | Captured | Rubric criterion |
| --- | --- | --- | --- | --- |
| E01_upload_interface.png | React "New analysis" page: name field, CSV/Excel dropzone, privacy notice, five-step stepper | FE-PG-004 `NewAnalysisPage` served by the Vite dev container; logged in as the test account | 2026-10-13 23:50 | Part A, implementation evidence: CSV upload interface |
| E02_validation_results.png | The same page after uploading `unit6_synthetic_sales.csv` (11.1 KB): "File checked successfully, 240 rows and 7 columns detected", analysis named "Unit 6 evidence upload (synthetic)" | Live DATA-API-001 upload and DATA-API-002 validation through Laravel to FastAPI `/v1/validate` | 2026-10-13 23:50 | Part A: actual validation results |
| E03_executive_report.png | Completed executive report "Unit 6 latency test": KPI cards (240 records, 92,032 THB total, 999 units, 383.5 THB average), executive summary with claim links, findings 1 to 4 with charts (bar, bar, association table, trend line) and "What this chart shows" sentences marked *calculated* | RPT-API-002 for analysis `c74c7c9d-…`; pipeline run through `/v1/analyze`, `/v1/insights`, `/v1/visualizations/recommend`, narrative by the local Qwen model via Ollama, claims validated by `RuleBasedClaimValidator` (21 claims, 14 supported) | 2026-10-13 23:52 (report generated 23:47) | Part A: completed statistical-analysis output and executive report |
| E03b_executive_report_lower.png | Lower part of the same report after scrolling (remaining findings, limitations, data notes) | Same page, inner container scrolled to the end | 2026-10-13 23:52 | Part A: report evidence (continuation) |
| E04_profile_or_step.png | Profile step at a full-screen 1920 × 1080 viewport: rows 240, columns 7, completeness 97.9%, one quality note, and the columns table with suggested meanings, reasons, detected types, missing rates and summaries | PROF-API-002 data from FastAPI `/v1/profile` persisted in `dataset_columns`; retaken 2026-10-14 | 2026-10-14 00:52 | Part B: observed usability, data profile |
| E04b_profile_columns.png | Same page scrolled to the end of the columns table: the data-quality note (discount_pct 14.6% missing) and the "Continue to definitions" action | Same source | 2026-10-14 00:52 | Part B: observed usability, data profile |
| E05_dashboard.png | Analyses dashboard listing the test account's analyses with status badges | ANL-API-001 | 2026-10-13 23:50 | Part A: working application |
| E06_claim_traceability.png | "How we know" panel at 1920 × 1080 for one executive-summary claim: statement, "Checked against the calculated results" badge, the evidence card (Spearman rho = +0.71, Revenue by Units Sold) and the technical-details disclosure, over the report | TRC-API-001 `GET /analyses/{id}/claims/{claimId}`; retaken 2026-10-14 | 2026-10-14 00:52 | Part B: observed usability, evidence traceability |
| S01_react_api_client.png | `frontend/src/services/apiClient.ts` lines 50-110: `request()` with `Accept`, `X-Dataspeak-Language` and `Authorization: Bearer` headers, error envelope and 401 handling | Source file printed with `sed` and `nl` in Terminal | 2026-10-13 23:55 | Part A: React API client |
| S02_laravel_analytics_client.png | `backend/app/Services/Analytics/AnalyticsServiceClient.php` lines 19-66: `X-Internal-Token` header, `validate/profile/analyze/insights/recommendVisualizations`, `request()` | Source file in Terminal | 2026-10-13 23:55 | Part A: Laravel analytics client |
| S03_fastapi_analyze_endpoint.png | `analytics/app/api/v1/analyze.py` lines 1-55: `@router.post("/analyze")`, spooled upload, `analyze_frame()`, `AnalyzeResponse` | Source file in Terminal | 2026-10-13 23:55 | Part A: FastAPI analysis endpoint |
| S04_postgres_evidence_persistence.png | `backend/app/Services/Analytics/AnalysisResultPersister.php` lines 30-64: `persistEvidence()` inside `DB::transaction`, `evidence()->create([...])` | Source file in Terminal | 2026-10-13 23:55 | Part A: PostgreSQL evidence persistence |
| S05a_claim_validator_rules_2_to_5.png | `backend/app/Services/Validation/RuleBasedClaimValidator.php` lines 75-100: no-evidence, unknown-evidence, digits-outside-placeholder and placeholder-whitelist rules | Source file in Terminal | 2026-10-13 23:55 | Part A: claim validator |
| S05b_claim_validator_rules_6_to_8_and_decision.png | Same file lines 127-170: causal-language review, quality-versus-confidence rule, direction contradiction, final REJECTED / NEEDS_REVIEW / SUPPORTED decision | Source file in Terminal | 2026-10-13 23:55 | Part A: claim validator |
| B01_statistical_accuracy.png | Terminal: `pytest -v` on the three accuracy-related test files, 17 passed in 0.90 s | Execution; log `logs/02_pytest_statistical_accuracy_terminal_capture.log`; per-assertion values in `02_statistical_accuracy.csv` (33/33, from the 22:24 run, log `logs/02_pytest_statistical_accuracy.log`) | 2026-10-13 23:51 | Part B: statistical reference-test pass rate |
| B02_pytest_coverage.png | Terminal: full suite with coverage, 49 passed in 1.74 s, TOTAL 1381 statements, 99 missed, 93% | Execution; log `logs/03_pytest_results_terminal_capture.log`; detailed run in `03_pytest_results.log` (22:33) and `03_coverage_summary.md` | 2026-10-13 23:53 | Part B: test counts and line coverage |
| B03_api_latency.png | Terminal: latency benchmark run 2, 5 warm-ups + 30 sequential requests, 30/30 HTTP 200, mean 9.874 ms, median 9.945 ms, P95 10.629 ms | Execution of `scripts/api_latency.py` against the local API at 23:51:52; CSV `latency_results_run2.csv`; log `logs/04_api_latency_run2_terminal_capture.log` | 2026-10-13 23:51 | Part B: API latency |
| B04_latency_chart.png | 300 DPI line chart of run 1 (mean 9.071 ms line) | Generated from `latency_results.csv` (run 1, 22:37, the dataset reported in Table 1) | 2026-10-13 | Part B, Figure: response-time distribution |
| C01_deployment_configuration.png | VS Code editor window with `deploy/aws/docker-compose.prod.yml` open, lines 15-51: shared anchor (env_file, restart, depends_on, logging), `app` service (ports, volumes, health check), start of `worker` | macOS screenshot of the VS Code window, cropped to the window | 2026-10-14 00:26 | Part C: deployment configuration |
| C01b_deployment_configuration_part2.png | VS Code editor pane, lines 44-102: `worker`, `scheduler`, `redis`, `analytics` (no published ports, `${ANALYTICS_INTERNAL_TOKEN}` reference), `volumes` | macOS screenshot of the VS Code workspace window, cropped to the editor pane | 2026-10-14 00:26 | Part C: deployment configuration |
| C01t_deployment_configuration_terminal.png, C01t2_deployment_configuration_terminal_part2.png | The same file printed with line numbers (lines 1-62; 63-101 plus the nginx override) | Terminal window captures | 2026-10-14 00:14 | Part C: deployment configuration (alternative rendering) |
| C02_frontend_build.png | `npm ci && npm run build`: 417 packages in 5 s, 2,597 modules, built in 414 ms, exit 0, `dist/` listing; npm audit and Vite warnings visible | Execution; log `logs/C02_frontend_build.log` | 2026-10-14 00:15 | Part C: successful build evidence |
| C02c_docker_compose_config.png | `docker compose config --quiet` on `docker-compose.prod.yml` + `docker-compose.nginx.yml` with `.env.prod.example` (valid, exit 0), service/image/volume lists, resolved `app` service, development-stack `config --quiet` (valid) | Execution in `deploy/aws`; log `logs/C02c_docker_compose_config.log` | 2026-10-14 00:46:44 | Part C: successful configuration validation |
| C02b_laravel_tests.png | Laravel Framework 13.34.0; `php artisan test`: 148 passed, 903 assertions, 4.43 s | Execution; log `logs/C02b_laravel_tests.log` | 2026-10-14 00:15 | Part C: application verification |
| C03_application_health.png | Four read-only health requests: local FastAPI 200, local Laravel 200 (database ok, redis ok), production frontend HTTPS 200, production API HTTP/2 200 via nginx 1.28.3 (database ok, redis ok) | Execution; log `logs/C03_health_checks.log` | 2026-10-14 00:16 | Part C: health-check evidence |
| B04b_latency_chart_run2.png | 300 DPI line chart of run 2 (mean 9.874 ms line) | Generated from `latency_results_run2.csv` | 2026-10-13 | Part B: companion to B03 |

## Preserved logs and data

| File | Content |
| --- | --- |
| logs/02_pytest_statistical_accuracy.log | Verbose accuracy-test run at 22:24 (basis of the 33-case CSV) |
| logs/02_pytest_statistical_accuracy_terminal_capture.log | Same tests, run shown in B01 |
| logs/02_pytest_full_suite.log | Full suite, quiet, 22:24 |
| 03_pytest_results.log | Full suite with coverage, verbose, 22:33 (basis of 03_coverage_summary.md) |
| logs/03_pytest_results_terminal_capture.log | Full suite with coverage, run shown in B02 |
| latency_results.csv, logs/04_api_latency.log | Latency run 1, 22:37 (basis of 04, Table 1, B04) |
| latency_results_run2.csv, logs/04_api_latency_run2_terminal_capture.log | Latency run 2, 23:51 (shown in B03 and B04b) |
| 02_statistical_accuracy.csv, 05_quantitative_results.csv | Derived tables |
| coverage.xml, coverage_html/ | Machine-readable and browsable coverage reports from the 22:33 run |
| C06_build_health_logs.txt, logs/C02_*.log, logs/C03_health_checks.log, logs/C05_laravel_route_list.log, logs/C07_pytest_python312_ci_reproduction.log | Part C build, test, route, health and CI-reproduction logs (2026-10-14) |

## Notes and limitations

- Two latency runs exist. Run 1 is reported in Table 1 and the written sections; run 2 is the one visible in the terminal screenshot. Run 2 was slower on average (9.87 ms versus 9.07 ms); by then the test analysis had completed its full pipeline and a local model server and a headless browser were running on the same host. Both are documented in [04_api_latency.md](04_api_latency.md) section 4.3.
- The narrative in the report screenshots was produced by the local development model (Qwen via Ollama), not the Claude model configured for production.
- Terminal screenshots were taken through automation of Terminal.app and `screencapture`; they are operating-system screen captures, not renderings. The Terminal theme is translucent, which is why the desktop is faintly visible behind the text.
- Application screenshots come from a headless browser at a fixed viewport; they are the application's real rendering but were not taken by a person at a physical screen.
- Nothing in the source code or tests was modified to produce any result. The only repository additions are evidence files, two scripts under `scripts/`, and the output-validation test file added earlier on 2026-10-08.
