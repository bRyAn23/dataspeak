# Round 3 Test Plan: Final Post-Fix System Verification

Data Speak, MSIT 5910-01 Capstone, Unit 7. Round 3 executed 2026-10-16 (evening, +07) on the local development stack by the investigator. Rounds 1 (2026-10-15, `../`) and 2 (2026-10-16 morning, `../retesting/`) are historical and read-only; see `historical-evidence-integrity.md`.

## 1. Objectives

1. Verify DEF-09 (narrative timeout under local-model contention) against the real implementation, with scheduling unit tests and real-model integration scenarios, under controlled and reproducible conditions.
2. Re-verify DEF-01 to DEF-08 on the current build and detect regressions.
3. Re-run every automated suite and the golden-reference assertions; re-run the integration and system harness; repeat the performance scenarios; perform investigator-led acceptance verification.
4. Produce a new, self-contained evidence package under `round3/` without touching earlier evidence.

## 2. System under test

Working tree on commit `e4ae051` plus the DEF-09 correction (`ModelAccessCoordinator`, `CoordinatedLlmClient`, bounded report retry, configuration), committed at the end of the round. Environment: `environment-report.md`.

## 3. Test levels, cases and tooling

| Level | Cases | Tool / script | Real or mocked | Output |
| --- | --- | --- | --- | --- |
| DEF-09 scheduling unit tests | DEF09-01..08 (`tests/Unit/ModelAccessCoordinatorTest.php`) | PHPUnit, array cache, fake model client | mocked model, real lock logic | `evidence/regression/` |
| DEF-09 real-model scenarios | R3-AI-01..10 (table below) | `scripts/def09_concurrency.py` (W1–W5), `scripts/r3_ai_resilience.py`, `scripts/final_checks.py`, `scripts/ollama_serial_probe.py` | real Ollama `qwen3:8b`, real workers, Redis, PostgreSQL | `defect09-verification.md`, `evidence/defect09/`, `performance-results.csv` |
| Defect retests | RT-01..08 (Round 2 procedures, re-executed) | `scripts/defect_retests_r3.py` (Round 3 copy of the Round 2 script with Round 3 output paths) | real stack, browser (Playwright + axe-core) | `defect-verification-matrix.md`, `evidence/defects/` |
| Regression | pytest + coverage, golden reference (33 assertions), PHPUnit, Pint, Vitest, ESLint, Prettier, tsc, Vite build, Ruff | project commands | unit level (mocked externals) | `regression-results.md`, `evidence/regression/`, `evidence/logs/` |
| Integration | INT-01..08 | `../scripts/system_tests.py` with `U7_EVIDENCE_DIR=round3/evidence` | real services (React, Laravel, PostgreSQL, Redis, FastAPI, Ollama), controlled outage of analytics | `integration-results.md`, `evidence/integration/` |
| System (end-to-end) | SYS-01..12 plus R3-E2E scenarios 1–14 mapping | same harness, `final_checks.py`, resilience script | real stack, browser | `system-test-results.md`, `evidence/system/` |
| Performance and stability | P1 FastAPI direct, P2 API latency, P3 end-to-end pipeline, plus DEF-09 concurrency workloads | `scripts/performance_tests_r3.py` (Round 3 copy, outputs redirected), `def09_concurrency.py` | real services | `performance-results.csv`, `evidence/performance/` |
| Hosted CI | workflow inspection, public Actions API | curl (no token) | n/a | `hosted-ci-verification.md` |
| Acceptance | ACC-01..12 (Round 1 criteria, re-inspected) | evidence of the above | investigator-led | `acceptance-results.md` |

### R3-AI scenarios

| ID | Scenario | Procedure | Expected | Source |
| --- | --- | --- | --- | --- |
| R3-AI-01 | Narrative without contention | W1: report on an idle model, 2 runs | SUCCEEDED, claims, no lock wait | `def09_concurrency.py` |
| R3-AI-02 | Translation without contention | W2: translation jobs for 3 languages on an idle model, 2 runs | jobs DONE, statements translated | same |
| R3-AI-03 | Narrative during translation | W3: translation in flight, then report request, 2 runs | narrative gets the model after at most one batch; bounded wait logged; SUCCEEDED; no timeout | same |
| R3-AI-04 | Multiple concurrent requests | W4: translation in flight + two reports back to back, 2 runs | both reports SUCCEEDED, waits bounded, no AI_TIMEOUT, translations finish | same |
| R3-AI-05 | Model unavailable | FC-01: worker with an unreachable model endpoint | READY report, 0 claims, `AI_UNAVAILABLE`, no retry, no fabrication | `final_checks.py` |
| R3-AI-06 | Model timeout and recovery | real `OLLAMA_TIMEOUT=8` worker, then a normal report; plus W5 bounded-wait expiry (`AI_BUSY`, one retry) | controlled `AI_TIMEOUT`, lock released, next report succeeds; `AI_BUSY` retried once then SUCCEEDED | `r3_ai_resilience.py`, W5 |
| R3-AI-07 | Queue worker restart | restart both workers while a translation runs | queue drains, no flags/lock/reserved jobs left, translations complete | `r3_ai_resilience.py` |
| R3-AI-08 | Translation after narrative | FC-02 + W1 wake | th/zh/my translations present, served by language header, flags cleared | `final_checks.py` |
| R3-AI-09 | Statistical integrity | golden 33 assertions, pytest golden tests, INT-04 cross-check on a Round 3 analysis | identical deterministic values | pytest, `reference_cases.py`, harness |
| R3-AI-10 | Fair scheduling | DEF09-03/08 (bounded yield) + W3/W4 translation durations and yield events | translations complete; background proceeds after the yield bound | PHPUnit + `def09_concurrency.py` |

## 4. Entry and exit criteria

Entry: stack healthy (`environment-report.md`), historical manifest written. Exit: every planned case executed or marked BLOCKED/SKIPPED with a reason; DEF-09 marked VERIFIED only if R3-AI-03 and R3-AI-04 pass on the real model; closing integrity check appended.

## 5. Constraints

- Production and staging untouched; no secrets in artefacts; no destructive git commands; historical folders never written.
- Ollama serves one request at a time (confirmed by `ollama_serial_probe.py`), so model-dependent scenarios run sequentially; durations are from a developer laptop and are not production figures.
- Pass criteria are the ones defined in Rounds 1–2; any new Round 3 threshold is labelled as such in the results documents.
