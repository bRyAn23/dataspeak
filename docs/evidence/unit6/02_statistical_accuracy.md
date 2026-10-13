# Unit 6 Evidence 02: Statistical Accuracy of the Analyst Service

Data Speak, UoPeople MSIT 5910. Evaluation date 2026-10-13 (Asia/Bangkok). Code: `main` at `c4a1951` plus the uncommitted working tree described in [01_test_environment.md](01_test_environment.md). Runtime: Python 3.9.6, pytest 8.4.2, Polars 1.36.1, NumPy 2.0.2, SciPy 1.13.1, statsmodels 0.14.6.

## 1. Methodology

The analyst service computes every statistic deterministically with Polars, NumPy, SciPy and statsmodels. Its accuracy was evaluated with the repository's existing reference-value tests, executed unchanged. A *reference case* is a single assertion that compares a value produced by the analysis engine with an expected value obtained independently of the engine, by one of two routes:

1. **Hand-verified constants.** The golden dataset `analytics/tests/golden/tiny.csv` (six rows, two regions) has values small enough to compute by hand; the expected mean, median, minimum, maximum, sum, group means and group difference are written as literals in `test_tiny_golden_hand_verified_values`.
2. **Independent library cross-check.** The golden dataset `analytics/tests/golden/retail_sales.csv` (240 synthetic rows, Thai and Myanmar headers, monthly growth, 15% missing discounts) and the eight-row sample in `test_output_validation.py` have expected values computed at test time with Python's standard `statistics` module (`fmean`, `median`, `stdev`) and plain `sum()`, `len()` and null counts, never from the implementation under test.

Each case states its tolerance explicitly: exact equality for integers and hand-verified values, a relative tolerance of 1e-9 for cross-checked floats, the `pytest.approx` default of 1e-6 where the test used it, and sign, threshold or set membership for method-selection checks. The procedure follows standard unit-testing practice: one component is isolated, given controlled inputs, and its output is compared with a known expected result.

To separate reference accuracy from general correctness, the complete suite of 49 tests was classified (section 4) and only the three reference tests, decomposed into their 33 individual assertions, enter the pass-rate calculation. The decomposition was produced by `scripts/reference_cases.py`, which re-runs the engine on the same inputs, derives the expected values exactly as the tests do, and records expected value, actual value, tolerance and outcome for each assertion into [02_statistical_accuracy.csv](02_statistical_accuracy.csv). No assertion, expected value or tolerance was altered.

**Execution commands**

```bash
cd analytics
.venv/bin/python -m pytest tests/unit/test_engine_golden.py tests/unit/test_output_validation.py \
    tests/unit/test_rules_and_selection.py -v -p no:cacheprovider      # saved to logs/02_pytest_statistical_accuracy.log
.venv/bin/python -m pytest -q -p no:cacheprovider                        # full suite, logs/02_pytest_full_suite.log
.venv/bin/python ../docs/evidence/unit6/scripts/reference_cases.py       # per-assertion CSV
```

## 2. Results

### 2.1 Pass rate

Pass Rate (%) = (Passed Reference Cases / Total Reference Cases) × 100

| Scope | Passed | Total | Pass rate |
| --- | --- | --- | --- |
| All reference cases (numeric and structural) | 33 | 33 | 100.0% |
| Numeric reference cases only | 26 | 26 | 100.0% |
| Reference tests as pytest functions | 3 | 3 | 100.0% |

For context only, and not as a measure of statistical accuracy: the full analytics suite passed 49 of 49 tests.

### 2.2 Reference cases

Values are reproduced from the CSV. Floating values are shown to full double precision so the agreement is visible.

| Case | Test | Dataset | Calculation | Expected | Actual | Tolerance | Outcome |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SA-01 | tiny | tiny.csv | row_count | 6 | 6 | exact | PASS |
| SA-02 | tiny | tiny.csv | mean(amount) | 30 | 30.0 | exact | PASS |
| SA-03 | tiny | tiny.csv | median(amount) | 30 | 30.0 | exact | PASS |
| SA-04 | tiny | tiny.csv | min(amount) | 10 | 10.0 | exact | PASS |
| SA-05 | tiny | tiny.csv | max(amount) | 50 | 50.0 | exact | PASS |
| SA-06 | tiny | tiny.csv | sum(amount) | 180 | 180.0 | exact | PASS |
| SA-07 | tiny | tiny.csv | group mean, region N | 20 | 20.0 | exact | PASS |
| SA-08 | tiny | tiny.csv | group mean, region S | 40 | 40.0 | exact | PASS |
| SA-09 | tiny | tiny.csv | group sum, region S | 120 | 120.0 | exact | PASS |
| SA-10 | tiny | tiny.csv | top-bottom mean difference (40-20)/20 | 1.0 | 1.0 | exact | PASS |
| SA-11 | tiny | tiny.csv | test selection, 3 rows per group | none_applicable / insufficient | none_applicable / insufficient | exact | PASS |
| SA-12 | tiny | tiny.csv | trend granularity, 6 monthly dates | month | month | exact | PASS |
| SA-13 | retail | retail_sales.csv | mean(revenue), n=240 | 383.4678333333333 | 383.4678333333334 | rel 1e-9 | PASS |
| SA-14 | retail | retail_sales.csv | median(revenue) | 372.16999999999996 | 372.16999999999996 | rel 1e-9 | PASS |
| SA-15 | retail | retail_sales.csv | sample std(revenue) | 106.90131567569158 | 106.90131567569159 | rel 1e-9 | PASS |
| SA-16 | retail | retail_sales.csv | sum display value carries unit | ends with THB | 92,032 THB | suffix | PASS |
| SA-17 | retail | retail_sales.csv | group mean(revenue), North | 485.4252427184466 | 485.4252427184466 | rel 1e-9 | PASS |
| SA-18 | retail | retail_sales.csv | group count, North | 103 | 103 | exact | PASS |
| SA-19 | retail | retail_sales.csv | top group share sum(North)/sum(all) | 0.5432745988690055 | 0.5432745988690055 | rel 1e-6 | PASS |
| SA-20 | retail | retail_sales.csv | top group identity | North | North | exact | PASS |
| SA-21 | retail | retail_sales.csv | group test method, 4 regions | one_way_anova or kruskal_wallis | kruskal_wallis | set | PASS |
| SA-22 | retail | retail_sales.csv | group test p-value | < 0.05 | 4.285e-39 | threshold | PASS |
| SA-23 | retail | retail_sales.csv | trend granularity, 240 daily rows | month | month | exact | PASS |
| SA-24 | retail | retail_sales.csv | periods in series | 12 | 12 | exact | PASS |
| SA-25 | retail | retail_sales.csv | trend slope sign | > 0 | 178.91517482517474 | sign | PASS |
| SA-26 | retail | retail_sales.csv | trend slope n_periods | 12 | 12 | exact | PASS |
| SA-27 | retail | retail_sales.csv | null_rate(discount_pct) | 0.14583333333333334 | 0.14583333333333334 | rel 1e-6 | PASS |
| SA-28 | retail | retail_sales.csv | null-rate quality flag at 15% | warning | warning | exact | PASS |
| SA-29 | descriptive | 8-row sample | sum(revenue), n=7 | 879.0 | 879.0 | rel 1e-6 | PASS |
| SA-30 | descriptive | 8-row sample | mean(revenue) | 125.57142857142857 | 125.57142857142857 | rel 1e-6 | PASS |
| SA-31 | descriptive | 8-row sample | median(revenue) | 60.0 | 60.0 | rel 1e-6 | PASS |
| SA-32 | descriptive | 8-row sample | min(revenue) | 25.0 | 25.0 | exact | PASS |
| SA-33 | descriptive | 8-row sample | max(revenue) | 495.0 | 495.0 | exact | PASS |

Test names in full: tiny = `test_tiny_golden_hand_verified_values`; retail = `test_retail_golden_cross_checked_with_statistics_module`; descriptive = `test_descriptive_statistics_match_reference_values`.

### 2.3 Observations

- Hand-verified descriptive statistics, group aggregates and the relative group difference on `tiny.csv` matched exactly (SA-01 to SA-10).
- On `retail_sales.csv`, the engine's mean and sample standard deviation differ from the `statistics` module in the last significant digit (SA-13, SA-15), a difference of about 3e-16 relative, which is floating-point summation order and is well inside the 1e-9 tolerance. Median, group mean, share and null rate agreed to all printed digits.
- Method selection behaved as the rules prescribe: with three rows per group no test was applied (SA-11); with four regions Kruskal-Wallis was chosen (SA-21); the rule applies one-way ANOVA only when every group has at least 30 rows and Levene's test does not reject equal variances, and otherwise falls back to Kruskal-Wallis when every group has at least 8 rows. The p-value was far below 0.05 (SA-22).
- The trend module chose monthly granularity for both datasets and produced a positive slope over 12 periods for data with growth built in (SA-23 to SA-26).
- The 15% missing discount rate was computed exactly and flagged as a warning, matching the 10% threshold (SA-27, SA-28).

## 3. Evidence files

| File | Content |
| --- | --- |
| [logs/02_pytest_statistical_accuracy.log](logs/02_pytest_statistical_accuracy.log) | Verbose pytest output of the 17 tests in the three accuracy-related files, with date, commit and interpreter header |
| [logs/02_pytest_full_suite.log](logs/02_pytest_full_suite.log) | Quiet output of the full 49-test suite for context |
| [02_statistical_accuracy.csv](02_statistical_accuracy.csv) | 33 reference cases: test, dataset, calculation, library, expected, actual, tolerance, case type, outcome |
| [scripts/reference_cases.py](scripts/reference_cases.py) | Script that regenerates the CSV from the unchanged tests and golden data |
| [B01_statistical_accuracy.png](B01_statistical_accuracy.png) | Image of the verbose test run (see note below) |

**Note on the image.** `B01_statistical_accuracy.png` is a genuine macOS screenshot of a Terminal window in which the same three test files were executed again on 2026-10-13 at 23:51 (17 passed in 0.90 s; log `logs/02_pytest_statistical_accuracy_terminal_capture.log`). The per-case values in this report come from the earlier run recorded in `logs/02_pytest_statistical_accuracy.log`; both runs passed every test.

## 4. Classification of the full suite

Only the first group is statistical accuracy evidence. The remaining groups test correctness, safety and contracts and are listed to show they were excluded from the pass rate.

| Group | Tests (parametrised cases) | Files and functions |
| --- | --- | --- |
| Reference-value accuracy (counted) | 3 | `test_engine_golden.py`: `test_tiny_golden_hand_verified_values`, `test_retail_golden_cross_checked_with_statistics_module`; `test_output_validation.py`: `test_descriptive_statistics_match_reference_values` |
| Statistical behaviour, not value accuracy | 7 | `test_engine_golden.py`: `test_determinism_same_input_same_evidence`, `test_insights_reference_evidence_and_avoid_causal_language`, `test_visualizations_use_units_from_definitions_and_flag_missing_units`; `test_rules_and_selection.py`: `test_rule_constants_are_sane`, `test_test_selection_follows_sample_sizes`, `test_granularity_selection_follows_business_preference`, `test_definitions_override_inference_and_exclude_text_identifier` |
| CSV validation and profiling unit tests | 22 | `test_csv_reader.py` (10 cases incl. 4 delimiters), `test_inference_and_profile.py` (5), `test_output_validation.py` (7 non-statistical) |
| Integration (API round trips, temp files, limits) | 7 | `test_endpoints.py` |
| Security and logging | 6 | `test_security.py` (3), `test_logging.py` (3) |
| Health and contract | 4 | `test_health.py` (2), `tests/contract/test_openapi_contract.py` (2) |
| **Total** | **49** | |

## 5. Limitations

- Three reference tests with 33 assertions cover descriptive statistics, group aggregates, one non-parametric test, trend granularity and slope sign, and a null rate. Correlation (Spearman), chi-square and Cramer's V, histogram bins and outlier counts have rules tests but no independent reference value in the suite; they are not included in the pass rate.
- The slope and p-value are checked for sign and threshold only, not against an independently computed coefficient; a future reference case could compute the OLS slope with `statistics.linear_regression` on the monthly totals.
- Golden datasets are small and synthetic by design (no customer data is committed). Accuracy on large or irregular real datasets is not evidenced here.
- Tests ran on Python 3.9.6, while the deployed service runs on Python 3.12.15; the numerical libraries are the same major versions.
