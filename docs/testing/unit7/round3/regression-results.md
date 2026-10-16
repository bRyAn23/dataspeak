# Round 3 Regression Results

Data Speak, Unit 7 Test Round 3. First execution 2026-10-16 20:08–20:12 (+07) on the working tree (commit `e4ae051` plus the DEF-09 correction, later committed as `57b7bce`); logs `evidence/logs/phpunit_def09.log`, `evidence/logs/pytest_final.log`, `evidence/logs/golden_reference_final.log`, `evidence/logs/frontend_final.log`. **Re-executed 2026-10-16 23:23 after the DEF-10 image change at commit `c68be35`** in a real Terminal window with identical results (`recovery/evidence/regression/suites_terminal.log`, `recovery/evidence/regression/golden_reference_recovery.log`); the Terminal screenshot `recovery/evidence/regression/figure-r3-regression-suites.png` is captured when the screen is unlocked (see `recapture_watcher.log`). Historical reference values are quoted from the Round 1 and Round 2 documents and were not re-derived.

## 1. Automated suites

| Suite | Command | Round 1 (2026-10-15) | Round 2 (2026-10-16 a.m.) | **Round 3** | Pass / fail / skip / error | Change vs Round 2 |
| --- | --- | --- | --- | --- | --- | --- |
| FastAPI analytics: unit, golden, contract (pytest, Python 3.9 venv) | `pytest -q --cov=app` | 49 passed, 93 % | 49 passed, 93 % (1,381 stmts, 99 missed) | **49 passed, 93 %** (1,381 stmts, 99 missed) | 49 / 0 / 0 / 0 | none (no analytics change in Round 3) |
| Golden-reference assertions | `docs/evidence/unit6/scripts/reference_cases.py` | 33/33 (26/26 numeric) | 33/33 | **33/33 (26/26 numeric)** | 33 / 0 / 0 / 0 | identical values |
| Analytics lint | `ruff check .` | clean | clean | **clean** | | |
| Laravel backend (PHPUnit; SQLite in memory, FastAPI and model faked) | `php artisan test --compact` | 148 passed, 903 assertions | 154 passed, 921 assertions | **162 passed, 955 assertions**, 9.3 s | 162 / 0 / 0 / 0 | +8 tests (DEF09-01..08), +34 assertions |
| Backend style | `vendor/bin/pint --test` | passed | passed | **passed** | | |
| React frontend (Vitest + MSW) | `npm test` | 28 passed, 14 files | 28 passed | **28 passed, 14 files** | 28 / 0 / 0 / 0 | none |
| Frontend lint / format / types / build | `npm run lint`, `format:check`, `typecheck`, `build` | clean (2 pre-existing warnings), built | same | **0 errors, same 2 pre-existing warnings (`no-explicit-any` in `vite.config.ts`); Prettier clean; `tsc` exit 0; built in 168 ms** | | none |

Total automated: **272 executed, 272 passed, 0 failed, 0 skipped, 0 errors** (49 + 162 + 28 + 33 golden assertions), identical in both executions (20:08 and 23:23).

## 2. What the suites verify (mapping to the Round 3 checklist)

| Area required by the Round 3 brief | Where it is tested | Round 3 result |
| --- | --- | --- |
| CSV parsing, dataset validation | `analytics/tests/unit/test_csv_reader.py`, `test_endpoints.py`, `test_security.py` | pass |
| Data profiling, type inference | `test_inference_and_profile.py` | pass |
| Descriptive statistics, statistical calculations, golden references | `test_engine_golden.py`, `test_rules_and_selection.py`, `test_output_validation.py`; 33 reference assertions | pass, values identical to Rounds 1–2 (see section 3) |
| Insight detection | `test_rules_and_selection.py`, `test_engine_golden.py` (insight families and selection) | pass |
| OpenAPI contract | `analytics/tests/contract/test_openapi_contract.py` (DEF-06 pins) | pass |
| Authentication, authorization, request validation, database access, analytics integration, report lifecycle, queue processing | `backend/tests/Feature/**` (38 test files in Feature and Unit) | pass |
| Claim validation | `RuleBasedClaimValidatorTest` incl. the DEF-01 cases | pass |
| Retry, timeout and scheduling of narrative and translation requests | `ModelAccessCoordinatorTest` DEF09-01..08 (bounded waits, AI_BUSY, AI_TIMEOUT, lock release on exception and TTL, background yield bound, pass-through for API providers), `GenerateReportJobTest` | pass |
| Frontend: authentication views, upload, validation feedback, profiling, charts, report rendering, error handling | 14 Vitest files under `frontend/src` | pass |
| Frontend accessibility | not unit-tested; verified in the browser with axe-core (`system-test-results.md`, FC-04, RT-07) | see those documents |

## 3. Numerical correctness

No deterministic code changed in Round 3 (the DEF-09 correction touches only the model-access layer, which runs after the deterministic evidence is produced). The 33 reference assertions (means, medians, sums, standard deviation, group aggregates, test selection, trend granularity and slope sign, null rate) reproduce exactly: `{"total": 33, "passed": 33, "numeric_total": 26, "numeric_passed": 26}`. The independent mean cross-check on a Round 3 analysis is recorded under INT-04 in `integration-results.md`.

## 4. New tests added in Round 3

`backend/tests/Unit/ModelAccessCoordinatorTest.php` (8 tests, 34 assertions, real lock logic on the array cache store, fake model client, waits shortened to 1 s by configuration):

| Test | Verifies |
| --- | --- |
| DEF09-01 | interactive request runs immediately on a free model; lock released afterwards |
| DEF09-02 | background request runs without waiting when nothing interactive is pending |
| DEF09-03 | background request yields the whole bound (≥ 0.9 s) while interactive work is waiting, then proceeds and logs `proceeding_after_bound` |
| DEF09-04 | with the lock held by another request, an interactive request waits the bound (never more), raises `AI_BUSY`, sends nothing; the holder keeps the lock |
| DEF09-05 | a model timeout propagates as `AI_TIMEOUT`, the lock is released, and the retry policy retries only `AI_BUSY` on the first attempt |
| DEF09-06 | a stale lock expires with its TTL; an exception inside the request releases the lock |
| DEF09-07 | results pass through unchanged; `chatMany` serialises per request; with the `anthropic` driver no lock is taken |
| DEF09-08 | `chatMany` under a permanently raised waiting counter still completes (no starvation) |

One test (DEF09-04) initially failed because the coordinator did not pass its poll interval to the lock, which then gave up one default interval (250 ms) early; the coordinator now sets the interval explicitly and the test asserts the wait is within one poll of the bound. The failing run is kept in the log history of this session (first block of `evidence/logs/phpunit_def09.log` was overwritten by the passing re-run; the failure is described here and in `defect09-verification.md`).

## 5. Coverage

Analytics coverage report (term): 1,381 statements, 99 missed, 93 %, lowest modules `insights/wording.py` 81 %, `visualization/recommender.py` 84 %. Unchanged from Round 2. No coverage tooling is configured for PHPUnit (`coverage: none` in CI) or Vitest in this project; none was added in Round 3. Historical coverage artefacts under `../evidence/regression/` were not modified.
