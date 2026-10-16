# Unit 7 Regression Test Results (post-fix baseline)

Executed 2026-10-16 on the local development stack after the corrections described in `defect-resolution-report.md`. Each suite ran unchanged apart from the tests added or adjusted for the fixes (listed in that report). Baseline for comparison: the pre-fix runs of 2026-10-15 in `../evidence/regression/`.

## 1. Automated suites

| Suite | Command | Pre-fix (2026-10-15) | Post-fix (2026-10-16) | Change | Log |
| --- | --- | --- | --- | --- | --- |
| Analytics unit, golden and contract (pytest) | `pytest --cov=app -q` | 49 passed, 93% coverage (1,381 stmts, 99 missed) | **49 passed, 93%** (1,381 stmts, 99 missed) | none; DEF-03 changed only a display string | `logs/analytics_after_fix.log` |
| Golden-reference assertions | `reference_cases.py` | 33/33 (26/26 numeric) | **33/33 (26/26 numeric)** | identical values | `logs/analytics_after_fix.log` |
| Analytics lint and format | `ruff check .`, `ruff format --check .` | clean | **clean** | | same |
| Backend (PHPUnit, in-memory SQLite, FastAPI and LLM faked) | `php artisan test` | 148 passed, 903 assertions | **154 passed, 921 assertions** | +6 tests (3 DEF-01, 3 DEF-02); 1 assertion updated for the validator version name | `logs/phpunit_after_fix.log` |
| Backend style (Pint) | `vendor/bin/pint --test` | passed | **passed** | | same |
| Frontend (Vitest, MSW) | `vitest run` | 28 passed in 14 files | **28 passed in 14 files** | none | `logs/frontend_after_fix.log` |
| Frontend format, lint, typecheck, build | `prettier --check`, `eslint`, `tsc --noEmit`, `vite build` | clean; 2 pre-existing warnings in vite.config.ts; built | **clean; same 2 pre-existing warnings; built in 350 ms** | | same |
| CI analytics job reproduced (python:3.12-slim, pinned requirements) | `pip install -r requirements-dev.txt && ruff check . && ruff format --check . && pytest -q` | 48 passed, 1 failed (frozen contract) | **49 passed, 0 failed** (fastapi 0.128.8, pydantic 2.13.5, starlette 0.49.3 resolved) | DEF-06 fixed | `logs/def06_ci_reproduction_after_fix.log` |
| Compose configuration (dev and prod) | `docker compose config --quiet` | valid | **valid**, services now include `backend-worker-translations` / `worker-translations` | DEF-04 | `logs/dev_stack_apply_def04_def06.log` |

Figure 09 (`evidence/figure-09-regression-after-fix.png`) is a terminal capture of these summaries.

## 2. Numerical correctness after the changes

The deterministic layer was not altered except for the Spearman display string. The 33 reference assertions (means, medians, sums, standard deviation, group aggregates, test selection, trend granularity and slope sign, null rate) reproduce exactly; the Spearman evidence for the golden retail dataset keeps `value = 0.7054` and `method = spearman_rank_correlation`, while `display_value` changed from `rho=+0.71` to `+0.71`.

## 3. Integration and end-to-end regression

See `defect-retesting-results.md` for the scripted retests of each defect and `final-test-summary.md` for the full re-execution of the Unit 7 integration and system harness against the corrected build (recorded under `evidence/system/` of this folder, separate from the 2026-10-15 baseline).
