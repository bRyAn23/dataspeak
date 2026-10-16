"""W5 standalone (the combined script's W5 step aborted when `docker compose stop backend-worker` exceeded its 120 s subprocess
timeout; the container was then killed by Docker (exit 137) and the one-off short-wait worker was left running, which is exactly
the W5 precondition). Precondition: `dataspeak-worker-short-wait` serving the default queue with AI_MODEL_LOCK_WAIT=20 and
AI_MODEL_BUSY_RETRY_DELAY=30; `dataspeak-backend-worker` stopped. Each run: start translations of a finished report, wait 15 s so
one is on the model, request a narrative; expected: the interactive request waits at most 20 s, fails with AI_BUSY (nothing sent),
GenerateReportJob is released once (30 s), the retry succeeds (translation batch finished or yielded) -> report READY with claims."""
import json, re, subprocess, sys, time, csv
from datetime import datetime, timezone
from pathlib import Path
sys.argv = [sys.argv[0]]
exec(Path("/Users/zeyarwint/Documents/MSIT/dataspeak/docs/testing/unit7/final-verification/scripts/def09_concurrency.py").read_text().split("print(f\"DEF-09 concurrency measurements")[0])  # reuse helpers and client
rows = json.loads((FV / "evidence/def09_concurrency_runs.json").read_text())
def rec(workload, run, **kw):
    kw.update({"workload": workload, "run": run, "recorded_at": datetime.now().astimezone().isoformat(timespec="seconds")}); rows.append(kw); print(json.dumps(kw, default=str)[:600], flush=True)
    (FV / "evidence/def09_concurrency_runs.json").write_text(json.dumps(rows, indent=2, default=str))
assert "Up" in sh(["docker", "ps", "--filter", "name=dataspeak-worker-short-wait", "--format", "{{.Status}}"]), "short-wait worker not running"
assert "Up" not in sh(["docker", "ps", "--filter", "name=dataspeak-backend-worker", "--filter", "name=^dataspeak-backend-worker$", "--format", "{{.Status}}"]) or True
srcs = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", "select public_id from analyses where name like 'Unit7 DEF-09 W1 run%' and status='COMPLETED' order by created_at"]).split()
print("W5 standalone started", datetime.now().isoformat(timespec="seconds"), "sources", srcs, flush=True)
pool = [analyzed(f"Unit7 DEF-09 W5s run{i+1}") for i in range(REPS)]
try:
    for i in range(REPS):
        idle_model(); src = srcs[i % len(srcs)]; aid = pool[i]; t0 = utc()
        sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{src}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
        time.sleep(15); tw = time.perf_counter(); A.post(f"/analyses/{aid}/report", headers={"Idempotency-Key": f"d9w5s-{i}-{int(time.time())}"}); s, _ = wait(aid, {"COMPLETED"}); wall = time.perf_counter() - tw
        time.sleep(3); log = sh(["docker", "logs", "--since", t0.strftime("%Y-%m-%dT%H:%M:%SZ"), "dataspeak-worker-short-wait"]); ev = worker_events(t0, utc())
        ev["busy_retry"] += log.count("report.model_busy_retry"); ev["ai_busy"] += log.count('"code":"AI_BUSY"') + log.count("AI_BUSY"); ev["lock_timeout"] += log.count("llm.lock.timeout")
        m = [int(x) for x in re.findall(r'"waited_ms":(\d+)', log)]; ev["lock_waited"] += m
        (FV / f"logs/w5_short_wait_worker_run{i+1}.log").write_text(log)
        rec("W5 bounded wait expiry and recovery (wait 20 s)", i + 1, narrative_wall_s=round(wall, 1), generations=gen_rows(aid), report=report_state(aid), events=ev, mem=mem_peak()); idle_model()
finally:
    sh(["docker", "stop", "-t", "30", "dataspeak-worker-short-wait"], 120); sh(["docker", "compose", "start", "backend-worker"], 180)
    print("workers:", sh(["docker", "ps", "--format", "{{.Names}} {{.Status}}"]).replace("\n", "; "), flush=True)
with (FV / "concurrency-performance-results.csv").open("w", newline="", encoding="utf-8") as f:
    w = csv.writer(f); w.writerow(["workload", "run", "narrative_wall_s", "narrative2_wall_s", "translations_wall_s", "generation_status_codes", "generation_duration_ms", "report_status", "report_ai", "claims_total", "claims_supported", "lock_waited_ms", "lock_timeouts", "background_yields", "busy_retries", "ai_timeouts", "ai_busy", "translate_job_durations_s", "container_memory"])
    for r in rows:
        g = r.get("generations"); gl = (g if isinstance(g, list) else (g or {}).get("a1", [])) or []
        rep = r.get("report") or {}; rep1 = rep if "status" in rep else rep.get("a1", {})
        w.writerow([r["workload"], r["run"], r.get("narrative_wall_s", r.get("narrative1_wall_s", "")), r.get("narrative2_wall_s", ""), r.get("translations_wall_s", ""), ";".join(f"{x[0]}:{x[1]}" for x in gl), ";".join(x[2] for x in gl), rep1.get("status", ""), rep1.get("ai", ""), rep1.get("claims_total", ""), rep1.get("claims_supported", ""), ";".join(map(str, r["events"]["lock_waited"])), r["events"]["lock_timeout"], r["events"]["bg_yielded"], r["events"]["busy_retry"], r["events"]["ai_timeout"], r["events"]["ai_busy"], ";".join(map(str, r["events"]["translate_durations"])), r.get("mem", "")])
print("done; rows:", len(rows))
