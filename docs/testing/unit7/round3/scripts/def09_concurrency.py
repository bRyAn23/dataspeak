"""DEF-09 real-model concurrency and performance measurements on the LOCAL dev stack (Ollama qwen3:8b).
Workloads (each repeated REPS times, bounded for a development laptop):
  W1 narrative only            : POST /report on an ANALYZED analysis with an idle model
  W2 translation only          : pre-translation jobs after a report, no other model work
  W3 narrative during translation: start a report while a translation job is running (the DEF-09 condition)
  W4 multiple pending          : two reports requested back to back while translations are queued
  W5 timeout and recovery      : narrative requested while a translation holds the model, with the interactive wait shortened
                                 so the bounded wait expires (AI_BUSY); verify controlled failure, one delayed retry, then success
Measured per run: narrative wall time, ai_generations status/error_code/duration, lock-wait and yield log events, translation job
durations, timeouts, retries, report completion status, docker stats peak memory. Results -> concurrency-performance-results.csv.
"""
import csv, json, os, re, statistics, subprocess, sys, time
from datetime import datetime, timezone
from pathlib import Path
import httpx
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); FV = ROOT / "docs/testing/unit7/final-verification"; EVD = FV / "evidence"
API = "http://localhost:8000/api/v1"; GOLDEN = ROOT / "analytics/tests/golden/retail_sales.csv"; PASSWORD = "Unit7Test#2026x"
SP = Path(os.environ["U7_SCRATCH"]); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]; REPS = int(os.environ.get("DEF09_REPS", "2"))
rows = []; events = []
def sh(cmd, timeout=120): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout
def utc(): return datetime.now(timezone.utc)
tok = httpx.post(f"{API}/auth/login", json={"email": emailA, "password": PASSWORD}, headers={"Accept": "application/json"}).json()["data"]["token"]
A = httpx.Client(base_url=API, headers={"Accept": "application/json", "Authorization": f"Bearer {tok}", "X-Dataspeak-Language": "en"}, timeout=300)
def wait(aid, targets, limit=1800):
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
    A.post(f"/analyses/{aid}/run", headers={"Idempotency-Key": f"d9-{int(time.time()*1000)}"}); wait(aid, {"ANALYZED"}); return aid
def idle_model(limit=900):
    t0 = time.time()
    while time.time() - t0 < limit:
        q = sh(["docker", "compose", "exec", "-T", "redis", "redis-cli", "LLEN", "laravel-database-queues:translations"]).strip()
        last = sh(["docker", "compose", "logs", "--tail", "1", "backend-worker-translations"])
        if q == "0" and ("DONE" in last or "RUNNING" not in last): return True
        time.sleep(5)
    return False
def gen_rows(aid):
    out = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select g.status, coalesce(g.error_code,''), g.duration_ms, g.attempt_no from ai_generations g join analyses a on a.id=g.analysis_id where a.public_id='{aid}' order by g.created_at"])
    return [l.split("|") for l in out.strip().splitlines() if l.strip()]
def report_state(aid):
    r = A.get(f"/analyses/{aid}/report").json()["data"]; return {"status": r["status"], "ai": (r.get("ai") or {}).get("status"), "claims_total": r["claims_total"], "claims_supported": r["claims_supported"]}
def worker_events(since, until=None):
    args = ["docker", "compose", "logs", "--since", since.strftime("%Y-%m-%dT%H:%M:%SZ")] + (["--until", until.strftime("%Y-%m-%dT%H:%M:%SZ")] if until else []) + ["backend-worker", "backend-worker-translations"]
    log = sh(args); ev = {"lock_waited": [], "lock_timeout": 0, "bg_yielded": 0, "busy_retry": 0, "ai_timeout": 0, "ai_busy": 0, "translate_durations": []}
    for l in log.splitlines():
        if "llm.lock.waited" in l:
            m = re.search(r'"waited_ms":(\d+)', l); ev["lock_waited"].append(int(m.group(1)) if m else 0)
        if "llm.lock.timeout" in l: ev["lock_timeout"] += 1
        if "llm.background.yielded" in l: ev["bg_yielded"] += 1
        if "report.model_busy_retry" in l: ev["busy_retry"] += 1
        if '"code":"AI_TIMEOUT"' in l: ev["ai_timeout"] += 1
        if '"code":"AI_BUSY"' in l: ev["ai_busy"] += 1
        m = re.search(r"TranslateReportJob \.+ ([0-9ms ,]+?) DONE", l)
        if m:
            t = m.group(1); secs = 0
            for num, unit in re.findall(r"(\d+(?:\.\d+)?)\s*(m|s|ms)", t): secs += float(num) * {"m": 60, "s": 1, "ms": 0.001}[unit]
            ev["translate_durations"].append(round(secs, 1))
    return ev
def mem_peak():
    out = sh(["docker", "stats", "--no-stream", "--format", "{{.Name}}\t{{.MemUsage}}"]); return out.strip().replace("\n", "; ")
def rec(workload, run, **kw):
    kw.update({"workload": workload, "run": run, "recorded_at": datetime.now().astimezone().isoformat(timespec="seconds")}); rows.append(kw); print(json.dumps(kw, default=str)[:400], flush=True)
    (FV / "evidence/def09_concurrency_runs.json").write_text(json.dumps(rows, indent=2, default=str))

print(f"DEF-09 concurrency measurements, {REPS} runs per workload, started {datetime.now().isoformat(timespec='seconds')}", flush=True)
# prepare analyses (ANALYZED, no report yet)
pool = {}
for w in ("W1", "W2", "W3", "W4", "W5"):
    pool[w] = [analyzed(f"Unit7 DEF-09 {w} run{i+1}") for i in range(REPS)]
    if w == "W4": pool["W4b"] = [analyzed(f"Unit7 DEF-09 W4b run{i+1}") for i in range(REPS)]
print("analyses prepared", flush=True)
# W1 narrative only
for i in range(REPS):
    idle_model(); aid = pool["W1"][i]; t0 = utc(); tw = time.perf_counter(); A.post(f"/analyses/{aid}/report", headers={"Idempotency-Key": f"d9w1-{i}-{int(time.time())}"}); s, _ = wait(aid, {"COMPLETED"}); wall = time.perf_counter() - tw
    time.sleep(3); ev = worker_events(t0, utc()); rec("W1 narrative only", i + 1, narrative_wall_s=round(wall, 1), generations=gen_rows(aid), report=report_state(aid), events=ev, mem=mem_peak())
    idle_model()  # let the pre-translations of this report finish before the next workload
# W2 translation only: pre-translations dispatched by W1 reports were already measured in W1's wake; measure cleanly by re-queuing translations of a W1 report
for i in range(REPS):
    idle_model(); aid = pool["W1"][i]; t0 = utc()
    # force a fresh translation job set: clear translation flags and dispatch for the other languages via artisan tinker
    sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{aid}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
    tw = time.perf_counter(); idle_model(); wall = time.perf_counter() - tw; ev = worker_events(t0, utc()); rec("W2 translation only", i + 1, translations_wall_s=round(wall, 1), events=ev, mem=mem_peak())
# W3 narrative arriving while a translation is running
for i in range(REPS):
    idle_model(); src = pool["W1"][i]; aid = pool["W3"][i]; t0 = utc()
    sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{src}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
    time.sleep(15)  # translation job now running on the model
    tw = time.perf_counter(); A.post(f"/analyses/{aid}/report", headers={"Idempotency-Key": f"d9w3-{i}-{int(time.time())}"}); s, _ = wait(aid, {"COMPLETED"}); wall = time.perf_counter() - tw
    time.sleep(3); ev = worker_events(t0, utc()); rec("W3 narrative during translation", i + 1, narrative_wall_s=round(wall, 1), generations=gen_rows(aid), report=report_state(aid), events=ev, mem=mem_peak()); idle_model()
# W4 multiple pending: two narratives back to back while translations are queued
for i in range(REPS):
    idle_model(); src = pool["W1"][i]; a1, a2 = pool["W4"][i], pool["W4b"][i]; t0 = utc()
    sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{src}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
    time.sleep(10); tw = time.perf_counter(); A.post(f"/analyses/{a1}/report", headers={"Idempotency-Key": f"d9w4a-{i}-{int(time.time())}"}); A.post(f"/analyses/{a2}/report", headers={"Idempotency-Key": f"d9w4b-{i}-{int(time.time())}"})
    s1, _ = wait(a1, {"COMPLETED"}); w1 = time.perf_counter() - tw; s2, _ = wait(a2, {"COMPLETED"}); w2 = time.perf_counter() - tw
    time.sleep(3); ev = worker_events(t0, utc()); rec("W4 two narratives + translations pending", i + 1, narrative1_wall_s=round(w1, 1), narrative2_wall_s=round(w2, 1), generations={"a1": gen_rows(a1), "a2": gen_rows(a2)}, report={"a1": report_state(a1), "a2": report_state(a2)}, events=ev, mem=mem_peak()); idle_model()
# W5 bounded wait expiry (AI_BUSY) and recovery: shorten the interactive wait on the default worker only, via env override restart
print("W5: restarting default worker with AI_MODEL_LOCK_WAIT=20 and AI_MODEL_BUSY_RETRY_DELAY=30", flush=True)
sh(["docker", "compose", "run", "-d", "--rm", "--no-deps", "--name", "dataspeak-worker-short-wait", "-e", "AI_MODEL_LOCK_WAIT=20", "-e", "AI_MODEL_BUSY_RETRY_DELAY=30", "backend-worker", "php", "artisan", "queue:work", "redis", "--queue=default", "--tries=1", "--timeout=600", "--sleep=1"], 120)
sh(["docker", "compose", "stop", "backend-worker"], 120); time.sleep(5)
try:
    for i in range(REPS):
        idle_model(); src = pool["W1"][i]; aid = pool["W5"][i]; t0 = utc()
        sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{src}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
        time.sleep(15); tw = time.perf_counter(); A.post(f"/analyses/{aid}/report", headers={"Idempotency-Key": f"d9w5-{i}-{int(time.time())}"}); s, _ = wait(aid, {"COMPLETED"}); wall = time.perf_counter() - tw
        time.sleep(3); log = sh(["docker", "logs", "--since", t0.strftime("%Y-%m-%dT%H:%M:%SZ"), "dataspeak-worker-short-wait"]); ev = worker_events(t0, utc())
        ev["busy_retry"] += log.count("report.model_busy_retry"); ev["ai_busy"] += log.count('"code":"AI_BUSY"'); ev["lock_timeout"] += log.count("llm.lock.timeout")
        rec("W5 bounded wait expiry and recovery (wait 20 s)", i + 1, narrative_wall_s=round(wall, 1), generations=gen_rows(aid), report=report_state(aid), events=ev, mem=mem_peak()); idle_model()
finally:
    sh(["docker", "stop", "dataspeak-worker-short-wait"], 60); sh(["docker", "compose", "start", "backend-worker"], 120)
# CSV
with (FV / "concurrency-performance-results.csv").open("w", newline="", encoding="utf-8") as f:
    w = csv.writer(f); w.writerow(["workload", "run", "narrative_wall_s", "narrative2_wall_s", "translations_wall_s", "generation_status_codes", "generation_duration_ms", "report_status", "report_ai", "claims_total", "claims_supported", "lock_waited_ms", "lock_timeouts", "background_yields", "busy_retries", "ai_timeouts", "ai_busy", "translate_job_durations_s", "container_memory"])
    for r in rows:
        g = r.get("generations"); gl = (g if isinstance(g, list) else (g or {}).get("a1", [])) or []
        rep = r.get("report") or {}; rep1 = rep if "status" in rep else rep.get("a1", {})
        w.writerow([r["workload"], r["run"], r.get("narrative_wall_s", r.get("narrative1_wall_s", "")), r.get("narrative2_wall_s", ""), r.get("translations_wall_s", ""), ";".join(f"{x[0]}:{x[1]}" for x in gl), ";".join(x[2] for x in gl), rep1.get("status", ""), rep1.get("ai", ""), rep1.get("claims_total", ""), rep1.get("claims_supported", ""), ";".join(map(str, r["events"]["lock_waited"])), r["events"]["lock_timeout"], r["events"]["bg_yielded"], r["events"]["busy_retry"], r["events"]["ai_timeout"], r["events"]["ai_busy"], ";".join(map(str, r["events"]["translate_durations"])), r.get("mem", "")])
print("done; rows:", len(rows))
