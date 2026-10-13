# Unit 6 Evidence 04: Laravel API Latency (RUN-API-002)

Data Speak, UoPeople MSIT 5910. Measured on 2026-10-13 at 22:37 (Asia/Bangkok) against the **local development stack**. Code: `main` at `c4a1951` plus the uncommitted working tree described in [01_test_environment.md](01_test_environment.md).

## 1. Endpoint and scope

| Item | Value |
| --- | --- |
| Endpoint | `GET /api/v1/analyses/{id}/status` (RUN-API-002), authenticated, read-only |
| Route confirmation | `backend/routes/api.php` line 64: `Route::get('/status', [RunController::class, 'status'])`, inside the `auth:sanctum` and owner-scoped `/analyses/{analysis}` group |
| Handler | `RunController::status` (`backend/app/Http/Controllers/Api/V1/RunController.php` line 65): authorises the owner, reaps stale jobs, reads job progress from Redis and the analysis row from PostgreSQL, returns status, stage, progress, job and `poll_seconds` |
| Base URL tested | `http://localhost:8000/api/v1` (local Docker Compose stack) |
| Production | **Not tested.** `https://api.dataspeaknow.com/api/v1` was not measured because authenticated access to production for this purpose was not confirmed, and creating a test account or analysis there would modify production data. The script below runs unchanged against production once a dedicated account is approved. |
| Test account | Dedicated local account `latency-test-<timestamp>@example.com`, created for this run, marked verified locally; token kept in a mode-600 scratch file and read by the script from an environment variable. The token appears in no log, CSV or image. |
| Test analysis | One analysis in status `VALIDATED` holding the 240-row synthetic golden dataset `retail_sales.csv` (no customer data). Analysis id `c74c7c9d-...` |

## 2. Method

1. Five warm-up requests were sent and discarded, so that connection set-up, PHP opcode caching and framework bootstrapping do not bias the sample.
2. Thirty measured requests were sent strictly one after another on one persistent HTTP/1.1 connection (`httpx.Client`). No concurrency was used.
3. Each duration was measured with `time.perf_counter_ns()`, a monotonic high-resolution clock, from just before the request was issued to just after the full response was received, and converted to milliseconds with three decimals.
4. A request counted as successful when the HTTP status was 200. Transport errors would have been recorded as status 0 with the exception name.
5. Statistics: arithmetic mean, median, minimum, maximum, sample standard deviation, success rate, and the 95th percentile by the **nearest-rank method**: with n = 30 sorted durations, P95 is the value at rank ceil(0.95 × 30) = 29. The linear-interpolation estimate (the numpy default) is also reported for comparison; the two differ by less than 0.1 ms here.

Script: [scripts/api_latency.py](scripts/api_latency.py). Command (token and analysis id supplied through environment variables):

```bash
cd analytics
DATASPEAK_TOKEN_FILE=<protected file> DATASPEAK_ANALYSIS_ID=<uuid> \
  .venv/bin/python ../docs/evidence/unit6/scripts/api_latency.py \
  --base-url http://localhost:8000/api/v1 --out ../docs/evidence/unit6/latency_results.csv
```

## 3. Environment and network conditions

| Item | Value |
| --- | --- |
| Client | Python 3.9.6, httpx 0.28.1, macOS 26.6.2, Apple M5 Pro (18 cores), 64 GiB RAM |
| Server | Laravel 13.34.0 on PHP 8.4.26 inside the `backend` Docker container, served by PHP's built-in development server (`php -S 0.0.0.0:8000 .../server.php`), single-process, with OPcache not enabled. Production uses FrankenPHP behind nginx, which is a different server with different characteristics. |
| Data stores | PostgreSQL 17.11 and Redis 7.4.11 containers on the same Docker network |
| Network path | Loopback: client on the host to Docker Desktop's published port 8000, then to the container. No internet hop, no TLS, no reverse proxy. |
| Other load | The full development stack was running (frontend, backend, backend-worker, analytics, mailpit, postgres, redis); no other analysis jobs were in progress. |
| Date and time | 2026-10-13 22:37:27 local time (Asia/Bangkok, UTC+07:00), from the log header |
| Host load | 1-minute load average 2.03 on 18 cores just after the run (`uptime`) |
| Docker Desktop VM | 18 CPUs, 7.7 GiB memory allocated to the Linux VM that runs all containers |

## 4. Results

### 4.1 Summary of the 30 measured requests

| Statistic | Value |
| --- | --- |
| HTTP success rate | 100.0% (30/30 responses HTTP 200) |
| Mean latency | 9.071 ms |
| Median latency | 9.020 ms |
| Minimum | 8.594 ms |
| Maximum | 9.799 ms |
| 95th percentile, nearest-rank (rank 29 of 30) | 9.656 ms |
| 95th percentile, linear interpolation (comparison) | 9.580 ms |
| Sample standard deviation | 0.329 ms |

### 4.2 Per-request results

Raw data: [latency_results.csv](latency_results.csv) (columns: request, http_status, duration_ms, success, error). Terminal output of this run: [logs/04_api_latency.log](logs/04_api_latency.log). A genuine Terminal screenshot of a second, later run of the same benchmark is [B03_api_latency.png](B03_api_latency.png); see section 4.3.

| # | HTTP | Duration (ms) | Success |
| --- | --- | --- | --- |
| 1 | 200 | 9.193 | yes |
| 2 | 200 | 8.896 | yes |
| 3 | 200 | 8.706 | yes |
| 4 | 200 | 9.129 | yes |
| 5 | 200 | 8.807 | yes |
| 6 | 200 | 8.677 | yes |
| 7 | 200 | 8.707 | yes |
| 8 | 200 | 8.974 | yes |
| 9 | 200 | 8.706 | yes |
| 10 | 200 | 9.460 | yes |
| 11 | 200 | 8.890 | yes |
| 12 | 200 | 9.227 | yes |
| 13 | 200 | 8.748 | yes |
| 14 | 200 | 9.066 | yes |
| 15 | 200 | 8.653 | yes |
| 16 | 200 | 8.910 | yes |
| 17 | 200 | 8.763 | yes |
| 18 | 200 | 8.934 | yes |
| 19 | 200 | 8.594 | yes |
| 20 | 200 | 9.394 | yes |
| 21 | 200 | 8.832 | yes |
| 22 | 200 | 9.340 | yes |
| 23 | 200 | 9.209 | yes |
| 24 | 200 | 9.488 | yes |
| 25 | 200 | 9.357 | yes |
| 26 | 200 | 9.656 | yes |
| 27 | 200 | 9.309 | yes |
| 28 | 200 | 9.300 | yes |
| 29 | 200 | 9.406 | yes |
| 30 | 200 | 9.799 | yes |

### 4.3 Replication run (run 2) with terminal screenshot

The benchmark was executed a second time at 23:51:52 on the same date, in a visible macOS Terminal window so that a genuine screenshot could be taken ([B03_api_latency.png](B03_api_latency.png)). Its own files are [latency_results_run2.csv](latency_results_run2.csv), [logs/04_api_latency_run2_terminal_capture.log](logs/04_api_latency_run2_terminal_capture.log) and the chart [B04b_latency_chart_run2.png](B04b_latency_chart_run2.png). Run 1 remains the dataset reported in sections 4.1, 4.2 and 5.

| Statistic | Run 1 (22:37, reported above) | Run 2 (23:51, screenshot) |
| --- | --- | --- |
| Success rate | 100.0% (30/30) | 100.0% (30/30) |
| Mean | 9.071 ms | 9.874 ms |
| Median | 9.020 ms | 9.945 ms |
| Min / max | 8.594 / 9.799 ms | 8.840 / 10.631 ms |
| P95 nearest-rank | 9.656 ms | 10.629 ms |
| Sample SD | 0.329 ms | 0.498 ms |

Conditions differed between the runs: by run 2 the test analysis had been taken through profiling, analysis and report generation (so the status handler reads more stage rows), and a local Ollama model server and a headless Chromium instance used for evidence capture were running on the same host. The measurement does not attribute the 0.8 ms difference in mean latency to any one of these factors.

## 5. Methods and results paragraph (APA style)

Latency of the analysis-status endpoint (GET /api/v1/analyses/{id}/status) was measured on the local development deployment of Data Speak on 13 October 2026. A dedicated test account and one validated analysis containing a 240-row synthetic dataset were prepared. After five discarded warm-up requests, 30 authenticated requests were issued sequentially over a single persistent HTTP/1.1 connection from a Python 3.9 client using httpx, and each round-trip time was recorded with the monotonic `perf_counter_ns` clock. The server was Laravel 13 on PHP 8.4 running under PHP's built-in development server in Docker on an Apple M5 Pro host, with PostgreSQL 17 and Redis 7 in adjacent containers; requests travelled over loopback without TLS or a reverse proxy. All 30 requests returned HTTP 200 (success rate 100%). Latency had a mean of 9.07 ms (SD = 0.33), a median of 9.02 ms, a minimum of 8.59 ms, a maximum of 9.80 ms, and a 95th percentile of 9.66 ms by the nearest-rank method (rank 29 of 30). The narrow spread (range 1.21 ms) indicates stable single-request response time for this read-only endpoint under no concurrent load. These figures characterise sequential latency on a loopback development environment only; they do not estimate production latency, which adds Internet transit, TLS termination at nginx, a different application server (FrankenPHP) and a smaller instance, nor do they measure throughput or behaviour under concurrent users, which were deliberately out of scope.

## 6. Limitations

- **Local, not production.** No production measurement was taken (section 1). Production numbers will be higher because of network distance, TLS, the reverse proxy and the smaller EC2 instance, and should be measured separately with an approved account.
- **Development web server.** PHP's built-in server handles one request at a time and skips the FrankenPHP worker mode used in production; absolute values are not transferable.
- **Sequential only.** Thirty sequential requests measure single-request latency. They say nothing about concurrent throughput, queueing or saturation, and this report makes no such claim.
- **Small sample.** With n = 30 the 95th percentile rests on a single observation (rank 29); it is reported as the method requires but should not be over-interpreted.
- **Loopback through Docker Desktop.** The host-to-container port mapping adds a small, constant overhead that is included in every measurement.
- **One endpoint, one analysis state.** The status endpoint for a `VALIDATED` analysis does a bounded amount of work (one analysis row, one Redis read, stage reaping). Latency for other endpoints or for analyses with many stage rows was not measured.
