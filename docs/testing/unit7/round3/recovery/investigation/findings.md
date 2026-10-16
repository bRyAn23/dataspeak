# Round 3 failure investigation (recovery step 2)

Investigated 2026-10-16 22:50–23:05 (+07) before any re-execution. Raw captures in this folder: `worker_container_timeline.log` (container state from `docker inspect`), `analyses_state_after_failure.log` (database state of every analysis created by the failed attempt), `profile_response_on_unprofiled_analysis.log` (the real HTTP responses behind the `KeyError`). The failed attempt's own outputs are preserved unchanged in `../../evidence/attempt1-worker-down/` (relocated from their original paths under `round3/evidence/` so that the re-run could not overwrite them; contents are byte-identical, see `../round3-pre-recovery-manifest.csv`).

## Timeline (all +07)

| Time | Event | Source |
| --- | --- | --- |
| 21:19:53 | Chain of model-bound steps starts (serial probe OK). | `attempt1-worker-down/round3_chain.log` |
| 21:20:12 | `r3_ai_resilience.py` prepares two analyses (both reached ANALYZED, so the stack was healthy). | `analyses_state_after_failure.log` |
| 21:20:4x | The script runs `docker compose stop backend-worker` with a 120 s subprocess timeout. The worker process (PID 1 in its container, PHP without the `pcntl` extension) does not react to SIGTERM; Docker waits for the configured `stop_grace_period` (120 s) and then kills it. The subprocess timeout fires first, the script raises `TimeoutExpired` **before** its `try/finally`, so the step that would have restored the worker never ran. | traceback in the chain log; `docker inspect`: `stopTimeout=120`, worker `finishedAt 21:22:51`, `exitCode 0` after the kill sequence |
| 21:22:51 → 22:44:39 | **No consumer for the `default` queue.** Profiling, analysis and report jobs were queued but never executed. The translations worker kept running. | `docker inspect` `startedAt 22:44:39`; the W5 standalone run earlier had already shown the same stop behaviour (`Exited (137)`) |
| 21:22:51–22:46 | `final_checks.py`, the system harness, `defect_retests_r3.py` and the P3 part of the performance script all created analyses that stayed VALIDATED/UPLOADED and failed as described below. P1 (FastAPI direct) and P2 (API latency) in the same run did not need the worker and are valid measurements, but the whole run is treated as attempt 1 and was repeated. | chain log, database state |
| 22:44:39 | Worker restarted manually (`docker compose start backend-worker`); the queued jobs were then processed: the attempt-1 analyses now show PROFILED/ANALYZED. | `analyses_state_after_failure.log` |

## Issue-by-issue classification

| # | Failure in attempt 1 | Actual observation | Classification |
| --- | --- | --- | --- |
| 1 | INT-07, INT-08: `KeyError: 'data'` | The scripts poll `/status` until PROFILED (or a limit), then read `GET /analyses/{id}/profile` `.json()["data"]`. With the worker down the status stayed `VALIDATED`; the profile endpoint answered **HTTP 409 `{"error":{"code":"STEP_NOT_READY","message":"The dataset has not been profiled yet."}}`**, which has no `data` key. Reproduced on 2026-10-16 22:53 against analysis `3f252c4b…` (still unprofiled). | **Test-environment failure** (no queue consumer) exposed by a **harness weakness** (scripts assume the wait succeeded instead of asserting the state and surfacing the 409). The API behaved correctly. |
| 2 | SYS-03, SYS-06, SYS-07, SYS-08, SYS-09: Playwright timeouts | The browser waited for the profile page, analysis completion, charts, evidence badge and report, all of which depend on `ProfileAnalysisJob`/`RunAnalysisJob`/`GenerateReportJob` on the `default` queue. Nothing was processed, so the elements never appeared. | **Test-environment failure** (same cause). Not a UI defect; the same cases passed in Round 2 and in the recovery run. |
| 3 | SYS-12: `'data'` | Same mechanism: the idempotency case re-runs the analysis and reads `data` from a step response that returned the 409 envelope because the analysis had not been profiled. | **Test-environment failure**, same cause. |
| 4 | `defect_retests_r3.py`: `KeyError: 'data'` at `accept_defs` | Analysis `b72892d1…` ("Unit7 retest X (report)") stayed VALIDATED until 22:44 and was profiled only after the worker restart (now PROFILED). | **Test-environment failure**, same cause. |
| 5 | `r3_ai_resilience.py`: `docker compose stop backend-worker` timed out after 120 s | Root cause has two layers. (a) **Application/operations defect (new, DEF-10):** the worker images had no `pcntl` extension, so `queue:work` cannot install signal handlers; running as PID 1 it ignores SIGTERM, every stop/restart waits the full 120 s grace period and ends with SIGKILL, i.e. a job in flight is killed instead of finished (contradicts the documented intent "the worker finishes its current job first" in `deploy/aws/deploy.sh`). The production image (`dataspeak-api:latest` built 2026-10-06) has the same gap (`php -m` lists `posix` only). (b) **Harness error:** the subprocess timeout (120 s) was shorter than the grace period and the stop call sat outside the `try/finally`, so the failure left the stack without a worker. | **Application defect (DEF-10) + harness error.** |

## Corrective actions (recovery step 3)

| Action | Change | Verification |
| --- | --- | --- |
| DEF-10 fix | `pcntl` added to `docker/backend/Dockerfile` (`docker-php-ext-install … pcntl`) and `docker/backend/Dockerfile.prod` (`install-php-extensions … pcntl`); a build-time assertion `RUN php -r 'exit(extension_loaded("pcntl") ? 0 : 1);'` fails the image build if the extension is ever dropped (regression guard). | Dev worker images rebuilt 22:49–22:55: `php -m` lists `pcntl`; `docker compose stop backend-worker-translations` on an idle worker took **0 s** (was 120 s). Mid-job behaviour covered by R3-AI-07 in the recovery run. |
| Harness | `r3_ai_resilience.py`, `final_checks.py`, `def09_w5b_busy_retry.py`: the worker stop and one-off worker start moved inside `try` so `finally` always restores the default worker; stop called with `-t 130` and a 400 s subprocess timeout (timeout longer than the grace period, not a longer wait for the product). | Recovery run. |
| Procedure | Every recovery step runs in one sequential chain after the image rebuild; the worker state is checked at the start of each step by the scripts' `idle` helpers. | `round3_chain2.log` |

No timeout used by the product was changed. Re-execution results are in `../final-round3-verification.md`.
