# Hosted CI Verification (GitHub Actions)

Data Speak, Unit 7 final verification, 2026-10-16. Status: **PENDING** for every defect-fix commit, because the fix commits have not been pushed. Nothing was pushed in this session; the project owner pushes.

## 1. What was checked

- Workflow definition `.github/workflows/ci.yml` (triggers: push to `main`/`develop`, pull requests; jobs: Frontend lint/format/typecheck/test/build, Backend Pint + PHPUnit, Analytics Ruff + PyTest, Compose file validation).
- The public GitHub Actions API for the repository (`/repos/bRyAn23/dataspeak/actions/runs`, read-only, no token), queried on 2026-10-16 at 20:05 (+07).
- Local git state: `git status -sb` and `git log origin/main..HEAD`.

## 2. Findings

| Item | Observed |
| --- | --- |
| Remote `main` (`origin/main`) | `c4a1951` (pushed 2026-10-07 23:59 UTC) |
| Local `main` | `e4ae051` plus the uncommitted DEF-09 working tree; local is **10 commits ahead** of `origin/main` (e5ecc93, 0b33df0, 4db9db6, 9bc1e3f, 0a3137f, 457148b, 524842f, 0b32ead, 14b7c99, e4ae051) |
| Most recent hosted run | Run #1 on `c4a1951`, created 2026-10-08 00:02 UTC, conclusion **failure** |
| Failed job in that run | `Analytics (Ruff, PyTest)`, step `Run pytest -q` failed; `Backend (Pint, PHPUnit)`, `Frontend (lint, typecheck, test, build)` and `Compose file validates` succeeded |
| Hosted runs for the DEF-01..DEF-09 fix commits | **none exist**; the commits are local only |

The failed step on `c4a1951` is the condition recorded as DEF-06 (the OpenAPI contract test fails on an unpinned, newer FastAPI/Pydantic schema generator). Its fix (`457148b`, pinned `analytics/requirements.txt`) was reproduced locally in a CI-equivalent environment during retesting (`../retesting/logs/def06_ci_reproduction_after_fix.log`) but has not yet run on GitHub.

## 3. Local equivalents executed in this session (same commands as the workflow)

| Workflow step | Local command | Result | Log |
| --- | --- | --- | --- |
| Backend: `vendor/bin/pint --test` | same | passed | `logs/phpunit_def09.log` |
| Backend: `php artisan test` | `php artisan test --compact` | 162 passed, 955 assertions | `logs/phpunit_def09.log` |
| Analytics: `ruff check .` | same | All checks passed | `logs/pytest_final.log` |
| Analytics: `pytest -q` | `pytest -q --cov=app` (Python 3.9 venv) | 49 passed, 93% coverage | `logs/pytest_final.log` |
| Frontend: lint, format:check, typecheck, test, build | `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm test`, `npm run build` | lint 0 errors (2 pre-existing warnings), format ok, typecheck ok, 28 tests passed, build ok | `logs/frontend_final.log` |
| Compose file validates | `docker compose config` (validated during Unit 6/7 deployment evidence) | not re-run in this session | see `../../../evidence/unit6` |

## 4. What remains for the hosted verification

1. The owner pushes `main` (10 commits plus the two commits created at the end of this session).
2. GitHub Actions runs the four jobs automatically on the push.
3. Record the run URL and the per-job conclusions in `final-test-summary.md` and change this document's status from PENDING to PASSED or FAILED. Until then DEF-06 stays "VERIFIED locally, hosted run pending" and the CI line in the defect register keeps that wording.

No workflow file was changed in this session.
