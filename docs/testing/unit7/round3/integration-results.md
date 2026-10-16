# Round 3 Integration Test Results

Data Speak, Unit 7 Test Round 3. Executed by `../scripts/system_tests.py` (unchanged Round 1/2 harness, output redirected with `U7_EVIDENCE_DIR`) on 2026-10-16 22:58–23:02 (+07) against the running local stack (recovery run; the first attempt of 21:42, void because the default worker was down, is preserved under `evidence/attempt1-worker-down/`). Records: `evidence/system_test_results.json|csv`, `evidence/integration/*.json`, `evidence/logs/system_tests_round3.log`. All cases below ran against **real services**; the only mocked integration tests in this round are the automated suites listed in `regression-results.md` (FastAPI TestClient, PHPUnit with faked analytics and model, Vitest with MSW).

## 1. Results

| ID | Interface | Check (request / response / state) | Result | Duration | Evidence |
| --- | --- | --- | --- | ---: | --- |
| INT-01 | React → Laravel | Browser login and dashboard: `POST /auth/login` 200, `GET /analyses` 200 observed from the page; list rendered from the response | PASS | 3.8 s | `evidence/system/sys01_api_calls_from_react.json` |
| INT-02 | Laravel → FastAPI (`/v1/validate`) | upload 201 (multipart), validate 200, contract fields rows 240 / cols 7; no dataset rows in the analytics log | PASS | 1.2 s | `int02_laravel_to_fastapi_validate.json` |
| INT-03 | Laravel → PostgreSQL | status API `VALIDATED`, listed in `GET /analyses`; `analyses` row `VALIDATED|240|7|retail_sales.csv` read directly from PostgreSQL | PASS | 0.1 s | `int03_laravel_postgres.json` |
| INT-04 | FastAPI direct | validate 200 valid, profile 200 (7 columns), analyze 200 with 75 evidence records across the families descriptive, trend, group_comparison, association, quality, summary; engine mean cross-checked independently | PASS | 0.1 s | `int04_fastapi_valid_csv.json` |
| INT-05 | Input validation at both boundaries | header-only 200/valid=False, empty file 422, no token 401, duplicate headers rejected, Laravel 422 `CSV_VALIDATION_FAILED`, `.txt` 422 | PASS | 0.1 s | `int05_invalid_input.json` |
| INT-06 | Service failure → error handling | analytics container stopped: `POST /validate` 503 `ANALYTICS_UNAVAILABLE` "The analytics service is unavailable."; status stays UPLOADED; after restart `/health` ok and retry 200 | PASS | 8.6 s | `int06_analytics_outage.json` |
| INT-07 | Laravel → Redis queue → FastAPI → PostgreSQL (results retrieval) | queued profile and run jobs: final ANALYZED, 7 insights with evidence keys, 8 charts, job SUCCEEDED; database evidence/insights/charts = 75/7/8 | PASS | 20.5 s | `int07_results_retrieval.json` |
| INT-08 | Laravel → claim validation → report persistence | report claims_total 9, supported 5; statuses sampled SUPPORTED; database: REJECTED claims have `in_report = false` (4), SUPPORTED out of report (2), SUPPORTED in report (3) | PASS | 0.8 s | `int08_claim_validation.json` |

**8 executed, 8 passed, 0 failed, 0 blocked.** Round 1: 8/8 after one harness correction (INT-07R); Round 2: 7/8 in the first attempt (INT-08 blocked by DEF-09) then 8/8 with INT-08b.

## 2. Interfaces required by the Round 3 brief and where they are covered

| Interface | Covered by |
| --- | --- |
| React → Laravel | INT-01, every SYS case (browser) |
| Laravel → PostgreSQL | INT-03, INT-07, INT-08, SYS-12 |
| Laravel → FastAPI | INT-02, INT-04 (direct), INT-06 (failure), P1/P3 |
| Laravel → Redis queue | INT-07 (queued jobs), R3-AI-07b (reserved/delayed sets, restart), DEF-04 RT-04 (two workers) |
| Laravel → Ollama | SYS-09 (narrative 132 s), R3-AI-01..06, W1–W5b |
| Laravel → claim validation | INT-08, RT-01, SYS-08 |
| Report generation → database persistence | INT-08 (claims, `in_report`), SYS-09 (report READY), R3-AI-05/06 (`ai_generations` rows with error codes) |
| Background translation → report state | R3-AI-02, R3-AI-07b, FC-02 (`statement_translations`, language header, in-progress flags) |
| Service failure → error handling | INT-06/SYS-10 (analytics down), R3-AI-05 (model unreachable), R3-AI-06 (model timeout), W5b (`AI_BUSY`) |

Request and response formats, status codes and persisted state were checked as recorded in the JSON evidence files (each contains the HTTP status codes and the relevant response fragments).
