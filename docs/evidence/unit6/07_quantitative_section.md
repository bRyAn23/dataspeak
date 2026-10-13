# Unit 6 Evidence 07: Quantitative Methods and Results (Part B subsection)

Ready to paste into the Word document. Values match Table 1 ([05_quantitative_results.md](05_quantitative_results.md)), the pytest and coverage log ([03_pytest_results.log](03_pytest_results.log), image B02), the latency run ([latency_results.csv](latency_results.csv), image B03) and the chart (image B04). Word count of the subsection body: 247.

**Figure numbering.** The current draft (DataSpeak_Unit6_Parts_ABC_APA7_Corrected_Cover.docx) uses Table 1, Figure 5 (measured statistical tests and API response times) and Figure 6 (API response-time distribution) in Part B, so the text below refers to those. If the figures are renumbered so that the test and latency evidence becomes Figures 8 and 9, replace "Figure 5" with "Figure 8" and "Figure 6" with "Figure 9".

---

## Quantitative Methods and Results

Statistical accuracy and API latency were selected because they correspond to two key user-facing properties: that reported numbers are correct and that the interface responds promptly while long analyses run asynchronously. Both are measurable, repeatable indicators of the kind recommended for software quality reporting (Wells, 2025).

Accuracy was assessed with the analyst service's existing reference tests, executed unchanged, on two golden datasets (tiny.csv and retail_sales.csv) and an eight-row sample. Expected values came from hand-verified constants or Python's statistics module, compared under exact equality or relative tolerances of 1e-9 and 1e-6. All 33 reference assertions passed, a pass rate of 100% (Table 1). This measure concerns output correctness and is distinct from code coverage: the full 49-test suite passed with 92.83% line coverage (Figure 5), which indicates how much code the tests executed, not whether its results were right.

Latency was measured with 30 sequential authenticated requests to the Laravel analysis-status endpoint on the local development stack, after five discarded warm-up requests, using a monotonic timer. Mean latency was 9.07 ms, the median 9.02 ms, and the 95th percentile (nearest-rank method) 9.66 ms; all 30 requests returned HTTP 200, a success rate of 100% (Figure 6).

These findings demonstrate that the calculations covered by reference tests are numerically correct within tolerance and that the status endpoint is responsive and stable under single-user conditions. Limitations include the small reference set, a 30-request sample, a loopback development server rather than production, and the absence of any concurrency or throughput measurement.

---

## Table and figure references to insert

**Table 1**

*Quantitative Performance Evaluation of Data Speak*

Use the table from 05_quantitative_results.md (seven metrics with method, measured result, acceptance threshold "Not predefined", and interpretation). Short form if space is limited:

| Metric | Measured result |
| --- | --- |
| Statistical reference-test pass rate | 100% (33/33 cases) |
| FastAPI tests collected / passed / failed / skipped | 49 / 49 / 0 / 0 |
| FastAPI line coverage | 92.83% (printed as 93%) |
| Mean API latency | 9.07 ms |
| Median API latency | 9.02 ms |
| P95 API latency (nearest rank) | 9.66 ms |
| HTTP success rate | 100% (30/30) |

*Note.* Measured on 13 October 2026 against commit c4a1951 plus working-tree changes; latency measured on the local development stack, not production. No acceptance thresholds were predefined in the project requirements.

**Figure 5**

*Measured Statistical Tests and API Response Times*

Images (genuine Terminal screenshots): B01_statistical_accuracy.png (17 accuracy-related tests passed), B02_pytest_coverage.png (49 passed, 93% coverage), B03_api_latency.png (latency benchmark run 2: 30/30 HTTP 200, mean 9.87 ms, median 9.95 ms, P95 10.63 ms). The figures quoted in the text and Table 1 are from latency run 1 (latency_results.csv); run 2 is documented in 04_api_latency.md section 4.3.

*Note.* The pytest run collected 49 tests on Python 3.9.6 with pytest 8.4.2 and reported 92.83% line coverage with pytest-cov 7.1.0; 33 reference assertions in three accuracy tests passed. Latency was measured with five warm-up and 30 sequential authenticated requests to GET /api/v1/analyses/{id}/status on the local Docker development stack on 13 October 2026; the terminal image shows the second of two runs, and Table 1 reports the first. All images are screenshots of live Terminal sessions whose output is preserved in docs/evidence/unit6/logs.

**Figure 6**

*API Response-Time Distribution*

Image: B04_latency_chart.png.

*Note.* Response times were measured on 13 October 2026 using 30 sequential authenticated requests to the Laravel analysis-status endpoint after five discarded warm-up requests, over a single persistent HTTP/1.1 connection, timed with a monotonic high-resolution clock. The server was the local development stack (Laravel 13 on PHP 8.4 under PHP's built-in server in Docker, with PostgreSQL 17 and Redis 7), reached over loopback without TLS. The horizontal dashed line represents the measured mean latency (9.07 ms).

## Reference (as listed in the current draft)

Wells, J. (2025, April 16). *Top 12 software quality metrics to measure and why*. LinearB. https://linearb.io/blog/software-quality-metrics
