"""Sequential latency measurement for GET /api/v1/analyses/{id}/status (RUN-API-002).

Usage:
    DATASPEAK_TOKEN_FILE=/path/to/token DATASPEAK_ANALYSIS_ID=<uuid> \
    python api_latency.py --base-url http://localhost:8000/api/v1 --out latency_results.csv

The token is read from a file (or DATASPEAK_TOKEN) and never printed. Timing uses
time.perf_counter_ns(), a monotonic high-resolution clock, around a single blocking request on a
persistent HTTP connection. Five warm-up requests precede thirty measured, strictly sequential
requests. No concurrency is used; this measures single-request latency, not throughput.
"""

from __future__ import annotations

import argparse
import csv
import os
import platform
import statistics
import sys
import time
from datetime import datetime
from pathlib import Path

import httpx

WARMUP = 5
MEASURED = 30


def percentile_nearest_rank(sorted_values: list[float], p: float) -> float:
    """Nearest-rank method: the value at rank ceil(p * n), 1-based, of the sorted sample."""
    import math

    n = len(sorted_values)
    rank = max(1, math.ceil(p * n))
    return sorted_values[rank - 1]


def percentile_linear(sorted_values: list[float], p: float) -> float:
    """Linear interpolation between closest ranks (the numpy/Excel 'inclusive' default)."""
    n = len(sorted_values)
    pos = p * (n - 1)
    lo = int(pos)
    hi = min(lo + 1, n - 1)
    return sorted_values[lo] + (sorted_values[hi] - sorted_values[lo]) * (pos - lo)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base-url", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--language", default="en")
    args = ap.parse_args()

    token = os.environ.get("DATASPEAK_TOKEN")
    token_file = os.environ.get("DATASPEAK_TOKEN_FILE")
    if not token and token_file:
        token = Path(token_file).read_text(encoding="utf-8").strip()
    analysis_id = os.environ.get("DATASPEAK_ANALYSIS_ID")
    if not token or not analysis_id:
        print("DATASPEAK_TOKEN (or DATASPEAK_TOKEN_FILE) and DATASPEAK_ANALYSIS_ID are required", file=sys.stderr)
        return 2

    url = f"{args.base_url.rstrip('/')}/analyses/{analysis_id}/status"
    headers = {"Accept": "application/json", "Authorization": f"Bearer {token}", "X-Dataspeak-Language": args.language}
    started = datetime.now().astimezone()
    print(f"Endpoint : GET {args.base_url.rstrip('/')}/analyses/{analysis_id[:8]}.../status")
    print(f"Started  : {started.isoformat(timespec='seconds')}")
    print(f"Client   : Python {platform.python_version()}, httpx {httpx.__version__}, {platform.system()} {platform.machine()}")
    print(f"Timer    : time.perf_counter_ns (monotonic); {WARMUP} warm-up + {MEASURED} measured sequential requests, one connection")
    print()

    rows: list[dict] = []
    with httpx.Client(timeout=30.0, http2=False) as client:
        for i in range(WARMUP):
            r = client.get(url, headers=headers)
            print(f"warm-up {i + 1}/{WARMUP}: HTTP {r.status_code}")
        print()
        print(f"{'#':>3}  {'HTTP':>4}  {'ms':>9}  ok")
        for i in range(1, MEASURED + 1):
            t0 = time.perf_counter_ns()
            try:
                r = client.get(url, headers=headers)
                t1 = time.perf_counter_ns()
                status, ok, err = r.status_code, r.status_code == 200, ""
            except httpx.HTTPError as exc:  # transport failure counts as a failed request
                t1 = time.perf_counter_ns()
                status, ok, err = 0, False, type(exc).__name__
            ms = (t1 - t0) / 1_000_000
            rows.append({"request": i, "http_status": status, "duration_ms": round(ms, 3), "success": ok, "error": err})
            print(f"{i:>3}  {status:>4}  {ms:>9.3f}  {'yes' if ok else 'NO ' + err}")

    durations = sorted(r["duration_ms"] for r in rows)
    successes = sum(1 for r in rows if r["success"])
    summary = {
        "requests": len(rows),
        "success_rate_pct": round(100 * successes / len(rows), 2),
        "mean_ms": round(statistics.fmean(durations), 3),
        "median_ms": round(statistics.median(durations), 3),
        "min_ms": durations[0],
        "max_ms": durations[-1],
        "p95_nearest_rank_ms": percentile_nearest_rank(durations, 0.95),
        "p95_linear_ms": round(percentile_linear(durations, 0.95), 3),
        "stdev_ms": round(statistics.stdev(durations), 3),
    }
    print()
    print("Summary (30 measured requests)")
    print(f"  success rate      : {summary['success_rate_pct']}%  ({successes}/{len(rows)} HTTP 200)")
    print(f"  mean              : {summary['mean_ms']} ms")
    print(f"  median            : {summary['median_ms']} ms")
    print(f"  min / max         : {summary['min_ms']} / {summary['max_ms']} ms")
    print(f"  p95 nearest-rank  : {summary['p95_nearest_rank_ms']} ms  (rank ceil(0.95*30)=29 of 30 sorted)")
    print(f"  p95 linear interp : {summary['p95_linear_ms']} ms  (numpy default, for comparison)")
    print(f"  sample stdev      : {summary['stdev_ms']} ms")

    out = Path(args.out)
    with out.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["request", "http_status", "duration_ms", "success", "error"])
        w.writeheader()
        for r in rows:
            w.writerow(r)
    print(f"\nRaw results written to {out}")
    return 0 if successes == len(rows) else 1


if __name__ == "__main__":
    raise SystemExit(main())
