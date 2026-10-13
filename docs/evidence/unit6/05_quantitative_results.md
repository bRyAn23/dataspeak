# Unit 6 Evidence 05: Quantitative Results Table

Data Speak, UoPeople MSIT 5910. Compiled on 2026-10-13 from the measured evidence in this folder only. Every figure below was recomputed from its source file before being entered (section 3). Machine-readable copy: [05_quantitative_results.csv](05_quantitative_results.csv).

**Table 1**

*Quantitative Performance Evaluation of Data Speak*

| Metric | Measurement method | Measured result | Acceptance threshold | Interpretation |
| --- | --- | --- | --- | --- |
| 1. Statistical reference-test pass rate (%) | 33 reference assertions in three pytest functions, executed unchanged, on the golden datasets `tiny.csv` and `retail_sales.csv` and an eight-row in-test sample. Expected values were hand-verified constants or values from Python's `statistics` module; tolerances were exact equality, relative 1e-9, or relative 1e-6 as recorded per case. Pass rate = passed cases / total cases × 100. | 100.00% (33/33); numeric-only subset 100.00% (26/26) | Not predefined | All descriptive statistics, group aggregates, group-difference ratio, trend granularity, period count, slope sign and null-rate cases agreed with the independent references within tolerance. The reference set does not include Spearman correlation, chi-square association or outlier counts, so the rate applies to the metrics listed in [02_statistical_accuracy.md](02_statistical_accuracy.md) only. |
| 2. FastAPI test counts (collected / passed / failed / skipped) | `pytest -v -ra` over `analytics/tests` with Python 3.9.6 and pytest 8.4.2; counts read from the saved log's `collected` line, per-test result lines and final summary. | 49 / 49 / 0 / 0 (no errors or expected failures) | Not predefined | Every collected test executed and passed on the evaluated working tree; the count matches the project documentation. A passing suite shows the absence of the failures the tests were designed to detect, not the absence of defects in general. |
| 3. FastAPI line coverage (%) | pytest-cov 7.1.0 (coverage 7.10.7) over the `app` package with default settings and no exclusions during the run in metric 2. Line coverage = (statements − missed) / statements. | 92.83% (1 381 statements, 99 missed; printed by pytest-cov as 93%) | Not predefined | The 99 uncovered statements are concentrated in insufficient-data branches, large-magnitude number formatting, insight wording for types the golden data does not trigger, and error paths. Coverage measures which code the tests executed; it does not measure the correctness of the values produced, which metric 1 addresses. |
| 4. Average API latency (ms) | Arithmetic mean of 30 strictly sequential authenticated requests to `GET /api/v1/analyses/{id}/status` after five discarded warm-up requests, over one persistent HTTP/1.1 connection, timed with the monotonic `perf_counter_ns` clock. Target was the local Docker development stack (PHP built-in server, loopback, no TLS). | 9.071 ms (SD 0.329 ms; range 8.594 to 9.799 ms) | Not predefined | Single-request latency on the development deployment was low and stable. The value is not an estimate of production latency, which adds Internet transit, TLS, a reverse proxy and a smaller instance, and it is not a measure of concurrent throughput. |
| 5. Median API latency (ms) | Median of the same 30 durations. | 9.020 ms | Not predefined | The median lies within 0.05 ms of the mean, indicating a symmetric, narrow distribution without outliers in this sample. |
| 6. P95 API latency (ms) | 95th percentile by the nearest-rank method: the value at rank ⌈0.95 × 30⌉ = 29 of the sorted durations. Linear interpolation (numpy default) is reported for comparison. | 9.656 ms (linear interpolation 9.580 ms) | Not predefined | The 95th percentile is 0.6 ms above the median. With n = 30 it rests on a single observation and should be read as indicative rather than precise. |
| 7. HTTP success rate (%) | Share of the 30 measured requests that returned HTTP 200; transport errors would have been recorded as failures. | 100.0% (30/30) | Not predefined | No failed or non-200 response occurred during the measured sequence on the development stack. |

*Note.* Measurements were taken on 2026-10-13 (Asia/Bangkok) on a macOS 26.6.2 host with an Apple M5 Pro processor and 64 GiB RAM, against repository commit `c4a1951` on `main` plus uncommitted working-tree changes recorded in [01_test_environment.md](01_test_environment.md). Metrics 1 to 3 were produced by the analyst service's own pytest suite, executed without modification; metrics 4 to 7 come from a single sequential run against the local development API, not the production deployment. Sources: [02_statistical_accuracy.csv](02_statistical_accuracy.csv), [03_pytest_results.log](03_pytest_results.log), [03_coverage_summary.md](03_coverage_summary.md), [latency_results.csv](latency_results.csv), [04_api_latency.md](04_api_latency.md).

## 1. Acceptance thresholds

No numeric acceptance criteria for test pass rate, coverage, latency or success rate were defined in the project requirements before testing. The original specification (*Data_Speak_Program_Specification_and_Architecture*) states the relevant non-functional requirements qualitatively: NFR-PER-001, "UI remains responsive; long analysis uses asynchronous processing"; NFR-REL-001, "Failures return structured status and support safe retry"; NFR-MNT-001, "Layered modules, typed contracts, automated tests". None specifies a percentage or a millisecond target. In accordance with the evaluation protocol, every threshold cell therefore reads "Not predefined" rather than a target chosen after the results were known. Any target adopted for future releases should be set before the next measurement cycle.

## 2. Reading the table

The three test-based metrics and the four latency metrics measure different properties and should not be combined into a single score. Metric 1 is the only direct evidence of numerical correctness and is bounded by the set of calculations that have independent references. Metrics 2 and 3 describe the reach and outcome of the automated suite; a complete pass with 92.83% line coverage indicates a well-exercised code base but does not establish that uncovered or unasserted behaviour is correct. Metrics 4 to 7 characterise the response time of one read-only endpoint under sequential, single-user conditions on a development server; they demonstrate that the endpoint is lightweight and stable in that setting and nothing more.

## 3. Validation of the figures

All values were recomputed from the source files immediately before this table was written:

| Figure | Recomputed from | Result | Matches source document |
| --- | --- | --- | --- |
| 33/33 reference cases, 26/26 numeric | `02_statistical_accuracy.csv` (`outcome`, `case_type` columns) | 100.00% / 100.00% | Yes (02_statistical_accuracy.md) |
| 49 collected, 49 passed, 0 failed, 0 skipped | `03_pytest_results.log` (`collected 49 items`; 49 `PASSED` lines; 0 `FAILED`, `SKIPPED`, `ERROR`; `49 passed in 2.08s`) | 49 / 49 / 0 / 0 | Yes (03_coverage_summary.md) |
| 92.83% coverage | `03_pytest_results.log` TOTAL line 1381 / 99 → (1381 − 99) / 1381 | 92.83% (93% rounded) | Yes (03_coverage_summary.md, coverage.xml line-rate 0.9283) |
| Mean 9.071 ms, median 9.020 ms | `latency_results.csv` (30 `duration_ms` values) | 9.071 / 9.020 | Yes (04_api_latency.md) |
| P95 9.656 ms | sorted durations, rank ⌈0.95 × 30⌉ = 29 | 9.656 (linear 9.580) | Yes (04_api_latency.md) |
| Success 100.0% | `latency_results.csv` (`http_status` = 200 and `success` = True for 30 of 30) | 30/30 | Yes (04_api_latency.md) |
