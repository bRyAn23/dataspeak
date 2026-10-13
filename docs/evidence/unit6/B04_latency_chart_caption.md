# Figure 9 caption and interpretation (for B04_latency_chart.png)

Source data: latency_results.csv (30 measured requests, all HTTP 200). Chart: 2250 x 1260 px, 300 DPI, 7.5 x 4.2 in. Mean line at 9.071 ms; labelled minimum 8.594 ms (request 19) and maximum 9.799 ms (request 30).

**Figure 9**

*API Response Latency Across 30 Sequential Requests*

*Note.* Response times were measured on 13 October 2026 using 30 sequential authenticated requests to the Laravel analysis-status endpoint (GET /api/v1/analyses/{id}/status) after five discarded warm-up requests, over a single persistent HTTP/1.1 connection, timed with a monotonic high-resolution clock. The server was the local development stack (Laravel 13 on PHP 8.4 under PHP's built-in server in Docker, with PostgreSQL 17 and Redis 7), reached over loopback without TLS. The horizontal dashed line represents the measured mean latency (9.07 ms). Raw data are in latency_results.csv.

**Interpretation**

Response times ranged from 8.59 ms (request 19) to 9.80 ms (request 30), with a mean of 9.07 ms and a standard deviation of 0.33 ms, so every observation lies within 0.73 ms of the mean. Requests 22 to 30 sit consistently above the mean and request 30 is the highest value, although none exceeds two standard deviations; the measurement does not identify a cause for this late upward drift. The values reflect sequential, single-user requests to a loopback development server and do not predict production or concurrent performance.
