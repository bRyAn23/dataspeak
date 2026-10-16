"""Data Speak Unit 7: performance and stability measurements on the LOCAL development stack.

Measures, with synthetic datasets of 100 / 1,000 / 10,000 rows (evidence/performance/datasets):
  P1  FastAPI direct (loopback, internal token): /v1/validate, /v1/profile, /v1/analyze, 5 runs each per size -> median and p95
  P2  Laravel API latency: GET /analyses/{id}/status, 5 warm-up + 30 sequential requests (same method as Unit 6)
  P3  End-to-end deterministic workflow through Laravel per size, 1 run each: upload+validate, profiling job (includes local
      Ollama column suggestions), run job to ANALYZED; the AI narrative stage is excluded (paid/slow model stage, measured once in SYS-09)
  P4  Container CPU / memory snapshots (docker stats) before and after the large run; HTTP error count across all calls
No production system or paid endpoint is touched. Timings are wall-clock from this Mac; no concurrency (sequential, single client).
"""
from __future__ import annotations

import csv, json, math, os, statistics, subprocess, sys, time
from datetime import datetime
from pathlib import Path

import httpx

ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); U7 = ROOT / "docs/testing/unit7"; R3 = U7 / "round3"; PERF = R3 / "evidence/performance"; DS = U7 / "evidence/performance/datasets"  # Round 3 copy: datasets are read from the Round 1 folder, every output is written under round3/
API = "http://localhost:8000/api/v1"; FAST = "http://127.0.0.1:8001"
SIZES = [("small", "perf_small_100.csv", 100), ("medium", "perf_medium_1000.csv", 1000), ("large", "perf_large_10000.csv", 10000)]
RUNS = 5; PASSWORD = "Unit7Test#2026x"
DEFS = [{"column": "order_date", "business_name": "Order Date", "semantic_type": "DATE"}, {"column": "region", "business_name": "Region", "semantic_type": "CATEGORY"},
        {"column": "product_category", "business_name": "Product Category", "semantic_type": "CATEGORY"}, {"column": "sales_thb", "business_name": "Sales", "semantic_type": "CURRENCY", "unit": "THB", "aggregation": "SUM"},
        {"column": "units", "business_name": "Units", "semantic_type": "NUMBER", "aggregation": "SUM"}, {"column": "discount_pct", "business_name": "Discount", "semantic_type": "PERCENTAGE", "aggregation": "AVERAGE"},
        {"column": "customer_id", "business_name": "Customer", "semantic_type": "IDENTIFIER"}]
errors = []; rows = []
def env_value(key):
    for f in (ROOT / ".env", ROOT / "backend/.env"):
        for line in f.read_text().splitlines():
            if line.startswith(key + "="): return line.split("=", 1)[1].strip().strip('"')
    return ""
def p95(v): s = sorted(v); return s[max(0, math.ceil(0.95 * len(s)) - 1)]
def sh(cmd, timeout=120): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout.strip()
def stats_snapshot(label):
    out = sh(["docker", "stats", "--no-stream", "--format", "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}"])
    (PERF / f"docker_stats_{label}.txt").write_text(f"# docker stats --no-stream at {datetime.now().isoformat(timespec='seconds')}\n{out}\n"); return out
def add(scenario, size, n_rows, metric, values, unit="s", note=""):
    rows.append({"scenario": scenario, "dataset": size, "rows": n_rows, "metric": metric, "runs": len(values), "median": round(statistics.median(values), 3), "p95": round(p95(values), 3),
                 "min": round(min(values), 3), "max": round(max(values), 3), "unit": unit, "note": note, "values": ";".join(f"{v:.3f}" for v in values)})
    print(f"  {scenario:28} {size:7} {metric:26} n={len(values)} median={statistics.median(values):.3f} p95={p95(values):.3f} {unit}", flush=True)

def main():
    started = datetime.now().astimezone(); print(f"performance run started {started.isoformat(timespec='seconds')} commit {sh(['git','rev-parse','--short','HEAD'])}")
    itoken = env_value("ANALYTICS_INTERNAL_TOKEN")
    stats_snapshot("before")
    # ---- P1 FastAPI direct
    with httpx.Client(base_url=FAST, headers={"X-Internal-Token": itoken}, timeout=300) as f:
        for size, name, n in SIZES:
            raw = (DS / name).read_bytes(); meta = {"analysis_id": f"perf-{size}", "definitions": DEFS, "options": {}}
            for ep, metric in (("/v1/validate", "fastapi_validate"), ("/v1/profile", "fastapi_profile"), ("/v1/analyze", "fastapi_analyze")):
                vals = []
                for i in range(RUNS):
                    t0 = time.perf_counter(); r = f.post(ep, files={"file": (name, raw, "text/csv")}, data={"metadata": json.dumps(meta)}); dt = time.perf_counter() - t0
                    if r.status_code != 200: errors.append({"scenario": metric, "size": size, "status": r.status_code, "body": r.text[:200]})
                    vals.append(dt)
                add("P1 FastAPI direct", size, n, metric, vals, note="loopback, 5 sequential runs, same file")
            # record evidence count for context
            r = f.post("/v1/analyze", files={"file": (name, raw, "text/csv")}, data={"metadata": json.dumps(meta)}); add("P1 FastAPI direct", size, n, "evidence_records", [float(r.json()["summary"]["evidence_count"])], unit="count", note="records returned by /v1/analyze")
    # ---- account for Laravel runs
    email = f"unit7-perf-{int(time.time())}@example.com"
    with httpx.Client(base_url=API, headers={"Accept": "application/json"}, timeout=120) as c:
        token = c.post("/auth/register", json={"name": "Unit7 perf", "email": email, "password": PASSWORD, "password_confirmation": PASSWORD}).json()["data"]["token"]
    sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"App\\Models\\User::where('email','{email}')->first()->markEmailAsVerified();"])
    A = httpx.Client(base_url=API, headers={"Accept": "application/json", "Authorization": f"Bearer {token}", "X-Dataspeak-Language": "en"}, timeout=300)
    def wait(aid, targets, limit=1800):
        t0 = time.time()
        while time.time() - t0 < limit:
            s = A.get(f"/analyses/{aid}/status").json()["data"]
            if s["status"] in targets or s["status"] == "FAILED": return s, time.time() - t0
            time.sleep(2)
        return s, time.time() - t0
    # ---- P3 end-to-end per size
    e2e = {}
    for size, name, n in SIZES:
        aid = A.post("/analyses", json={"name": f"Unit7 perf {size}"}).json()["data"]["id"]; t_all = time.perf_counter()
        t0 = time.perf_counter()
        with (DS / name).open("rb") as fh: up = A.post(f"/analyses/{aid}/dataset", files={"file": (name, fh, "text/csv")})
        v = A.post(f"/analyses/{aid}/validate"); t_val = time.perf_counter() - t0
        if up.status_code != 201 or v.status_code != 200: errors.append({"scenario": "e2e upload/validate", "size": size, "status": [up.status_code, v.status_code]})
        t0 = time.perf_counter(); A.post(f"/analyses/{aid}/profile"); s, _ = wait(aid, {"PROFILED"}); t_prof = time.perf_counter() - t0
        prof = A.get(f"/analyses/{aid}/profile").json()["data"]
        defs = []
        for col in prof["columns"]:
            d = col.get("definition") or {}
            defs.append({"column": col.get("name") or col.get("source_name"), "business_name": d.get("business_name") or col.get("name"), "semantic_type": d.get("semantic_type") or "TEXT", "unit": d.get("unit"), "aggregation": d.get("aggregation")})
        A.put(f"/analyses/{aid}/definitions", json={"definitions": defs})
        t0 = time.perf_counter(); rr = A.post(f"/analyses/{aid}/run", headers={"Idempotency-Key": f"perf-{size}-{int(time.time())}"}); s2, _ = wait(aid, {"ANALYZED", "COMPLETED"}); t_run = time.perf_counter() - t0
        t_total = time.perf_counter() - t_all
        if s2["status"] != "ANALYZED": errors.append({"scenario": "e2e run", "size": size, "status": s2["status"], "error": s2.get("last_error")})
        add("P3 end-to-end via Laravel", size, n, "upload_plus_validate", [t_val], note="HTTP round trips incl. FastAPI call")
        add("P3 end-to-end via Laravel", size, n, "profiling_job_to_PROFILED", [t_prof], note="queue job: FastAPI profile + local Ollama column suggestions; polled every 2 s")
        add("P3 end-to-end via Laravel", size, n, "run_job_to_ANALYZED", [t_run], note="queue job: analyze + insights + charts + label translation; polled every 2 s")
        add("P3 end-to-end via Laravel", size, n, "total_upload_to_ANALYZED", [t_total], note="excludes the AI narrative stage")
        e2e[size] = aid
        if size == "large": stats_snapshot("after_large_run")
    # ---- P2 API latency on the large analysis
    aid = e2e["large"]; url = f"/analyses/{aid}/status"
    for _ in range(5): A.get(url)
    lat = []
    for i in range(30):
        t0 = time.perf_counter_ns(); r = A.get(url); lat.append((time.perf_counter_ns() - t0) / 1e6)
        if r.status_code != 200: errors.append({"scenario": "status latency", "status": r.status_code})
    add("P2 Laravel API latency", "large", 10000, "GET_status_latency", lat, unit="ms", note="5 warm-up + 30 sequential, single connection")
    # ---- write outputs
    with (R3 / "performance-results.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0].keys())); w.writeheader(); [w.writerow(r) for r in rows]
    (PERF / "performance_raw.json").write_text(json.dumps({"started": started.isoformat(timespec="seconds"), "finished": datetime.now().astimezone().isoformat(timespec="seconds"), "runs_per_scenario": RUNS, "datasets": [{"name": n, "rows": r, "bytes": (DS / n).stat().st_size} for _, n, r in SIZES], "rows": rows, "errors": errors, "analyses": e2e, "account": email}, indent=2))
    print("errors:", len(errors)); print("done")

if __name__ == "__main__":
    main()
