# Unit 7 Test Plan: Data Speak System Testing

Version 1.0, 2026-10-15. Applies to commit `e5ecc93` on `main`. Environment: local development stack (see `evidence/environment/`). Production is excluded from all execution.

## 1. Objectives

1. Verify that the implemented components (React client, Laravel API, queue worker, FastAPI analytics, PostgreSQL, Redis, local LLM gateway) communicate correctly across their real interfaces.
2. Verify complete user workflows end to end in a browser against running services.
3. Re-execute the existing unit, golden-reference and contract suites to confirm numerical correctness and detect regressions.
4. Measure response time and workflow duration for three dataset sizes and record resource use.
5. Verify the implemented business requirements through investigator-led acceptance inspection.
6. Record defects and limitations with evidence, and evaluate reliability and quality.

## 2. Scope

**In scope:** authentication; CSV upload, validation and error feedback; profiling and missing-value handling; definitions; deterministic analysis; insight and chart generation; chart review; report generation with the local Qwen model; claim validation and traceability; idempotent re-processing; authorization boundaries; analytics-service outage handling; FastAPI contracts; numerical correctness; performance for 100, 1,000 and 10,000 rows.

**Out of scope:** production environment and the Claude provider; PDF rendering fidelity; translations (covered by backend unit tests only); email delivery; load or concurrency testing; security penetration testing; cross-browser and mobile layouts; XLSX conversion (unit-tested only).

## 3. Test levels and approach

| Level | Approach | Real or mocked | Tooling | Evidence |
| --- | --- | --- | --- | --- |
| Integration (INT-01..08) | Drive real HTTP interfaces between components; one failure injection by stopping the local analytics container; read-only PostgreSQL checks | Real services | Python/httpx, Playwright, docker compose, psql | `evidence/integration/*.json`, `evidence/system_test_results.{json,csv}`, `evidence/logs/system_tests.log` |
| System / end-to-end (SYS-01..12) | Headless Chromium drives the real UI through each workflow; assertions on rendered text, API state and database counts | Real services incl. local Ollama | Playwright 1.60 | `evidence/system/*.png`, results JSON/CSV |
| Regression and numerical correctness | Re-run Vitest, PHPUnit, pytest (with coverage) and the golden-reference tests unchanged | Backend and frontend suites mock FastAPI and the LLM by design; analytics tests are real computations | Vitest, PHPUnit, pytest | `evidence/regression/*.log`, `*.png` |
| Performance and stability | Sequential single-client harness: FastAPI direct (5 runs per endpoint and size), end-to-end to ANALYZED (1 run per size), API latency (30 requests), docker stats snapshots, HTTP error count | Real services; AI narrative stage excluded from timing loops | Python/httpx | `performance-results.csv`, `evidence/performance/` |
| Acceptance | Requirements-based checklist inspected by the investigator using the executed cases as evidence | Real services | manual review of evidence | `acceptance-checklist.md`, `evidence/acceptance/` |

## 4. Test data

- Golden synthetic dataset `retail_sales.csv` (240 rows, 7 columns, 14.6% missing discounts) for all functional cases.
- Synthetic performance datasets of 100, 1,000 and 10,000 rows with the same schema.
- Invalid inputs: duplicate and empty headers, header only, empty file, plain-text file.
- Dedicated per-run accounts `unit7-*@example.com` on the local database; no personal data.

## 5. Entry and exit criteria

Entry: all containers healthy, Ollama serving `qwen3:8b`, test accounts creatable, golden file present. Exit: every planned case has a recorded result of PASS, FAIL, BLOCKED or NOT IMPLEMENTED with evidence; all failures are logged in the defect register; the summary report totals equal the execution records.

## 6. Pass criteria

A case passes only when its stated expected result is observed in the real system. Presence of a page, an HTTP 200 without the expected body, or a mocked response does not count. Performance has no predefined thresholds in the project requirements; measurements are reported as observed values, not pass/fail.

## 7. Defect handling

Defects found during execution are recorded first with baseline evidence. No application code is changed during the test run. Any fix is a separate activity and must be retested before a defect is marked VERIFIED.

## 8. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Local LLM latency makes report generation slow or time out | Generous timeouts; narrative stage measured once and excluded from repeated timing loops |
| Failure injection leaves the analytics container stopped | Harness restarts it in a `finally` block and waits for `/health` |
| Non-deterministic narrative text | Assertions target structure, validation status and evidence links |
| Shared development database accumulates test analyses | Accounts and analyses are named `Unit7 *` and can be deleted through the API afterwards |

## 9. Schedule

Single session on 2026-10-15: discovery and environment (15:31), integration and system run (15:35 onward), regression suites, performance harness, acceptance inspection, reporting.
