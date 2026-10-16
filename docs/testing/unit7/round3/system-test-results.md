# Round 3 System (End-to-End) Test Results

Data Speak, Unit 7 Test Round 3. Browser workflow executed with Playwright (Chromium 1440/1920 × 1080) by `../scripts/system_tests.py` on 2026-10-16 22:58–23:02 (+07), plus the scripted checks listed in section 2. Screenshots in `evidence/system/` were taken during this execution; backend logs for the same window are in `evidence/logs/system_tests_round3.log` and the worker logs quoted in `recovery/`. The void first attempt (21:42–22:10, default worker down) is preserved in `evidence/attempt1-worker-down/` with its own screenshots and is not counted here.

## 1. Harness cases

| ID | Scenario | Actual | Result | Duration | Screenshot / record |
| --- | --- | --- | --- | ---: | --- |
| SYS-01 | Authentication: wrong password, then login | error shown; dashboard lists the user's analyses | PASS | 3.8 s | `sys01-login-wrong-password.png`, `sys01-dashboard-after-login.png` |
| SYS-02 | Valid CSV upload through the UI | "File checked successfully, 240 rows and 7 columns" | PASS | 1.1 s | `figure-03-valid-csv-workflow.png` |
| SYS-03 | Missing values flagged | Data quality warning present; 14.6 % (35) missing highlighted in red in the column table | PASS | 20.8 s | `sys03-missing-values-warning.png` |
| SYS-04 | Invalid / unsupported CSV feedback | duplicate and empty headers explained; `.txt` rejected | PASS | 2.0 s | `figure-04-invalid-csv-feedback.png`, `sys04-unsupported-file-feedback.png` |
| SYS-05 | Profiling results displayed | overview cards present (rows, columns, completeness, notes) | PASS | 20.8 s | `sys05-profile-overview.png` |
| SYS-06 | Statistical analysis completes from the UI | ANALYZED in 2.4 s after Define; chart review shown | PASS | 4.3 s | `sys06-define-step.png`, `sys06-analysis-complete-chart-review.png` |
| SYS-07 | Visualisations rendered for the selected specs | 7 SVG charts rendered; 8 selected specs (BAR, BAR, TABLE, LINE, BAR, BAR, LINE, LINE); 21 matching titles | PASS | 3.7 s | `sys07-chart-rendered.png` |
| SYS-08 | Insight shows its supporting evidence | verification badge and evidence panel present | PASS | 1.8 s | `sys08-insight-evidence-panel.png` |
| SYS-09 | Executive report generation (local model) | report READY, 9 claims, 5 supported, generation 132 s | PASS | 135.1 s | `sys09-report-ready.png`, `sys09-executive-report.png` |
| SYS-10 | Analytics outage in the UI | controlled error state shown; "Try again" succeeded after restart | PASS | 8.6 s | `sys10-analytics-outage-ui-error.png`, `sys10-analytics-recovered-ui.png` |
| SYS-11 | Authorisation enforcement | logged-out redirect to /login; no token 401; another user's analysis and report 404/404 | PASS | 1.1 s | `sys11-logged-out-redirect.png`, `sys11_unauthorized.json` |
| SYS-12 | Repeated processing | duplicate run request replayed (202/202, `Idempotent-Replayed`); re-upload 201 then second run ANALYZED; 75 evidence rows, identical key sets | PASS | 45.4 s | `sys12_repeat_processing.json` |

**12 executed, 12 passed.** Round 1: 9/12 first run (3 harness errors, fixed) ; Round 2: 9/12 first attempt (SYS-09/08 blocked by DEF-09) then 12/12 with re-runs.

## 2. Round 3 workflow scenarios required by the brief

| # | Scenario | Covered by | Result |
| --- | --- | --- | --- |
| 1 | Valid CSV processing | SYS-02, SYS-05, SYS-06, INT-02/04/07 | PASS |
| 2 | Invalid CSV rejection | SYS-04, INT-05 | PASS |
| 3 | Missing-value feedback | SYS-03, RT-08 | PASS |
| 4 | Data profiling | SYS-05, P3 profiling 20–31 s | PASS |
| 5 | Statistical outputs | SYS-06, INT-04, golden 33/33 | PASS |
| 6 | Insight generation | INT-07 (7 insights with evidence keys), SYS-08 | PASS |
| 7 | Unsupported claim rejection | INT-08 (REJECTED claims out of the report), RT-01, FC-03 (today's claims: 159 SUPPORTED, 150 REJECTED, 6 NEEDS_REVIEW across all Round 3 reports; only SUPPORTED claims flagged `in_report`) | PASS |
| 8 | Visualisation rendering | SYS-07 | PASS |
| 9 | Report completion | SYS-09, W1–W5 (13 reports) | PASS |
| 10 | Translation processing | R3-AI-02, R3-AI-08, R3-AI-07b | PASS |
| 11 | AI service unavailable | R3-AI-05 (unreachable), R3-AI-06 (timeout), W5b (`AI_BUSY`): deterministic report, 0 fabricated claims, error code recorded | PASS |
| 12 | Backend service failure | INT-06 / SYS-10 (analytics down and recovered); worker restart R3-AI-07b | PASS |
| 13 | Authorisation enforcement | SYS-11 | PASS |
| 14 | Repeated user workflows | SYS-12; W1–W5 (15 analyses through the full pipeline); RT-04 (second dataset while translations run) | PASS |

All listed features are implemented; no scenario was skipped as unimplemented. Not covered in Round 3 (same as Rounds 1–2): assistive-technology testing with real users, PDF export in the browser flow (API-level only in earlier units), and production execution.

## 3. Controlled-failure behaviour observed (real backend logs)

| Condition | Observed | Record |
| --- | --- | --- |
| Model endpoint unreachable | `ai_generations` FAILED/`AI_UNAVAILABLE`, report READY in 2.1 s with 3 data notes and no claims | `evidence/logs/final_checks.log` FC-01, `evidence/logs/fc01_no_model_worker.log` |
| Model request timed out (`OLLAMA_TIMEOUT=8`) | FAILED/`AI_TIMEOUT` after 10.2 s, report READY with no claims, next report succeeded (112 s) | `evidence/defect09/r3_ai_resilience.json`, `evidence/logs/r3_ai_06_short_timeout_worker.log` |
| Model busy beyond the bounded wait (forced 3 s) | attempt 1 and 2 `AI_BUSY` (nothing sent), delayed retry 30 s, deterministic report | `evidence/defect09/def09_concurrency_runs.json` rows W5b, `recovery/evidence/defect09/w5b_busy_worker_run*.log` |
| Analytics container down | 503 `ANALYTICS_UNAVAILABLE`, UI error state, recovery on retry | INT-06 / SYS-10 |
| Worker restarted mid-job | job finished before exit, queue drained, nothing stuck | R3-AI-07b |
