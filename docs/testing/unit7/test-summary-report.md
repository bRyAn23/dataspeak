# Data Speak Unit 7 Test Summary Report

University of the People, MSIT 5910-01 Capstone Project, Unit 7 Part A: System Testing and Documentation. Prepared 2026-10-15. System under test: Data Speak at commit `e5ecc93` (`main`), executed on the local development stack recorded in `evidence/environment/`. All counts in this report come from the execution records in `test-cases.csv`, `evidence/system_test_results.json`, `evidence/regression/*.log` and `performance-results.csv`.

## 1. Test Objectives

Integrated system verification establishes that the separately tested parts of Data Speak work together as a product: that the React client, the Laravel API and queue worker, the FastAPI analytics service, PostgreSQL, Redis and the language-model gateway exchange data correctly through their real interfaces; that a user can complete the full workflow from upload to a verified executive report; that the deterministic calculations remain numerically correct; that failures are reported truthfully rather than as success; and that response times for realistic dataset sizes are known. The testing also serves the project's governing rule that the software calculates, the model interprets and validation verifies, by checking that only validated claims reach the report.

## 2. Test Scope

**Tested:** authentication and authorization; CSV upload with valid, invalid and unsupported inputs; profiling and missing-value handling; definition confirmation; deterministic analysis and insight ranking; chart recommendation and rendering; AI narrative generation with the local Qwen model; claim validation and traceability; idempotent and repeated processing; analytics-service outage and recovery; FastAPI contracts and numerical correctness; performance at 100, 1,000 and 10,000 rows.

**Excluded:** the production environment and the Claude provider (read-only reads of one production report were used for defect evidence only); PDF rendering and translations beyond their unit tests; email delivery; concurrency and load; penetration testing; cross-browser and mobile layouts; XLSX conversion beyond unit tests.

## 3. Test Environment

| Item | Value |
| --- | --- |
| Date and time | 2026-10-15, 15:31 to 16:05 (+07:00, Asia/Bangkok) for the integration, system, regression and performance runs; the eight terminal figures were retaken 18:14 to 18:16 because the screen was locked during the first captures, and the four regression suites were re-executed for those figures with identical results |
| Commit / branch | `e5ecc93ae806427734e982162b2b4093517c71a2` / `main` (5 uncommitted working-tree entries at the start, 6 after the untracked `docs/testing/unit7` tree was created; none in application code) |
| Host | macOS 26.6.2, Apple M5 Pro, 64 GiB; Docker 29.8.1, Compose 5.5.1 |
| Services | Vite dev server (Node 24.21.0); Laravel 13.34.0 on PHP 8.4.26 (PHP built-in server in container); queue worker; FastAPI on Python 3.12.15; PostgreSQL 17.11; Redis 7.4.11; Mailpit; Ollama with `qwen3:8b` on the host |
| Test database | `dataspeak` in the local Docker volume (development data only); backend unit tests use in-memory SQLite |
| Tools | Playwright 1.60 (Chromium), httpx, pytest 8.4.2 with pytest-cov 7.1, PHPUnit 12.5, Vitest 3.2.7, docker compose, psql (read-only) |
| Test data | Synthetic `retail_sales.csv` (240 rows, 7 columns, 14.6% missing discounts); synthetic 100 / 1,000 / 10,000-row datasets; invalid inputs (duplicate and empty headers, header-only, empty, plain text) |
| Health at start | Laravel `ok` (database ok, redis ok); FastAPI `ok`; frontend 200; Mailpit 200 |

Figure 01 (`evidence/environment/figure-01-test-environment.png`) shows the recorded environment.

## 4. Test Methodology

- **Integration (INT-01 to INT-08):** real HTTP calls between components with assertions on status codes, bodies, database rows read through `psql`, and the analytics container log. One failure injection stopped the local analytics container (INT-06) and restarted it. Distinction from mocked tests: the backend and frontend unit suites fake FastAPI and the model; the integration cases here used the real services.
- **System (SYS-01 to SYS-12):** headless Chromium drove the real UI through each workflow; each case asserted rendered text, API state or database counts and saved a screenshot. Report generation used the local model.
- **Regression (REG-01 to REG-04):** the existing suites were executed unchanged and logged at 15:38 and again at 18:14 (for the figures); both runs gave the same counts. The 33 golden-reference assertions were reproduced with the Unit 6 extraction script.
- **Performance (PERF-1 to PERF-3):** sequential single-client harness; FastAPI endpoints five runs per size (median and p95); end-to-end through Laravel one run per size; API latency over 30 requests; `docker stats` before and after; HTTP error count.
- **Acceptance:** investigator-led inspection of twelve requirement-based criteria using the executed cases as evidence (no external users).
- **Triage rule:** every first-run failure was investigated before any retest; the original record is preserved and a retest case (suffix R) records the corrected expectation or precondition. No application code was changed.

## 5. Test Execution Results

| Testing Type | Executed | Passed | Failed | Blocked | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| Integration (INT-01..08, with INT-07R) | 8 | 8 | 0 | 0 | 0 |
| System / end-to-end (SYS-01..12, LIM-01, DEF-CHK-01/02, with retests) | 15 | 15 | 0 | 0 | 0 |
| Regression suites (REG-01..04) | 4 | 4 | 0 | 0 | 0 |
| Performance scenarios (PERF-1..3) | 3 | measured, no threshold | 0 | 0 | 0 |
| **Total** | **30** | **27 passed + 3 measured** | **0** | **0** | **0** |

First-run failures before triage: INT-07, SYS-04, SYS-05, SYS-12 and LIM-01 (5 of 24 first-run cases). All five were traced to harness assumptions, not product behaviour, and are documented in the defect register under "test-harness issues"; the retests passed. Individual test counts inside the regression suites: analytics 49 passed (including 17 golden and statistical tests and 2 contract tests), backend 148 passed with 903 assertions, frontend 28 passed in 14 files; 33 of 33 golden-reference assertions reproduced. Figure 02 shows the recorded results table.

## 6. Defects and Limitations

Eight product defects are open (none fixed during testing, by rule): DEF-01 (Major) a claim with doubled braces passes validation and renders literal braces, confirmed from the stored production claim and traced to the validator's placeholder regex and the renderer; DEF-04 (Medium) a single queue worker lets background translation jobs delay user-facing jobs by minutes, seen in the performance run; DEF-06 (Medium) the CI analytics job fails on the frozen OpenAPI contract through dependency drift; and five Minor items: repeated headline sentence (DEF-02), "rho" shown to business readers (DEF-03), disabled "Run again" without explanation (DEF-05), contrast and target-size accessibility failures (DEF-07), missing-value warning below the fold (DEF-08). DEF-01, DEF-02 and DEF-03 were observed on the production report generated by the Claude provider and did not reproduce in the local Qwen report, so they depend on model output and remain open. Six limitations are recorded (transient-file re-run rule, non-deterministic narrative stage, local-versus-production differences, limited failure injection, no load testing, no external users). Full detail: `defect-register.md`; evidence for the main defect: `evidence/system/def01_production_claim_with_literal_braces.json`, Figure 08.

## 7. Performance Findings

Workload: synthetic datasets of 100, 1,000 and 10,000 rows (7 columns); sequential single client on the laptop-hosted stack; AI narrative stage excluded from repeated timing loops. No HTTP errors occurred in any performance call.

| Measure | 100 rows | 1,000 rows | 10,000 rows |
| --- | --- | --- | --- |
| FastAPI `/v1/validate`, median (p95), n=5 | 0.010 s (0.014) | 0.014 s (0.016) | 0.027 s (0.029) |
| FastAPI `/v1/profile`, median (p95), n=5 | 0.024 s (0.025) | 0.032 s (0.035) | 0.079 s (0.088) |
| FastAPI `/v1/analyze`, median (p95), n=5 | 0.062 s (0.064) | 0.138 s (0.153) | 0.286 s (0.296) |
| Laravel upload + validate round trip, n=1 | 0.076 s | 0.049 s | 0.059 s |
| Profiling job to PROFILED (incl. local-model suggestions), n=1 | 73.3 s* | 12.2 s | 14.2 s |
| Run job to ANALYZED, n=1 | 128.4 s* | 2.1 s | 2.1 s |
| Upload to ANALYZED total, n=1 | 201.8 s* | 14.4 s | 16.4 s |

\*The 100-row run started while three background `TranslateReportJob` runs from the preceding system test occupied the single worker; the worker log shows the profiling job itself took 11 s and the run job 0.16 s once dequeued (DEF-04). Other measurements: `GET /analyses/{id}/status` median 9.3 ms, p95 9.8 ms over 30 requests; executive report generation with the local 8B model 94 s (SYS-09, one run); analytics container memory 317 MiB before and 481 MiB after the 10,000-row sequence, other containers unchanged; CPU under 3% at both snapshots. FastAPI time grows roughly linearly with rows (analyze 0.06 s to 0.29 s over two orders of magnitude). The project defines no performance thresholds, so these are observed values, not a pass/fail evaluation against a service-level objective. Figure 06 shows the recorded table.

## 8. Reliability and Quality Evaluation

**Functional reliability.** Every workflow stage executed end to end with real services, and the complete user path from login to a traced claim was demonstrated in one browser session. The five first-run failures were harness errors, which is itself informative: the product behaved more defensively than the test author assumed (client-side file rejection, disabled re-run after purge). The outage test showed the backend failing closed with a specific error code and recovering without manual repair.

**Analytical correctness.** The deterministic layer is the strongest part of the evidence: 33 reference assertions held, the engine's mean matched an independent calculation to 1e-9 in a live integration call, evidence ids were identical across two processings of the same file, and 75 evidence records were produced for every dataset size. Coverage of 93% describes test reach, not accuracy, and is reported separately.

**Integration stability.** Contracts between Laravel and FastAPI held for valid, invalid and oversized-schema inputs; the internal token boundary rejected unauthenticated calls; database rows matched API responses. The one integration weakness is operational rather than functional: a single worker serialises background translation behind user jobs (DEF-04).

**Error handling.** Invalid files, unsupported types, outages and unauthorized access all produced controlled, specific responses; nothing reported success falsely. The remaining gap is explanatory, not protective: the disabled "Run again" control gives no reason (DEF-05).

**Performance.** Deterministic processing is fast relative to any interactive expectation (under 0.3 s for 10,000 rows in FastAPI, about 2 s for the queued run). Perceived latency is dominated by model calls (12 to 14 s for suggestions, 94 s for the narrative locally) and by queue scheduling. No threshold was predefined, so no claim of meeting a target is made.

**Maintainability.** Three automated suites with 225 tests ran green in under 15 s combined, and a frozen API contract exists; however, the CI pipeline is red because of that very contract test and unpinned dependencies (DEF-06), which undermines the value of the suites until fixed.

**Remaining limitations.** The narrative stage is model-dependent; the most serious defect (DEF-01) appeared only with the production provider and shows that validation must guard against malformed placeholders regardless of model. Tests ran on a developer laptop against a dev server and a local model; production behaviour under real load, with the production model, was not measured here.

## 9. Overall Assessment

Against the criteria defined in the test plan, the tested implementation meets them in the local environment: all 23 integration and system cases pass after triage with no blocked or skipped cases, all four regression suites pass, numerical correctness is reproduced, and the acceptance inspection found all twelve criteria met, two with documented exceptions. The evidence supports the conclusion that the core pipeline is functionally reliable and analytically correct for the inputs tested. It does not support a claim that the complete product is production-ready: one Major defect in claim validation is open, the CI pipeline fails, queue scheduling can delay users by minutes under background load, and production performance and load behaviour were not measured. Those items, with the accessibility and messaging defects, form the recommended work before any release decision.

## Academic Evidence Summary

- **Executed tests by level:** integration 8 (all pass), system 15 (all pass after retests of 4 harness errors and 1 re-checked limitation), regression suites 4 (all pass: analytics 49 tests, backend 148 tests / 903 assertions, frontend 28 tests), performance scenarios 3 (measured). First-run failures: 5, all attributed to the harness; product defects open: 8; blocked: 0; skipped: 0.
- **Reproduced numerical correctness:** 17 golden and statistical pytest cases passed; 33/33 reference assertions (26/26 numeric) reproduced on 2026-10-15; live engine mean equal to an independent calculation within 1e-9.
- **Current code coverage:** analytics 93% line coverage (1,381 statements, 99 missed; 92.83% exact), measured 2026-10-15; coverage is not a measure of statistical accuracy.
- **Performance:** FastAPI analyze 0.06 / 0.14 / 0.29 s for 100 / 1,000 / 10,000 rows (median of 5); queued run about 2 s; profiling with model suggestions 12 to 14 s; status endpoint 9.3 ms median; narrative 94 s with the local model; zero HTTP errors; one scheduling anomaly explained by queue contention.
- **Outstanding defects and limitations:** DEF-01 malformed placeholders pass validation (Major); DEF-04 queue contention and DEF-06 CI failure (Medium); DEF-02, 03, 05, 07, 08 (Minor); limitations LIM-01 to LIM-06.
- **Figures:** `figure-01-test-environment.png`, `figure-02-integration-test-results.png`, `figure-03-valid-csv-workflow.png`, `figure-04-invalid-csv-feedback.png`, `figure-05-statistical-validation.png`, `figure-06-performance-test-results.png`, `figure-07-acceptance-verification.png`, `figure-08-defect-or-limitation.png` (captions in `evidence/figures.md`).
- **Evidence package:** `docs/testing/unit7/` (discovery, plan, test-cases.csv, acceptance checklist, performance CSV, defect register, traceability, this report) and `docs/testing/unit7/evidence/` (environment, integration JSON, system screenshots, regression logs, performance raw data, logs).
