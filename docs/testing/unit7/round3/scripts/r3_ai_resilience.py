"""Round 3 real-system resilience checks on the LOCAL dev stack (never production).
R3-AI-06 Model timeout: a one-off default-queue worker runs with OLLAMA_TIMEOUT=8 (the normal worker is stopped meanwhile), so the
         narrative request genuinely times out; expected ai FAILED/AI_TIMEOUT, report READY with 0 claims, no retry, lock released,
         then recovery: the normal worker generates a report for another analysis successfully.
R3-AI-07 Queue worker restart: translation jobs are dispatched; while one is in flight both workers are restarted; expected: the
         translations queue drains, no translate flags or model lock remain, no job stuck in the queue/ reserved set, report unchanged.
"""
import json, os, subprocess, time
from datetime import datetime, timezone
from pathlib import Path
import httpx
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); R3 = ROOT / "docs/testing/unit7/round3"; EVD = R3 / "evidence/defect09"; LOGS = R3 / "evidence/logs"
API = "http://localhost:8000/api/v1"; GOLDEN = ROOT / "analytics/tests/golden/retail_sales.csv"; PASSWORD = "Unit7Test#2026x"
SP = Path(os.environ["U7_SCRATCH"]); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]
out = {"started": datetime.now().astimezone().isoformat(timespec="seconds"), "results": {}}
def sh(cmd, timeout=180): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout
def psql(q): return sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", q])
def redis(*a): return sh(["docker", "compose", "exec", "-T", "redis", "redis-cli", *a]).strip()
tok = httpx.post(f"{API}/auth/login", json={"email": emailA, "password": PASSWORD}, headers={"Accept": "application/json"}).json()["data"]["token"]
A = httpx.Client(base_url=API, headers={"Accept": "application/json", "Authorization": f"Bearer {tok}", "X-Dataspeak-Language": "en"}, timeout=300)
def wait(aid, targets, limit=1200):
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
def analyzed(name):
    aid = A.post("/analyses", json={"name": name}).json()["data"]["id"]
    with GOLDEN.open("rb") as f: A.post(f"/analyses/{aid}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
    A.post(f"/analyses/{aid}/validate"); A.post(f"/analyses/{aid}/profile"); wait(aid, {"PROFILED"}); accept_defs(aid)
    A.post(f"/analyses/{aid}/run", headers={"Idempotency-Key": f"r3-{int(time.time()*1000)}"}); wait(aid, {"ANALYZED"}); return aid
def gens(aid): return [l.split("|") for l in psql(f"select g.status, coalesce(g.error_code,''), g.duration_ms, g.attempt_no from ai_generations g join analyses a on a.id=g.analysis_id where a.public_id='{aid}' order by g.created_at").strip().splitlines()]
def report(aid):
    r = A.get(f"/analyses/{aid}/report").json()["data"]; return {"status": r["status"], "ai": (r.get("ai") or {}).get("status"), "claims_total": r["claims_total"], "claims_supported": r["claims_supported"]}
def idle(limit=900):
    t0 = time.time()
    while time.time() - t0 < limit:
        if redis("LLEN", "laravel-database-queues:translations") == "0" and redis("LLEN", "laravel-database-queues:default") == "0" and not redis("--scan", "--pattern", "*llm:model:*"): time.sleep(3); return True
        time.sleep(5)
    return False
lock_pattern = "*llm:model:*"

# R3-AI-06 model timeout (real) and recovery
print("R3-AI-06: preparing analyses", flush=True); T1 = analyzed("Unit7 R3-AI-06 timeout"); T2 = analyzed("Unit7 R3-AI-06 recovery"); idle()
try:
    sh(["docker", "compose", "stop", "-t", "130", "backend-worker"], 400)  # restored in finally even if this step fails
    sh(["docker", "compose", "run", "-d", "--rm", "--no-deps", "--name", "dataspeak-worker-short-timeout", "-e", "OLLAMA_TIMEOUT=8", "backend-worker", "php", "artisan", "queue:work", "redis", "--queue=default", "--tries=1", "--timeout=600", "--sleep=1"], 120); time.sleep(4)
    t0 = time.perf_counter(); A.post(f"/analyses/{T1}/report", headers={"Idempotency-Key": f"r3t-{int(time.time())}"}); s, _ = wait(T1, {"COMPLETED"}); wall = time.perf_counter() - t0
    time.sleep(2); lock_after = redis("--scan", "--pattern", lock_pattern); wlog = sh(["docker", "logs", "dataspeak-worker-short-timeout"]); (LOGS / "r3_ai_06_short_timeout_worker.log").write_text(wlog)
    r = {"analysis": T1, "wall_s": round(wall, 1), "report": report(T1), "generations": gens(T1), "lock_keys_after": lock_after, "busy_retry_logged": "report.model_busy_retry" in wlog}
finally:
    sh(["docker", "stop", "dataspeak-worker-short-timeout"], 60); sh(["docker", "compose", "start", "backend-worker"], 120); time.sleep(4)
# the Ollama server may still be generating the abandoned request; the coordinator released the lock, so the next request waits only for Ollama itself
t0 = time.perf_counter(); A.post(f"/analyses/{T2}/report", headers={"Idempotency-Key": f"r3r-{int(time.time())}"}); s2, _ = wait(T2, {"COMPLETED"}); rec = time.perf_counter() - t0
r.update({"recovery_analysis": T2, "recovery_wall_s": round(rec, 1), "recovery_report": report(T2), "recovery_generations": gens(T2)})
r["pass"] = r["report"]["status"] == "READY" and r["report"]["claims_total"] == 0 and any(g[0] == "FAILED" and g[1] == "AI_TIMEOUT" for g in r["generations"]) and len(r["generations"]) == 1 and not r["lock_keys_after"] and r["recovery_report"]["ai"] == "ready" and r["recovery_report"]["claims_total"] > 0
out["results"]["R3-AI-06"] = r; print("R3-AI-06", json.dumps(r)[:500], flush=True); idle()

# R3-AI-07 queue worker restart while a translation is in flight
src = T2; t_start = datetime.now(timezone.utc)
sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{src}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
time.sleep(20); before = {"queue_len": redis("LLEN", "laravel-database-queues:translations"), "reserved": redis("ZCARD", "laravel-database-queues:translations:reserved"), "lock": redis("--scan", "--pattern", lock_pattern), "flags": redis("--scan", "--pattern", f"*dataspeak:translate:{src}:*").splitlines()}
print("R3-AI-07: restarting both workers while a translation is in flight", before, flush=True)
t_restart = datetime.now(timezone.utc); sh(["docker", "compose", "restart", "backend-worker", "backend-worker-translations"], 180); t0 = time.perf_counter()
drained = idle(1200); drain_s = time.perf_counter() - t0
flags = redis("--scan", "--pattern", f"*dataspeak:translate:{src}:*").splitlines(); lock = redis("--scan", "--pattern", lock_pattern); reserved = redis("ZCARD", "laravel-database-queues:translations:reserved"); delayed = redis("ZCARD", "laravel-database-queues:translations:delayed")
tr = [l.split("|") for l in psql(f"select count(*), count(*) filter (where statement_translations ? 'th'), count(*) filter (where statement_translations ? 'zh'), count(*) filter (where statement_translations ? 'my') from claims c join analyses a on a.id=c.analysis_id where a.public_id='{src}'").strip().splitlines()][0]
log = sh(["docker", "compose", "logs", "--since", t_start.strftime("%Y-%m-%dT%H:%M:%SZ"), "backend-worker-translations"]); (LOGS / "r3_ai_07_translations_worker_restart.log").write_text(log)
ev = {k: log.count(k) for k in ("TranslateReportJob", "DONE", "FAIL", "report.pretranslated", "report.pretranslate_retry", "report.pretranslate_failed", "llm.lock.timeout", "Lock wait", "Broken pipe")}
r = {"analysis": src, "restart_at_utc": t_restart.isoformat(timespec="seconds"), "state_before_restart": before, "drained": drained, "drain_s": round(drain_s, 1), "remaining_flags": flags, "remaining_lock": lock, "reserved_after": reserved, "delayed_after": delayed, "claims_total_th_zh_my": tr, "worker_log_counts": ev, "report_after": report(src)}
r["pass"] = drained and not flags and not lock and reserved == "0" and delayed == "0" and min(int(tr[1]), int(tr[2]), int(tr[3])) == int(tr[0]) and r["report_after"]["status"] == "READY"
out["results"]["R3-AI-07"] = r; print("R3-AI-07", json.dumps(r)[:600], flush=True)
out["finished"] = datetime.now().astimezone().isoformat(timespec="seconds")
(EVD / "r3_ai_resilience.json").write_text(json.dumps(out, indent=2, ensure_ascii=False))
for k, v in out["results"].items(): print(f"[{'PASS' if v['pass'] else 'FAIL'}] {k}")
