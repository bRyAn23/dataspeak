# Unit 6 Evidence 03: Full Analyst Test Suite and Code Coverage

Data Speak, UoPeople MSIT 5910. Run on 2026-10-13 22:33 (Asia/Bangkok) against `main` at `c4a1951` with the uncommitted working tree described in [01_test_environment.md](01_test_environment.md). Interpreter Python 3.9.6, pytest 8.4.2, pytest-cov 7.1.0, coverage 7.10.7. No test or application code was modified for this run.

## 1. Configuration and command

The suite is configured in `analytics/pyproject.toml` (`[tool.pytest.ini_options]`: `pythonpath = ["."]`, `testpaths = ["tests"]`). There is no coverage configuration file, so coverage measures every statement under `app/` with default settings and no exclusions. pytest-cov is listed in `requirements-dev.txt` and was present in the virtual environment. The documented command was valid for this environment; it was extended only with report formats and verbosity so the evidence is complete:

```bash
cd analytics
.venv/bin/python -m pytest --cov=app --cov-report=term-missing \
    --cov-report=html:../docs/evidence/unit6/coverage_html \
    --cov-report=xml:../docs/evidence/unit6/coverage.xml \
    -v -ra -p no:cacheprovider --durations=5
```

The complete output is saved in [03_pytest_results.log](03_pytest_results.log) (126 lines, exit code 0).

## 2. Results

| Measure | Value |
| --- | --- |
| Tests collected | 49 |
| Passed | 49 |
| Failed | 0 |
| Skipped | 0 |
| Errors, expected failures | 0 |
| Execution duration | 2.08 s (pytest wall time) |
| Statements measured | 1 381 |
| Statements missed | 99 |
| Overall line coverage | 93% as printed by pytest-cov; 92.83% from `coverage.xml` (`line-rate="0.9283"`) |
| Files at 100% line coverage | 22 of 45 measured files |

**Verification of the documented claims.** The architecture documentation stated 49 FastAPI tests and 93% line coverage. Both figures were reproduced exactly on the current source code. The 93% is a rounding of 92.83%.

**Failures.** None occurred, so there are no failure causes to report. The `-ra` summary printed no skipped, xfailed or error entries.

**Slowest tests** (all well under a tenth of a second):

| Duration | Test |
| --- | --- |
| 0.08 s | `tests/contract/test_openapi_contract.py::test_evidence_schema_has_required_fields` |
| 0.06 s | `tests/unit/test_endpoints.py::test_validate_profile_analyze_roundtrip` |
| 0.06 s | `tests/unit/test_engine_golden.py::test_determinism_same_input_same_evidence` |
| 0.06 s | `tests/contract/test_openapi_contract.py::test_openapi_file_matches_application` |
| 0.03 s | `tests/unit/test_engine_golden.py::test_retail_golden_cross_checked_with_statistics_module` |

## 3. Coverage by module

Files below 95% line coverage, from the terminal report:

| File | Stmts | Miss | Cover | Missing lines |
| --- | --- | --- | --- | --- |
| app/services/insights/wording.py | 21 | 4 | 81% | 31, 52, 60, 64 |
| app/services/visualization/recommender.py | 82 | 13 | 84% | 28, 85-88, 105, 116-118, 141, 149-150, 154 |
| app/services/analysis/evidence.py | 48 | 7 | 85% | 21, 28, 67, 70, 72, 86, 92 |
| app/services/analysis/trend.py | 86 | 13 | 85% | 33, 43, 47, 50, 53, 61, 65, 69, 72-88, 93, 134 |
| app/services/insights/detector.py | 89 | 13 | 85% | 54, 57-59, 72, 77-78, 146, 185-186, 215, 229, 258 |
| app/services/validation/validator.py | 35 | 5 | 86% | 19, 33, 44, 53, 61 |
| app/services/profiling/profiler.py | 53 | 6 | 89% | 17, 20-21, 27, 43, 101 |
| app/services/analysis/association.py | 58 | 6 | 90% | 22, 24, 53, 100, 111-124 |
| app/services/validation/csv_reader.py | 72 | 7 | 90% | 40, 47, 50-52, 96-97, 123 |
| app/core/security.py | 13 | 1 | 92% | 25 |
| app/api/v1/deps.py | 43 | 3 | 93% | 28-32 |
| app/api/v1/analyze.py | 31 | 2 | 94% | 35, 39 |
| app/services/analysis/descriptive.py | 53 | 3 | 94% | 42-55, 138 |

All schema modules, `rules.py`, `main.py`, `config.py` and the validate, insights and visualizations endpoints are at 100%. The full table is in the log and, per line, in the HTML report (`coverage_html/index.html`).

**What the uncovered lines are.** The largest missed regions were inspected in the source. They fall into four kinds, none of which is a primary calculation path:

1. *Insufficient-data branches* that emit an evidence record with `quality="insufficient"` instead of a number: fewer than six distinct periods for a trend (`trend.py` 72-88), chi-square expected counts too small (`association.py` 111-124), a measure with no numeric values (`descriptive.py` 42-55). The golden datasets are well formed, so these branches are not reached.
2. *Formatting branches* for magnitudes the test data never produces, such as values in the millions or billions and `NaN` or infinity guards (`evidence.py` 67-72, 86, 92).
3. *Insight wording templates* for insight types the golden data does not trigger: categorical association, duplicate rows, outliers (`wording.py` 52-64), and the matching detector and recommender paths (`detector.py`, `recommender.py` 116-118).
4. *Error paths* such as malformed `metadata` JSON on the multipart endpoints (`deps.py` 28-32) and a geometric-versus-arithmetic growth fallback when a period ratio is non-positive (`trend.py` 43-53).

## 4. Evidence files

| File | Content |
| --- | --- |
| [03_pytest_results.log](03_pytest_results.log) | Complete verbose output: header with date, commit and versions; 49 test lines; coverage table; slowest durations; summary; exit code |
| [coverage.xml](coverage.xml) | Cobertura XML coverage report (machine-readable, `line-rate="0.9283"`) |
| [coverage_html/index.html](coverage_html/index.html) | HTML coverage report with per-line highlighting (1.4 MB, generated artefact) |
| [B02_pytest_coverage.png](B02_pytest_coverage.png) | Terminal screenshot of a repeat run: coverage table and `49 passed` summary |

**Note on the image.** `B02_pytest_coverage.png` is a genuine macOS screenshot of a Terminal window in which the full suite was executed again with coverage on 2026-10-13 at 23:53 (`pytest --cov=app --cov-report=term-missing -q`; 49 passed, TOTAL 1381 statements, 99 missed, 93%; log `logs/03_pytest_results_terminal_capture.log`). The figures in sections 2 and 3 come from the earlier verbose run in `03_pytest_results.log`; the two runs report identical counts and coverage.

## 5. Code coverage versus statistical correctness

Line coverage answers one question: *which statements were executed at least once while the tests ran?* It says nothing about whether the values those statements produced were right. A test that calls the mean function and asserts only that a result is returned raises coverage exactly as much as a test that compares the result with a hand-computed reference. Conversely, a statement can be executed by a test whose assertions concern something else entirely, and still contain a numerical error. Coverage is therefore a measure of *test reach*, a necessary condition for the suite to be able to detect a defect in a line, not a sufficient one.

Statistical correctness is a property of the *outputs*: that the engine's mean, median, standard deviation, group aggregates, test selection and trend estimates equal independently derived reference values within a stated tolerance. That property is evidenced in [02_statistical_accuracy.md](02_statistical_accuracy.md), where 33 reference assertions on two golden datasets and one in-test sample all passed against hand-verified constants and Python's `statistics` module. The two measures are complementary. Coverage reveals which code the suite never exercised (section 3 shows these are mostly insufficient-data and formatting branches). Reference-value tests reveal whether the exercised calculation code is numerically right. High coverage with weak assertions, or exact reference tests over a small part of the code, would each leave a different blind spot.

## 6. Interpretation for Part B

The analyst service's automated suite executed 49 tests with no failures, skips or errors in about two seconds, and exercised 92.83% of the 1 381 statements in the application package. These results reproduce the figures stated in the project documentation and confirm that the documentation describes the current code. The uncovered 7% is concentrated in defensive and degenerate-input branches rather than in the primary calculation paths, which is consistent with a suite built around well-formed golden datasets.

These findings should be read with the usual limits of dynamic testing in mind. A passing suite shows the absence of the specific failures the tests were written to detect, under the inputs they supply; it cannot show the absence of defects in general, and 100% test success is not a proof of correctness. Coverage of 92.83% means that roughly one statement in fourteen was never executed by any test, so faults in those statements would not have been observed. The reference-value tests give direct numerical evidence for descriptive statistics, group comparison, one non-parametric test, and trend granularity and slope sign, but not for Spearman correlation, chi-square association or outlier detection, which are covered only by rule and behaviour tests. The suite also ran on Python 3.9.6 while the deployed service runs on Python 3.12.15 with the same library versions, so the evidence is for the code rather than for the production interpreter. Within those bounds, the combination of complete test success, high and well-characterised coverage, and exact agreement on independently computed reference values supports the conclusion that the deterministic calculation layer behaves as specified for the kinds of input it was designed to handle.
