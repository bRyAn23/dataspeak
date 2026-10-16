# Final Round 3 Verification Report (recovery)

Data Speak, MSIT 5910-01 Capstone, Unit 7 Test Round 3. Recovery performed 2026-10-16 22:49–23:31 (+07) after the first Round 3 execution chain failed. Historical rounds untouched (`../historical-evidence-integrity.md`). The original Round 3 failures and the recovery results are kept apart throughout: originals in `../evidence/attempt1-worker-down/`, recovery executions in `../evidence/` (harness, retests, performance) and `evidence/` of this folder (resilience re-run, accessibility, regression re-run), investigation in `investigation/`.

## 1. Original Round 3 failures (first chain, 21:19–22:46)

| Failure | Record |
| --- | --- |
| `r3_ai_resilience.py` aborted: `docker compose stop backend-worker` exceeded its 120 s subprocess timeout; R3-AI-06/07 not executed | `../evidence/attempt1-worker-down/r3_ai_resilience.log`, `round3_chain.log` |
| `final_checks.py` aborted at FC-01 preparation with `KeyError: 'data'` | `round3_chain.log` |
| System harness: INT-07, INT-08, SYS-12 `KeyError: 'data'`; SYS-03, SYS-06, SYS-07, SYS-08, SYS-09 Playwright timeouts; summary integration 6 PASS / 2 FAIL, system 5 PASS / 6 FAIL | `../evidence/attempt1-worker-down/system_tests_round3.log`, `system_test_results.json|csv`, screenshots |
| `defect_retests_r3.py` aborted with `KeyError: 'data'` before RT-04 | `defect_retests_round3.log` |
| Performance P3 ran against a stalled queue (profiling waited until the worker came back); P1/P2 of that run valid but the run was repeated | `performance_tests_round3.log`, `performance-results.csv` (attempt 1) |

## 2. Root causes

Full investigation with raw captures: `investigation/findings.md`, `investigation/worker_container_timeline.log`, `investigation/analyses_state_after_failure.log`, `investigation/profile_response_on_unprofiled_analysis.log`.

1. **Test-environment failure (primary):** the default-queue worker container exited at 21:22:51 and was not restarted until 22:44:39 (`docker inspect`). Every job on the `default` queue (profiling, analysis, report) stayed queued; analyses created in that window remained `VALIDATED` and were processed only after the restart (database state captured). The API answered correctly for an unprofiled analysis: `GET /profile` → **HTTP 409 `STEP_NOT_READY`** (reproduced and recorded), whose error envelope has no `data` key, hence `KeyError: 'data'` in scripts that assumed their wait had succeeded; the browser cases timed out waiting for pages that depend on those jobs. Not an application defect; the Round 2 and recovery runs of the same cases pass.
2. **Harness error:** the resilience script stopped the worker outside its `try/finally` with a subprocess timeout shorter than the container's stop grace period, so when the stop took longer the script died without restoring the worker.
3. **Application/operational defect (new, DEF-10):** the stop took longer because the worker images had no `pcntl` extension; `queue:work` as PID 1 cannot handle SIGTERM, so every stop/restart waited the 120 s `stop_grace_period` and ended in SIGKILL, killing any in-flight job. The production image built on 2026-10-06 has the same gap.
4. **Automated-verdict errors in new scripts (not failures of the product):** R3-AI-06 required "no lock key" after a timed-out request although the lock seen belonged to the pre-translation job that legitimately started next; FC-02 and the first R3-AI-07 divided translated claims by all claims instead of in-report claims (`ClaimTranslator` scope); RT-04's log window opened after the translation job's RUNNING line. Each is reported with the raw evidence of the corrected verdict.

## 3. Corrective actions

| Action | Commit / file | Regression coverage |
| --- | --- | --- |
| DEF-10: `pcntl` installed in `docker/backend/Dockerfile` and `Dockerfile.prod`, with `RUN php -r 'exit(extension_loaded("pcntl") ? 0 : 1);'` so an image without it cannot be built | `c68be35` (note: the IDE had staged the in-progress Round 3 working files at that moment, so this commit also carries a snapshot of them; the Dockerfile change is the only code change) | build-time assertion; R3-AI-07b; idle stop timing |
| Harness: worker stop/start moved inside `try/finally`, stop with `-t 130` and a 400 s subprocess timeout (longer than the grace period; no product timeout changed) in `r3_ai_resilience.py`, `final_checks.py`, `def09_w5b_busy_retry.py` | `../scripts/` | re-executed in the recovery chain |
| Harness: `fc04_axe.py` (login selectors, pages owned by the test account), `r3_ai_07b_worker_restart.py` (asserts a reserved job and a held lock before restarting) | this folder | executed |
| Procedure: recovery chain runs strictly sequentially after the image rebuild; failed-attempt artefacts archived before re-running | `../evidence/logs/round3_chain2.log`, `round3_chain3.log` | — |

## 4. Retesting outcomes (original → recovery)

| Case | Original Round 3 result | Recovery result | Evidence |
| --- | --- | --- | --- |
| INT-07 | FAIL `'data'` | PASS (ANALYZED, 7 insights, 8 charts, DB 75/7/8) | `../evidence/integration/int07_results_retrieval.json` |
| INT-08 | FAIL `'data'` | PASS (9 claims, 5 supported, rejected kept out) | `int08_claim_validation.json` |
| SYS-03 | timeout | PASS (warning, 14.6 % highlighted) | `../evidence/system/sys03-missing-values-warning.png` |
| SYS-06 | timeout | PASS (ANALYZED in 2.4 s) | `sys06-*.png` |
| SYS-07 | timeout | PASS (7 SVG charts, 8 specs) | `sys07-chart-rendered.png` |
| SYS-08 | timeout | PASS (evidence panel) | `sys08-insight-evidence-panel.png` |
| SYS-09 | timeout | PASS (READY, 9 claims, 132 s) | `sys09-*.png` |
| SYS-12 | FAIL `'data'` | PASS (idempotent replay, identical evidence keys) | `sys12_repeat_processing.json` |
| INT-01..06, SYS-01/02/04/05/10/11 | PASS in attempt 1 | PASS again | harness records |
| RT-01..08 | not executed (script aborted) | 7 PASS (RT-04 by inspection of the full worker-log window; automated check mis-windowed; RT-06 has no browser retest by design, DEF-06 is CI) | `../evidence/defects/round3_defect_retests.json`, `evidence/defects/rt04_worker_logs_full_window.log` |
| R3-AI-05 (FC-01) | not executed | PASS (`AI_UNAVAILABLE`, READY in 2.1 s, 0 claims) | `../evidence/logs/final_checks.log` |
| R3-AI-06 | not executed | PASS (`AI_TIMEOUT` 10.2 s, recovery 112 s; forced `AI_BUSY` path controlled) | `../evidence/defect09/r3_ai_resilience.json`, W5b rows |
| R3-AI-07 | not executed | first recovery attempt void (no job in flight), **R3-AI-07b PASS** (restart 23.3 s with a job in flight, drained 62 s, 0 failed) | `evidence/defect09/r3_ai_07b_result.json` |
| R3-AI-08 (FC-02) | not executed | PASS with the in-report denominator (2/2, 6/6, 8/8, 13 th) | `../evidence/logs/final_checks.log`, investigation |
| FC-04 accessibility | not executed (first recovery run crashed on a selector) | PASS: 0 axe violations on 3 pages | `evidence/accessibility/fc04_axe_results.json` |
| P1/P2/P3 | P3 invalid | measured (P1 n = 5 × 9, P2 n = 30, P3 3 sizes), 0 errors | `../performance-results.csv`, `../evidence/performance/` |
| Complete integrated workflow | partial | login → upload → validation → profiling → definition → analysis → insights → narrative → claim validation → executive report → translations, all in one harness run (22:58–23:02) plus 15 further analyses through the pipeline in the DEF-09 workloads | `../system-test-results.md` |

## 5. Updated integration and system totals

| Level | Attempt 1 (void) | Recovery | Final Round 3 count |
| --- | --- | --- | --- |
| Integration INT-01..08 | 6 PASS, 2 FAIL | 8 PASS | **8 executed, 8 passed** |
| System SYS-01..12 | 5 PASS, 6 FAIL (SYS-05 not reached as a separate record) | 12 PASS | **12 executed, 12 passed** |
| Defect retests | 0 executed | 7 PASS | **7 executed, 7 passed** |
| Real-model DEF-09 scenarios | W1–W5 done before the failure (8 runs, all pass); R3-AI-05/06/07/08 not executed | all executed | **10 scenarios, 10 passed** (plus W5b forced variant) |

## 6. DEF-09 verification status

**VERIFIED** on the real local model under genuine concurrent narrative and translation workloads (W3 ×2, W4 ×2, W5 ×2, W5b ×2, R3-AI-03/04): 0 `AI_TIMEOUT` in 13 narratives, coordinated waits 4.6–29.3 s, translations completed, controlled `AI_BUSY`/`AI_TIMEOUT`/`AI_UNAVAILABLE` behaviour, unit tests DEF09-01..08 green. Details: `../defect09-verification.md`. Commit `57b7bce`.

## 7. Regression results

After the DEF-10 change the suites were re-run at commit `c68be35` (23:23): analytics 49 passed, 93 % coverage, Ruff clean; golden 33/33 (26/26 numeric); backend Pint clean, 162 passed / 955 assertions; frontend 28 passed, ESLint 0 errors (2 pre-existing warnings), Prettier clean, `tsc` exit 0, build ok. Identical to the 20:08 run. `evidence/regression/suites_terminal.log`, `golden_reference_recovery.log`.

## 8. Performance results

`../performance-results.csv` (recovery run 23:16): P1 analyze 0.10 / 0.20 / 0.31 s for 100 / 1,000 / 10,000 rows (median of 5); P2 status latency 21.0 ms median, 27.7 ms p95 (n = 30, measured while translation jobs ran); P3 upload-to-ANALYZED 28.8 / 22.6 / 32.8 s, of which profiling 26.5 / 20.4 / 30.6 s (includes coordinated waits for the model behind translation batches) and analysis 2.1 s. DEF-09 workloads: `../evidence/defect09/concurrency-performance-results.csv` (narrative 118–171 s alone, 151–159 s during translation, second queued narrative 299–326 s; 0 timeouts). Environment differences from Round 1 are stated in `../final-test-summary.md` §4; no thresholds exist or are introduced.

## 9. Hosted CI status

GitHub Actions run #2 (`38926418330`) on `57b7bce`: **success**, four jobs (backend, analytics, frontend, compose), 2026-10-16 15:44–15:45 UTC, read from the public API. Commits after it (DEF-10 `c68be35` and the documentation commit of this round) trigger further runs, recorded in `../hosted-ci-verification.md` when available. No claim is made for runs not observed.

## 10. Historical evidence integrity outcome

**HISTORICAL EVIDENCE PRESERVED**: 166 of 166 baseline files unchanged (SHA-256 and size), 0 modified, 0 missing, 0 unexpected new files outside `round3/`; `git diff` on historical paths empty. Round 3's own failed artefacts: 0 altered. Log: `closing-integrity-check.log`; summary in `../historical-evidence-integrity.md` §3.

## 11. Remaining limitations

- Production deployment of `57b7bce` + `c68be35` is pending: SSH (port 22) to the server is filtered from the current network; images for the server's architecture are built locally and the deploy commands are documented. Production uses the Anthropic driver (Haiku), on which the DEF-09 coordinator is intentionally inactive.
- The Terminal screenshot of the suite re-run could not be taken because the screen was locked; a watcher captures it after unlock (`evidence/regression/recapture_watcher.log`). The log of that run is complete.
- One-off worker containers used for R3-AI-05/06 and W5b were started with `--rm`, and only their stdout was saved; their JSON application log lines (stderr) are lost. The outcomes are established from `ai_generations` rows and job timings instead.
- Acceptance is investigator-led; no real users or assistive technology; local-model Burmese quality is a known model limitation; durations are laptop figures.

## 12. Final academic readiness assessment

Round 3 is complete: every planned case was executed, the only blocked item (production deployment) is explained, failures are preserved and explained, and the integrity check is finished with a passing result. The evidence supports Part A (system testing and documentation) fully for the development environment, Part B (maintenance plan) with concrete verified fixes and two operational findings (DEF-09, DEF-10) and their monitoring points, and Part C (results and evaluation) with the explicit limitation that production-provider behaviour after these changes and real-user acceptance are not demonstrated.
