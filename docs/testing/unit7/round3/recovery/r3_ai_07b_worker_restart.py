"""R3-AI-07b queue worker restart with a translation job genuinely in flight (recovery re-run).
The first run restarted the workers 20 s after dispatch, by which time the three small translation jobs (2 in-report claims each)
had already finished, so the precondition "job in flight" was not met. This run uses a report with ~20 in-report claims (translation
jobs of 20-70 s), confirms a job is reserved and the model lock is held, restarts BOTH workers (pcntl now installed), measures the
restart duration, and checks: queue drains, in-flight job finished (DONE) or was re-queued and finished, no flags/lock/reserved/delayed
entries remain, every in-report claim translated, report unchanged."""
import json, os, subprocess, time
from datetime import datetime, timezone
from pathlib import Path
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); OUT = ROOT / "docs/testing/unit7/round3/recovery/evidence/defect09"; OUT.mkdir(parents=True, exist_ok=True)
def sh(cmd, timeout=400): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout
def psql(q): return sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", q])
def redis(*a): return sh(["docker", "compose", "exec", "-T", "redis", "redis-cli", *a]).strip()
src = os.environ.get("R3_SRC", "7f6f8434-d02b-4157-b6f0-3398dd74557f")  # W1 run1 report, ~18 in-report claims
def idle(limit=1200):
    t0 = time.time()
    while time.time() - t0 < limit:
        if redis("LLEN", "laravel-database-queues:translations") == "0" and redis("ZCARD", "laravel-database-queues:translations:reserved") == "0" and redis("ZCARD", "laravel-database-queues:translations:delayed") == "0" and not redis("--scan", "--pattern", "*llm:model:*"): time.sleep(3); return True
        time.sleep(4)
    return False
idle(); t_start = datetime.now(timezone.utc)
sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"$a=App\\Models\\Analysis::where('public_id','{src}')->first(); App\\Models\\Claim::where('analysis_id',$a->id)->update(['statement_translations'=>null]); App\\Jobs\\TranslateReportJob::dispatchForOtherLanguages($a,'en'); echo 'dispatched';"], 60)
# wait until a translation job is actually reserved and the model lock is held
inflight = None
for _ in range(40):
    time.sleep(1); st = {"queue_len": redis("LLEN", "laravel-database-queues:translations"), "reserved": redis("ZCARD", "laravel-database-queues:translations:reserved"), "lock": redis("--scan", "--pattern", "*llm:model:*")}
    if st["reserved"] != "0" and st["lock"]: inflight = st; break
print("state before restart:", inflight or st, flush=True)
t_restart = datetime.now(timezone.utc); t0 = time.perf_counter(); sh(["docker", "compose", "restart", "backend-worker", "backend-worker-translations"], 400); restart_s = time.perf_counter() - t0
after = {"queue_len": redis("LLEN", "laravel-database-queues:translations"), "reserved": redis("ZCARD", "laravel-database-queues:translations:reserved"), "delayed": redis("ZCARD", "laravel-database-queues:translations:delayed"), "lock": redis("--scan", "--pattern", "*llm:model:*")}
t1 = time.perf_counter(); drained = idle(1200); drain_s = time.perf_counter() - t1
flags = redis("--scan", "--pattern", f"*dataspeak:translate:{src}:*").splitlines(); lock = redis("--scan", "--pattern", "*llm:model:*")
tr = psql(f"select count(*) filter (where c.in_report), count(*) filter (where c.in_report and statement_translations ? 'th'), count(*) filter (where c.in_report and statement_translations ? 'zh'), count(*) filter (where c.in_report and statement_translations ? 'my') from claims c join analyses a on a.id=c.analysis_id where a.public_id='{src}'").strip().split("|")
log = sh(["docker", "compose", "logs", "--since", t_start.strftime("%Y-%m-%dT%H:%M:%SZ"), "backend-worker-translations"]); (OUT / "r3_ai_07b_translations_worker.log").write_text(log)
dlog = sh(["docker", "compose", "logs", "--since", t_start.strftime("%Y-%m-%dT%H:%M:%SZ"), "backend-worker"]); (OUT / "r3_ai_07b_default_worker.log").write_text(dlog)
counts = {k: log.count(k) for k in ("TranslateReportJob", " DONE", " FAIL", "report.pretranslated", "report.pretranslate_retry", "report.pretranslate_failed", "llm.lock.timeout", "has been attempted too many times", "MaxAttemptsExceeded")}
failed_jobs = psql("select count(*) from failed_jobs where failed_at > now() - interval '30 minutes'").strip()
r = {"executed": datetime.now().astimezone().isoformat(timespec="seconds"), "analysis": src, "in_flight_before_restart": inflight, "restart_at_utc": t_restart.isoformat(timespec="seconds"), "restart_wall_s": round(restart_s, 1), "state_right_after_restart": after, "drained": drained, "drain_s": round(drain_s, 1), "remaining_flags": flags, "remaining_lock": lock, "in_report_claims_total_th_zh_my": tr, "worker_log_counts": counts, "failed_jobs_last_30min": failed_jobs}
r["pass"] = bool(inflight) and drained and not flags and not lock and len(set(tr)) == 1 and counts[" FAIL"] == 0 and failed_jobs == "0"
(OUT / "r3_ai_07b_result.json").write_text(json.dumps(r, indent=2)); print(json.dumps(r)[:1200]); print("[PASS]" if r["pass"] else "[FAIL]", "R3-AI-07b")
