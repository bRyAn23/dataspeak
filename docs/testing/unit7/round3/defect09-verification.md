# DEF-09 Verification (Round 3)

Data Speak, Unit 7 Test Round 3, 2026-10-16. DEF-09: narrative generation timed out (`AI_TIMEOUT` after 300 s) when the single local Ollama model was busy with a background translation job (first seen in Round 2, 2026-10-16 10:41, `../retesting/defect-retesting-results.md` §2b). Root cause: `def09-root-cause-analysis.md`. Correction: `def09-fix-summary.md`, commit **`57b7bce`** (`ModelAccessCoordinator`, `CoordinatedLlmClient`, bounded report retry, configuration). Verification combined eight scheduling unit tests against the real lock logic (model faked) and real-model scenarios on the local stack (Ollama 0.35.1, `qwen3:8b`, two workers, Redis, PostgreSQL).

**Status: VERIFIED** on the development stack. The contention scenario that failed in Round 2 (narrative requested while a translation job occupies the model) passed in every real-model run (W3 twice, W4 twice, W5 twice, W5b twice as the forced variant): no `AI_TIMEOUT` occurred in any of the 13 narrative generations of the concurrency workloads, the longest coordinated wait was 29.3 s, and every report that was expected to succeed did. Production uses the Anthropic API, where the coordinator is inactive by design and the defect cannot occur; it is therefore verified only where it applies.

## 1. Unit tests (mocked model, real lock logic)

`backend/tests/Unit/ModelAccessCoordinatorTest.php`, 8 tests, 34 assertions, pass (`evidence/logs/phpunit_def09.log`; also in the full suite of 162). See `regression-results.md` §4 for the per-test table. One test (DEF09-04) failed on its first execution because the coordinator did not pass the configured poll interval to the lock; the fix (`betweenBlockedAttemptsSleepFor`) is part of `57b7bce`.

## 2. Real-model scenarios

Scripts: `scripts/def09_concurrency.py` (W1–W4), `scripts/def09_w5_bounded_wait.py` (W5), `scripts/def09_w5b_busy_retry.py` (W5b), `scripts/r3_ai_resilience.py` and `recovery/r3_ai_07b_worker_restart.py` (R3-AI-06/07), `scripts/final_checks.py` (FC-01..03), `recovery/fc04_axe.py`. Raw records: `evidence/defect09/def09_concurrency_runs.json`, `evidence/defect09/concurrency-performance-results.csv`, `evidence/defect09/r3_ai_resilience.json`, `recovery/evidence/defect09/r3_ai_07b_result.json`, logs under `evidence/logs/` and `recovery/evidence/`. All runs used the golden retail dataset (240 × 7) and the synthetic `unit7-a` account; two repetitions per workload.

| ID | Scenario and precondition | Runs | Result (actual) | Expected | Verdict |
| --- | --- | ---: | --- | --- | --- |
| R3-AI-01 | Narrative only (W1): model idle, `POST /report` on an ANALYZED analysis | 2 | SUCCEEDED both; wall 118 s and 171 s (generation 115 s / 143 s); 18 and 21 claims. Run 2 waited 23.6 s and 25.6 s for the lock because the pre-translation batches of run 1's report were still finishing (coordinated wait, logged `llm.lock.waited`; one `llm.background.yielded`). | successful generation | **PASS** |
| R3-AI-02 | Translation only (W2): three languages re-translated on an idle model | 2 | all jobs DONE; 140 s and 122 s for the language set; batch durations 21–73 s; no lock waits, no timeouts | successful translation | **PASS** |
| R3-AI-03 | Narrative during translation (W3): translation in flight for 15 s, then `POST /report` | 2 | SUCCEEDED both; wall 159 s and 151 s; narrative waited 9.6 s / 25.4 s for the batch in progress, the translation job yielded once (`proceeding_after_bound=false`), then waited 27.6 s / 4.6 s behind the narrative; no `AI_TIMEOUT`, no `AI_BUSY`. In Round 2 the same condition produced `AI_TIMEOUT` at 300 s. | correct scheduling, bounded response | **PASS** |
| R3-AI-04 | Multiple concurrent requests (W4): translation in flight, two reports requested back to back | 2 | all four narratives SUCCEEDED (first report 128 s / 169 s, second 299 s / 326 s because it is served after the first); waits 6–29 s; two background yields per run; translations completed (184 s / 227 s jobs); no timeouts | no uncontrolled contention | **PASS** |
| R3-AI-05 | Model unavailable (FC-01): one-off default worker with `OLLAMA_BASE_URL` pointing at a closed port | 1 | report READY in 2.1 s with 0 claims, `ai_generations` FAILED/`AI_UNAVAILABLE`, one attempt only (no busy retry), 3 data notes shown | graceful failure or deterministic fallback | **PASS** |
| R3-AI-06 | Model timeout and recovery (real): one-off worker with `OLLAMA_TIMEOUT=8`, then a normal report | 1 + 1 | timed-out request: FAILED/`AI_TIMEOUT` after 10.2 s, report READY with 0 claims, no retry (timeout is final by design); recovery report on the normal worker: SUCCEEDED in 112 s (generation 108 s, so the lock had been released by the failed request), 23 claims. Bounded-wait expiry (W5b, `AI_MODEL_LOCK_WAIT=3`): attempt 1 `AI_BUSY` after the 3 s bound with nothing sent, job released 30 s, attempt 2 `AI_BUSY` again (a 25 s batch was on the model), deterministic report READY with 0 claims after 39 s / 37 s; two runs identical. | controlled error and recovery | **PASS** (automated verdict was FAIL: see note a) |
| R3-AI-07 | Queue worker restart with a translation job in flight (`recovery/r3_ai_07b_worker_restart.py`) | 1 | precondition confirmed (1 job reserved, model lock held, 2 queued); `docker compose restart` of both workers took 23.3 s (the worker finished its batch before exiting, pcntl installed by DEF-10); queue drained in 62 s; 3 jobs DONE, 0 FAIL, 0 `failed_jobs`; all 6 in-report claims translated in th/zh/my; no translate flags, lock or reserved/delayed entries left. First attempt (`r3_ai_resilience.py`, 22:57) is void: its 20 s delay let the three small jobs finish before the restart, so no job was in flight; it is kept in `evidence/defect09/r3_ai_resilience.json` (see note b). | no permanently stuck jobs | **PASS** |
| R3-AI-08 | Translation after narrative (FC-02 and database check) | 4 reports | every in-report claim of each checked report has th, zh and my translations (2/2, 6/6, 8/8, 13 th for the retest report); `GET /report` with `X-Dataspeak-Language: th` serves the translated statements; no `dataspeak:translate:*` flag remains | background jobs complete | **PASS** (automated verdict was FAIL: see note c) |
| R3-AI-09 | Statistical integrity | 33 + 49 + harness | golden reference 33/33 (26/26 numeric) before and after the fix (`evidence/logs/golden_reference_final.log`, `recovery/evidence/regression/golden_reference_recovery.log`); pytest 49/49; INT-04 cross-check on a Round 3 analysis: 75 evidence records, independent mean agrees | original deterministic values preserved | **PASS** |
| R3-AI-10 | Fair scheduling (translations do not starve) | unit + W3/W4/W5 | DEF09-03 shows a background request proceeds after the yield bound even while interactive work is still waiting; DEF09-08 completes a batch set under a permanent waiting counter; in W3/W4/W5 every translation job completed (durations 19–227 s) while 1–2 narratives were served first; `proceeding_after_bound` was never needed on the real system because narratives finish within the 120 s yield bound | translation does not starve indefinitely | **PASS** |

Notes on automated verdicts that were corrected by inspection (the raw records keep the original verdicts):

- (a) R3-AI-06: the script required "no model lock key 2 s after the timed-out request". A lock key was present, but it belonged to the pre-translation job that started as soon as the fallback report was written (the translations worker log shows `TranslateReportJob RUNNING` at that moment). The release of the lock by the failed request is proven by the recovery narrative, which waited about 3 s and not the 330 s TTL. Criterion error, not a product failure.
- (b) R3-AI-07 first attempt: precondition not met (jobs finished before the restart); re-run as R3-AI-07b with a 6-claim report and a reserved-job check before restarting.
- (c) FC-02 and the first R3-AI-07 compared translated claims with **all** claims of the report (23) instead of the claims that are in the report (2); `ClaimTranslator` translates only `SUPPORTED` claims with `in_report = true` (`ClaimTranslator.php:31`), which is the documented scope. With the correct denominator every report is fully translated.

## 3. Timing summary of the concurrency workloads (real model)

| Workload | n | Narrative wall time median / max (s) | Coordinated lock waits (s) | Timeouts | Outcome |
| --- | ---: | --- | --- | --- | --- |
| W1 narrative only | 2 | 144.7 / 171.3 | 0 (run 1), 23.6 and 25.6 (run 2, behind finishing batches) | 0 | 2/2 SUCCEEDED |
| W2 translation only (3 languages) | 2 | 131.2 / 140.1 (set) | none | 0 | all jobs DONE |
| W3 narrative during translation | 2 | 154.9 / 159.2 | 4.6–27.6 | 0 | 2/2 SUCCEEDED |
| W4 two narratives + translations | 2 | first 148.8 / 169.1; second 312.7 / 325.9 | 6.2–29.3 | 0 | 4/4 SUCCEEDED |
| W5 bound 20 s | 2 | 143.6 / 154.8 | 4.6, 24.7 (translation side) | 0 | 2/2 SUCCEEDED, no `AI_BUSY` needed |
| W5b bound 3 s (forced) | 2 | 37.8 / 38.8 to deterministic report | bound expired twice per run | 0 `AI_TIMEOUT`, 4 `AI_BUSY` | controlled fallback, nothing fabricated |

Across the 15 coordinated waits recorded in W1–W5: median 23.6 s, maximum 29.3 s (one translation batch). With the default interactive bound of 240 s no narrative ever had to give up; the forced 3 s bound shows the controlled `AI_BUSY` path and the single delayed retry working as designed, and also that a retry delay close to the batch length can collide with the next batch (operational note in `def09-fix-summary.md`).

## 4. Memory before and during the runs

64 GiB host; `qwen3:8b` resident at 6.6 GB on the GPU with an 8192-token context; system free memory 43–55 % across 156 samples during the workloads (`evidence/logs/ollama_memory_samples.log`); Ollama process RSS ≤ 36 MB (model memory is Metal-allocated). `OLLAMA_NUM_PARALLEL` was left unset: raising it would fit in memory but would not remove serialisation and would slow concurrent generations (root-cause analysis §2.6 and §5).

## 5. Before and after

| Condition | Round 2 (before fix) | Round 3 (after fix) |
| --- | --- | --- |
| Narrative requested while a translation job is on the model | `AI_TIMEOUT` after 304 s; report READY with 0 claims; SYS-08/INT-08 unable to run | SUCCEEDED in 151–159 s; waited 5–25 s; all dependent checks pass |
| Two narratives plus translations | not tested | all SUCCEEDED; second narrative served after the first (300–326 s), no timeout |
| Behaviour when the model truly cannot be reached or times out | deterministic report, no fabrication (already correct) | unchanged, now with `AI_BUSY` as a distinct, bounded code and one delayed retry |
| Background translation completion | completes | completes; yields to interactive work for at most 120 s |
