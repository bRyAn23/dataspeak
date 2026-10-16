# DEF-09 Fix Summary: ModelAccessCoordinator

Data Speak, Unit 7 Test Round 3, 2026-10-16. Commit: see `final-defect-register.md` (recorded after the verification passed).

## 1. Design

A small coordinator serialises access to the single local model inside the application and gives user-facing requests bounded priority over background requests. It is a decorator around the existing `LlmClient`, so no call site that talks to the model had to change.

| Element | Behaviour |
| --- | --- |
| Lock | One Redis cache lock per driver+model (`llm:model:<hash>`) with a TTL (`AI_MODEL_LOCK_TTL`, default 330 s, longer than `OLLAMA_TIMEOUT`). Acquired with `Cache::lock()->block()` polling every 250 ms, released in `finally`, so an exception, a timeout or a crashed worker cannot leave the model locked for longer than the TTL. |
| Request classes | `interactive` (default: narrative generation, column-definition suggestions, on-demand translation of a report a user is viewing) and `background` (pre-translation jobs, which wrap their work in `asBackground()`). |
| Priority | An interactive request increments a "waiting" counter in the cache while it waits for and holds the lock. A background request first steps aside while that counter is above zero, for at most `AI_MODEL_LOCK_BG_YIELD` seconds (default 120 s), then competes for the lock normally. Background work therefore cannot be starved indefinitely. |
| Bounded waits | Interactive requests wait at most `AI_MODEL_LOCK_WAIT` (default 240 s) for the lock; background requests at most `AI_MODEL_LOCK_BG_WAIT` (300 s). The wait never exceeds the bound (the lock gives up one poll interval before it). A request whose wait runs out raises `LlmUnavailableException::busy()` with the new code `AI_BUSY`; **nothing is sent to the model** in that case. |
| No interruption | A request that already holds the lock is never cancelled; its own HTTP timeout still applies. |
| Controlled failure | `AI_BUSY` is handled by the existing "model unavailable" paths: the report is assembled deterministically and the generation row records the code, so no claims are ever fabricated. |
| Bounded retry | `GenerateReportJob` now has `tries = 2`. When the generation failed with `AI_BUSY` **and** this was the first attempt, the job is released back to the queue once with `AI_MODEL_BUSY_RETRY_DELAY` (default 60 s). Any other failure (schema, outage, model timeout) is final, as before. `shouldRetryForBusy()` is a pure, tested policy function. |
| Translation batches | `CoordinatedLlmClient::chatMany` takes the lock once per request rather than once per batch set, so an interactive request can slip in between two translation batches instead of waiting for all of them. |
| Scope | Enabled automatically when `AI_DRIVER=ollama` (`AI_MODEL_LOCK=auto`). For API providers (Anthropic in production) the decorator passes calls straight through, including the provider's concurrent `chatMany`. `AI_MODEL_LOCK=true|false` overrides. |
| Logging | `llm.lock.waited` (waits above 200 ms, with the duration), `llm.lock.timeout`, `llm.background.yielded` (with `proceeding_after_bound`), `report.model_busy_retry`. No prompt or data content is logged. |

Deterministic analysis results and CSV handling are untouched: the coordinator wraps only the model call, after the deterministic layer has produced its evidence.

## 2. Files changed

| File | Change |
| --- | --- |
| `backend/app/Services/Ai/ModelAccessCoordinator.php` | new: lock, waiting counter, bounded yield, request classes |
| `backend/app/Services/Ai/Clients/CoordinatedLlmClient.php` | new: `LlmClient` decorator |
| `backend/app/Services/Ai/LlmUnavailableException.php` | `busy()` factory, code `AI_BUSY` |
| `backend/app/Providers/AppServiceProvider.php` | coordinator singleton; `LlmClient` binding wraps the driver client |
| `backend/app/Jobs/TranslateReportJob.php` | runs its work inside `asBackground()` |
| `backend/app/Jobs/GenerateReportJob.php` | `tries = 2`, `shouldRetryForBusy()`, one delayed release on `AI_BUSY` |
| `backend/config/dataspeak.php`, `backend/.env.example` | `ai.coordinator` block and the `AI_MODEL_LOCK*` variables |
| `backend/tests/Unit/ModelAccessCoordinatorTest.php` | new: DEF09-01 to DEF09-08 |

## 3. Configuration (all limits are environment variables)

| Variable | Default | Meaning |
| --- | --- | --- |
| `AI_MODEL_LOCK` | `auto` | `auto` = on for the ollama driver only; `true`/`false` to force |
| `AI_MODEL_LOCK_TTL` | 330 | lock expiry in seconds (safety net; the lock is normally released immediately) |
| `AI_MODEL_LOCK_WAIT` | 240 | maximum wait for an interactive request |
| `AI_MODEL_LOCK_BG_WAIT` | 300 | maximum wait for a background request |
| `AI_MODEL_LOCK_BG_YIELD` | 120 | maximum time a background request steps aside for waiting interactive work |
| `AI_MODEL_BUSY_RETRY_DELAY` | 60 | delay before the single `GenerateReportJob` retry after `AI_BUSY` |

`OLLAMA_TIMEOUT` was **not** changed (still 300 s). With the coordinator in place that timeout now measures generation time only, because a request no longer queues inside the Ollama server behind another application request.

## 4. Operational notes

- `OLLAMA_NUM_PARALLEL` stays unset. Memory was measured before deciding (`def09-root-cause-analysis.md`, section 2.6): the machine could afford a second slot, but it would not remove the contention and would slow concurrent generations. If it is ever raised, the coordinator still serialises application requests; set `AI_MODEL_LOCK=false` to let Ollama schedule them instead.
- Production (Claude API): the coordinator is inactive; nothing changes in request behaviour. The `tries = 2` retry path is only entered on `AI_BUSY`, which API providers never raise.
- The Redis cache store is required for the lock to be shared across the two worker containers (already the case in `docker-compose.yml` and the production compose file). With the array cache store (unit tests) the lock is process-local, which the tests use deliberately.
