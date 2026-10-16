# Unit 7 Final Test Summary (Round 3, final post-fix verification)

Data Speak, MSIT 5910-01 Capstone. Round 3 executed 2026-10-16 from 20:05 to 23:25 (+07) on the local development stack (`environment-report.md`). System under test: commit `e4ae051` plus the DEF-09 correction, committed as **`57b7bce`** (pushed; hosted CI run #2 success), plus the DEF-10 correction **`c68be35`** found and fixed during the round. Rounds 1 (`../`, 2026-10-15, commit `e5ecc93`) and 2 (`../retesting/`, 2026-10-16 morning, commit `524842f`) are unchanged; see `historical-evidence-integrity.md`.

## 1. Execution results by level (Round 3)

| Testing type | Executed | Passed | Failed | Blocked | Skipped | Source |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| DEF-09 scheduling unit tests (DEF09-01..08) | 8 | 8 | 0 | 0 | 0 | `evidence/logs/phpunit_def09.log` |
| DEF-09 real-model scenarios (R3-AI-01..10) | 10 | 10 | 0 | 0 | 0 | `defect09-verification.md` |
| Defect retests DEF-01..08 (RT-01..08 re-executed) | 7 | 7 (RT-04 by inspection of the full worker log; automated check mis-windowed) | 0 | 0 | 0 | `defect-verification-matrix.md` |
| New defect DEF-10 (stop timing, R3-AI-07b) | 2 | 2 | 0 | 0 | 0 | same |
| Regression suites (pytest 49, golden 33, PHPUnit 162, Vitest 28, lint/format/types/build) | 272 tests | 272 | 0 | 0 | 0 | `regression-results.md` |
| Integration (INT-01..08, real services) | 8 | 8 | 0 | 0 | 0 | `integration-results.md` |
| System / end-to-end (SYS-01..12 + 14 workflow scenarios) | 12 (+14 mapped) | 12 | 0 | 0 | 0 | `system-test-results.md` |
| Accessibility (axe, 3 pages) | 1 | 1 | 0 | 0 | 0 | `recovery/evidence/accessibility/` |
| Performance (P1, P2, P3 + 6 concurrency workloads) | 3 + 6 | measured | 0 errors | 0 | 0 | `performance-results.csv`, `evidence/defect09/concurrency-performance-results.csv` |
| Hosted CI | 1 run | success | 0 | 0 | 0 | `hosted-ci-verification.md` |
| Acceptance (ACC-01..12, investigator-led) | 12 | 12 met | 0 | 0 | 0 | `acceptance-results.md` |

**First-attempt failures in this round (preserved, not counted above):** the first execution of the model-bound chain (21:20–22:46) failed in 11 harness cases, the defect retests and the P3 performance scenario because the default-queue worker had been left stopped by a test script (details, root cause and classification in `recovery/investigation/findings.md`; artefacts in `evidence/attempt1-worker-down/`). The cause was a harness sequencing error on top of a real operational defect (DEF-10). Every affected case was re-executed after the correction and passed. Three automated verdicts (R3-AI-06, R3-AI-07 first attempt, FC-02/RT-04 log checks) were criterion or windowing errors in new scripts; each is explained next to the result with the raw evidence that supports the corrected verdict.

## 2. Defect status after Round 3

| ID | Severity | Round 1 | Round 2 | Round 3 |
| --- | --- | --- | --- | --- |
| DEF-01 | Major | OPEN | VERIFIED | VERIFIED (no regression) |
| DEF-02 | Minor | OPEN | VERIFIED | VERIFIED |
| DEF-03 | Minor | OPEN | VERIFIED | VERIFIED |
| DEF-04 | Medium | OPEN | VERIFIED | VERIFIED |
| DEF-05 | Minor | OPEN | VERIFIED | VERIFIED |
| DEF-06 | Medium | OPEN | VERIFIED locally, hosted pending | **VERIFIED (hosted CI run #2)** |
| DEF-07 | Minor | OPEN | VERIFIED | VERIFIED |
| DEF-08 | Minor | OPEN | VERIFIED | VERIFIED |
| DEF-09 | Medium (dev stack) | — | found, DEFERRED | **FIXED (`57b7bce`) and VERIFIED** on the real model |
| DEF-10 | Medium (operational) | — | — | **found, FIXED (`c68be35`) and VERIFIED** on the dev images; production image rebuilt, deployment pending |

## 3. Three-round comparison

Before comparing: Round 1 ran one queue worker on `e5ecc93`; Round 2 ran two workers on `524842f` after six fix commits; Round 3 adds the model-access coordinator and pcntl. The datasets, accounts, scripts and machine were the same in all rounds; the harness corrections of Round 2 (H-00..H-05) apply to Rounds 2 and 3; the performance script in Round 3 is a copy with redirected output only. Claim counts per report vary between runs because model output varies; deterministic statistics do not.

| Category | Round 1 (2026-10-15) | Round 2 (2026-10-16 a.m.) | Round 3 (2026-10-16 p.m.) |
| --- | --- | --- | --- |
| Test execution | integration 8, system 15 (incl. retests), regression 4 suites, performance 3, acceptance 12 | retests 9, new unit tests 7, suites, harness 23 cases, performance context | unit 8 new, real-model 10, retests 7, suites 272 tests, harness 20, accessibility 1, performance 3 + 6, CI 1, acceptance 12 |
| Passing tests | integration 8/8, system 15/15 after 5 harness corrections; suites 49 / 148 / 28; golden 33/33 | retests 7/9 first run then 9/9; suites 49 / 154 / 28; golden 33/33; harness 20/23 first attempt, 23/23 after re-runs | all of the above pass: suites 49 / 162 / 28; golden 33/33; harness 20/20; retests 7/7; real-model 10/10 |
| Failing tests | 5 first-run harness errors (H-01..H-04 + H-00), no product failures in the executed cases | RT-04 and RT-07 first run (fixed same session); SYS-09/08, INT-08 first attempt (DEF-09) | 11 harness cases + retests + P3 in attempt 1 (worker down, DEF-10 + harness error); 0 after re-execution; 3 automated verdict errors corrected by inspection |
| Known defects | DEF-01..08 identified (1 Major, 3 Medium, 4 Minor) | DEF-01..08 corrected and verified (DEF-06 locally); DEF-09 found, deferred | DEF-01..08 re-verified; DEF-09 verified; DEF-10 found, fixed, verified; 0 open defects on the dev stack |
| Regression | baseline: 49 / 148 (903 assertions) / 28 | 49 / 154 (921) / 28, +6 tests | 49 / 162 (955) / 28, +8 tests; golden identical in all rounds |
| Performance | P1 analyze large 0.286 s; P2 status 9.3 ms; P3 profiling 73 / 12 / 14 s (small/medium/large; the 73 s included a translation backlog on the single worker) | not re-measured as a scenario; RT-04 profiling during translations 12.2 s (73.3 s before) | P1 analyze large 0.308 s; P2 status 21.0 ms (translation jobs and a second worker active during the sample, see §4); P3 profiling 26.5 / 20.4 / 30.6 s, now including a coordinated wait for the model behind translation batches; narrative 118–171 s; concurrency waits ≤ 29.3 s, 0 timeouts |
| CI | last hosted run (`c4a1951`, 2026-10-08) failed on the analytics contract test (DEF-06) | local reproduction of the CI analytics job passed; hosted pending (not pushed) | hosted run #2 on `57b7bce` success, 4/4 jobs |

## 4. Performance notes (no thresholds were defined in any round; none is introduced here)

- P1 (FastAPI direct) is within 0.03–0.1 s of Round 1 for validate and profile and equal within noise for analyze; the differences are smaller than the run-to-run spread of a laptop.
- P2 (status endpoint, n = 30) rose from 9.3 ms to 21.0 ms median. During the Round 3 sample a translation job and the default worker were both active (16:16–16:18 UTC worker logs) and the model was generating; Round 1 sampled an idle stack. The figures are not directly comparable.
- P3 profiling (20–31 s) is longer than Round 1's medium/large (12–14 s) because profiling's column-suggestion model calls now wait for the lock when a translation batch is on the model (10–12 s `llm.lock.waited` in the RT-04 log) and because the pre-translation of the preceding report is running in parallel; Round 1's small-file 73 s was the DEF-04 backlog. Without background translation the Round 3 P3 would be comparable to Round 1 (W1 run 1 profiled in 12–15 s during preparation). This is the intended trade-off of DEF-04 + DEF-09: user-facing work waits at most one batch instead of minutes.
- Peak memory: host free memory 43–55 % with the 6.6 GB model resident; container memory in `evidence/performance/docker_stats_*.txt` and the concurrency CSV. CPU was not sampled per process.

## 5. Unavailable environments and blocked items

- Production (AWS, Claude API): not tested in this round by rule; the production deployment of `57b7bce`/`c68be35` is **pending** because SSH to the server is filtered from the current network (documented in the completion report). The DEF-09 coordinator is inactive on the Anthropic driver; the DEF-10 fix is in the rebuilt production image awaiting shipment.
- Real-user acceptance and assistive-technology testing: not available; accessibility is automated only.
- Terminal figure of the suites: the suites were re-run in a real Terminal window at 23:23 (`recovery/evidence/regression/suites_terminal.log`), but the screen was locked, so the capture is pending an unlock (`recovery/evidence/regression/recapture_watcher.log`).

## 6. Corrections to earlier records (originals untouched)

- Round 2 `final-test-summary.md` §4 says DEF-09 is deferred and DEF-06's hosted run "follows the next push"; both are now resolved as recorded above.
- The Round 2 statement that the production provider makes DEF-09 "not applicable" is confirmed by code and the pass-through test DEF09-07, but the fix was still applied because the development and any future self-hosted deployment use Ollama.

## 7. Overall assessment

On the development stack the tested build meets every planned criterion with real services, the one Major defect and all Medium defects are closed with regression tests, the local-model contention defect is verified under genuine concurrent narrative and translation load, and the suites and golden assertions are green at commit `c68be35`. What is not demonstrated: behaviour under production load with the Claude API after these changes (deployment pending), and acceptance by real business users.
