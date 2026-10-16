"""RT-04b: DEF-04 re-verification after the translations worker was repaired.
Generate a report (queues translation jobs for the other languages), then immediately profile another dataset and
time it; confirm from both worker logs which container processed which job."""
import json, os, re, subprocess, sys, time
from datetime import datetime
from pathlib import Path
import httpx
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); RT = ROOT / "docs/testing/unit7/retesting"; EVD = RT / "evidence"
API = "http://localhost:8000/api/v1"; GOLDEN = ROOT / "analytics/tests/golden/retail_sales.csv"; PASSWORD = "Unit7Test#2026x"
SP = Path(os.environ["U7_SCRATCH"]); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]
def sh(cmd, timeout=120): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout
tok = httpx.post(f"{API}/auth/login", json={"email": emailA, "password": PASSWORD}, headers={"Accept": "application/json"}).json()["data"]["token"]
A = httpx.Client(base_url=API, headers={"Accept": "application/json", "Authorization": f"Bearer {tok}", "X-Dataspeak-Language": "en"}, timeout=300)
def wait(aid, targets, limit=1500):
    t0 = time.time()
    while time.time() - t0 < limit:
        s = A.get(f"/analyses/{aid}/status").json()["data"]
        if s["status"] in targets or s["status"] == "FAILED": return s, time.time() - t0
        time.sleep(2)
    return s, time.time() - t0
def accept_defs(aid):
    prof = A.get(f"/analyses/{aid}/profile").json()["data"]; defs = []
    for col in prof["columns"]:
        d = col.get("definition") or {}
        defs.append({"column": col.get("name") or col.get("source_name"), "business_name": d.get("business_name") or col.get("name"), "semantic_type": d.get("semantic_type") or "TEXT", "unit": d.get("unit"), "aggregation": d.get("aggregation")})
    A.put(f"/analyses/{aid}/definitions", json={"definitions": defs})
def new_analysis(name):
    aid = A.post("/analyses", json={"name": name}).json()["data"]["id"]
    with GOLDEN.open("rb") as f: A.post(f"/analyses/{aid}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
    A.post(f"/analyses/{aid}/validate"); return aid
X = new_analysis("Unit7 RT-04b X (report)"); A.post(f"/analyses/{X}/profile"); wait(X, {"PROFILED"}); accept_defs(X)
A.post(f"/analyses/{X}/run", headers={"Idempotency-Key": f"rt4b-x-{int(time.time())}"}); wait(X, {"ANALYZED"})
t_start = datetime.utcnow(); A.post(f"/analyses/{X}/report", headers={"Idempotency-Key": f"rt4b-xr-{int(time.time())}"}); s, rep_s = wait(X, {"COMPLETED"}); print(f"report {s['status']} in {rep_s:.0f}s", flush=True)
Y = new_analysis("Unit7 RT-04b Y (contention)"); t0 = time.perf_counter(); A.post(f"/analyses/{Y}/profile"); sy, _ = wait(Y, {"PROFILED"}); prof_s = time.perf_counter() - t0
# give translations time to show in logs, then collect both worker logs for the window
time.sleep(20)
wl = sh(["docker", "compose", "logs", "--since", t_start.strftime("%Y-%m-%dT%H:%M:%SZ"), "backend-worker", "backend-worker-translations"])
lines = [l.strip() for l in wl.splitlines() if re.search(r"(RUNNING|DONE|FAIL)", l)]
(EVD / "rt04b_worker_logs.log").write_text("\n".join(lines))
on = lambda job: sorted({l.split("|")[0].strip() for l in lines if job in l})
qlen = sh(["docker", "compose", "exec", "-T", "redis", "redis-cli", "LLEN", "laravel-database-queues:translations"]).strip()
res = {"defect": "DEF-04", "report_generation_s": round(rep_s, 1), "profile_job_wall_seconds_during_translations": round(prof_s, 1), "profile_status": sy["status"], "translate_jobs_seen_on": on("TranslateReportJob"), "profile_job_seen_on": on("ProfileAnalysisJob"), "translations_queue_length_after": qlen, "baseline_before_fix_seconds": 73.3, "analyses": {"X": X, "Y": Y}}
res["pass"] = sy["status"] == "PROFILED" and prof_s < 40 and any("translations" in c for c in res["translate_jobs_seen_on"]) and all("translations" not in c for c in res["profile_job_seen_on"])
d = json.loads((EVD / "post_fix_retests.json").read_text()); d["results"]["RT-04b"] = res; (EVD / "post_fix_retests.json").write_text(json.dumps(d, indent=2, ensure_ascii=False))
print(json.dumps(res, indent=1)); print("[PASS]" if res["pass"] else "[FAIL]", "RT-04b")
