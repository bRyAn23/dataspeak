# Unit 7 Requirements Traceability Matrix

Requirement identifiers follow the project feature list (`docs/traceability/feature_matrix.md`) and the non-functional requirements of the program specification. Results are those recorded on 2026-10-15 after retests; first-run results are kept in `test-cases.csv`.

| Requirement | Description | Test cases | Level(s) | Result | Evidence |
| --- | --- | --- | --- | --- | --- |
| FEAT-AUTH-001/002 | Registration, login, token auth | SYS-01, INT-01 | system, integration | PASS | sys01-*.png, sys01_api_calls_from_react.json |
| FEAT-UPL-001 | CSV/XLSX upload | SYS-02, INT-02 | system, integration | PASS | figure-03-valid-csv-workflow.png, int02_*.json |
| FEAT-UPL-002 | Structural validation with errors and warnings | INT-05, SYS-04/04R, REG-02 | integration, system, regression | PASS | int05_invalid_input.json, figure-04-invalid-csv-feedback.png |
| FEAT-UPL-003 / NFR-PRI-001 | Transient upload, purge after run | LIM-01R, SYS-12R | system | PASS | figure-08-defect-or-limitation.png, sys12R_repeat_processing.json |
| FEAT-PRO-001 | Dataset profiling, type inference, quality findings | SYS-05/05R, SYS-03, INT-04, REG-02 | system, integration, regression | PASS | sys05-profile-overview.png, int04_fastapi_valid_csv.json |
| FEAT-DEF-003/004 | Suggested definitions with reasons; user confirms | SYS-06 (Define step), INT-07 | system, integration | PASS | sys06-define-step.png |
| FEAT-ANL-001 | Deterministic statistical analysis | INT-04, REG-01, REG-02, PERF-1 | integration, regression, performance | PASS | figure-05-statistical-validation.png, golden_reference_cases_2026-10-15.csv |
| FEAT-INS-001 | Insight detection and ranking linked to evidence | INT-07/07R | integration | PASS | int07R_insight_evidence_association.json |
| FEAT-VIS-001/003 | Chart recommendation and user chart review | SYS-06, SYS-07 | system | PASS | sys06-analysis-complete-chart-review.png, sys07-chart-rendered.png |
| FEAT-AI-001/002 | AI narrative with placeholder-only numbers; structured claims | SYS-09, INT-08 | system, integration | PASS (DEF-01 open) | sys09-executive-report.png, int08_claim_validation.json |
| FEAT-VAL-001 | Rule-based claim validation; only SUPPORTED claims in report | INT-08, REG-03 (unit coverage of each rule) | integration, regression | PASS (DEF-01 open) | int08_claim_validation.json, phpunit_backend.log |
| FEAT-RPT-001 | Executive report assembly | SYS-09, SYS-07 | system | PASS | sys09-*.png |
| NFR-TRC-001 | Claim-to-evidence traceability | SYS-08, INT-07R | system, integration | PASS | sys08-insight-evidence-panel.png |
| NFR-REL-001 | Structured failures and safe retry | INT-06, SYS-10, SYS-12R | integration, system | PASS (DEF-04 queue contention open) | int06_analytics_outage.json, sys10-*.png |
| NFR-SEC-001 | Authentication, ownership, internal token | SYS-11, INT-05 (401 without internal token) | system, integration | PASS | sys11_unauthorized.json |
| NFR-PER-001 | UI stays responsive; long work asynchronous | PERF-1/2/3, SYS-06 | performance, system | MEASURED (no threshold defined) | performance-results.csv, figure-06-performance-test-results.png |
| NFR-MNT-001 | Automated tests and typed contracts | REG-01..04, contract test in REG-02 | regression | PASS (DEF-06: CI not green) | evidence/regression/*.log |
| FEAT-I18N-002/003 | Display labels and report translation | REG-03 (unit), PERF-3 observation | regression | PASS in unit tests; not system-tested in Unit 7 | phpunit_backend.log |
| FEAT-RPT-003 | PDF export | REG-03 (unit) | regression | Not system-tested in Unit 7 | phpunit_backend.log |

Coverage of the brief's workflow: upload, validation, profiling, definition, deterministic analysis, insight ranking, visualization, AI synthesis, claim generation, claim validation and executive report were each exercised at least once at system level with real services; translation and PDF were verified only by the existing unit suite.
