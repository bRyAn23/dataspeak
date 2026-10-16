"""W5b: force the AI_BUSY path on the real model. With a 20 s bound (W5) the narrative never had to give up, because translation
batches are 19-35 s and the background job yields between batches. Here the interactive bound is 3 s (AI_MODEL_LOCK_WAIT=3) with
the busy retry delay 30 s, so a narrative that arrives while a batch is on the model must: wait 3 s, raise AI_BUSY without sending
anything, release the job once (30 s), then succeed on the retry. Runs REPS times. Container handling uses generous stop timeouts."""
import json, re, subprocess, sys, time, csv
from datetime import datetime, timezone
from pathlib import Path
sys.argv = [sys.argv[0]]
exec(Path("/Users/zeyarwint/Documents/MSIT/dataspeak/docs/testing/unit7/final-verification/scripts/def09_concurrency.py").read_text().split("print(f\"DEF-09 concurrency measurements")[0])
rows = json.loads((FV / "evidence/def09_concurrency_runs.json").read_text())
def rec(workload, run, **kw):
    kw.update({"workload": workload, "run": run, "recorded_at": datetime.now().astimezone().isoformat(timespec="seconds")}); rows.append(kw); print(json.dumps(kw, default=str)[:700], flush=True)
    (FV / "evidence/def09_concurrency_runs.json").write_text(json.dumps(rows, indent=2, default=str))
srcs = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", "select public_id from analyses where name like 'Unit7 DEF-09 W1 run%' and status='COMPLETED' order by created_at"]).split()
print("W5b started", datetime.now().isoformat(timespec="seconds"), flush=True)
pool = [analyzed(f"Unit7 DEF-09 W5b run{i+1}") for i in range(REPS)]; idle_model()
try:
    sh(["docker", "compose", "stop", "-t", "130", "backend-worker"], 400)
    sh(["docker", "compose", "run", "-d", "--rm", "--no-deps", "--name", "dataspeak-worker-busy-test", "-e", "AI_MODEL_LOCK_WAIT=3", "-e", "AI_MODEL_BUSY_RETRY_DELAY=30", "backend-worker", "php", "artisan", "queue:work", "redis", "--queue=default", "--tries=2", "--timeout=600", "--sleep=1"], 180); time.sleep(5)
    for i in range(REPS):
        idle_model(); src = srcs[i % len(srcs)]; aid = pool[i]; t0 = utc()
        sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{src}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
        time.sleep(12); tw = time.perf_counter(); A.post(f"/analyses/{aid}/report", headers={"Idempotency-Key": f"d9w5b-{i}-{int(time.time())}"}); s, _ = wait(aid, {"COMPLETED"}); wall = time.perf_counter() - tw
        time.sleep(3); log = sh(["docker", "logs", "--since", t0.strftime("%Y-%m-%dT%H:%M:%SZ"), "dataspeak-worker-busy-test"]); ev = worker_events(t0, utc())
        ev["busy_retry"] += log.count("report.model_busy_retry"); ev["ai_busy"] += log.count("AI_BUSY"); ev["lock_timeout"] += log.count("llm.lock.timeout")
        ev["lock_waited"] += [int(x) for x in re.findall(r'"waited_ms":(\d+)', log)]
        (FV / f"logs/w5b_busy_worker_run{i+1}.log").write_text(log)
        stages = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select j.status, j.attempts, coalesce(j.error_code,'') from analysis_jobs j join analyses a on a.id=j.analysis_id where a.public_id='{aid}' and j.job_type like '%report%' order by j.created_at"]) if sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", "select 1 from information_schema.tables where table_name='analysis_jobs'"]).strip() else ""
        rec("W5b busy path forced (wait 3 s, retry delay 30 s)", i + 1, narrative_wall_s=round(wall, 1), generations=gen_rows(aid), report=report_state(aid), events=ev, job_rows=stages.strip().splitlines(), mem=mem_peak()); idle_model()
finally:
    sh(["docker", "stop", "-t", "30", "dataspeak-worker-busy-test"], 120); sh(["docker", "compose", "start", "backend-worker"], 180)
    print("workers:", sh(["docker", "ps", "--format", "{{.Names}} {{.Status}}"]).replace("\n", "; "), flush=True)
with (FV / "concurrency-performance-results.csv").open("w", newline="", encoding="utf-8") as f:
    w = csv.writer(f); w.writerow(["workload", "run", "narrative_wall_s", "narrative2_wall_s", "translations_wall_s", "generation_status_codes", "generation_duration_ms", "report_status", "report_ai", "claims_total", "claims_supported", "lock_waited_ms", "lock_timeouts", "background_yields", "busy_retries", "ai_timeouts", "ai_busy", "translate_job_durations_s", "container_memory"])
    for r in rows:
        g = r.get("generations"); gl = (g if isinstance(g, list) else (g or {}).get("a1", [])) or []
        rep = r.get("report") or {}; rep1 = rep if "status" in rep else rep.get("a1", {})
        w.writerow([r["workload"], r["run"], r.get("narrative_wall_s", r.get("narrative1_wall_s", "")), r.get("narrative2_wall_s", ""), r.get("translations_wall_s", ""), ";".join(f"{x[0]}:{x[1]}" for x in gl), ";".join(x[2] for x in gl), rep1.get("status", ""), rep1.get("ai", ""), rep1.get("claims_total", ""), rep1.get("claims_supported", ""), ";".join(map(str, r["events"]["lock_waited"])), r["events"]["lock_timeout"], r["events"]["bg_yielded"], r["events"]["busy_retry"], r["events"]["ai_timeout"], r["events"]["ai_busy"], ";".join(map(str, r["events"]["translate_durations"])), r.get("mem", "")])
print("done; rows:", len(rows))
