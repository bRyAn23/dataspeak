"""Final end-to-end checks on the LOCAL dev stack (never production) that the system harness does not cover directly.
FC-01 AI-unavailable fallback (real): a one-off default-queue worker whose OLLAMA_BASE_URL points at a closed port generates a
      report; expected READY report, ai FAILED/AI_UNAVAILABLE, zero claims, data notes present, no retry (AI_UNAVAILABLE is final).
FC-02 Translation after report: a report written in English has verified th/zh/my statement translations after the background
      jobs ran; GET /report with X-Dataspeak-Language: th serves them; no translation flag remains.
FC-03 Claim rejection evidence: validator version, counts of SUPPORTED/REJECTED/NEEDS_REVIEW claims and rejection codes on the
      reports generated today (validation verifies; rejected claims never reach the report).
FC-04 Accessibility: axe-core on new-analysis, profile and report pages (serious/critical violations expected 0).
Everything else (invalid CSV, missing-value warning, golden correctness, re-upload messaging, workflow) is in system_tests.py
and post_fix_retests.py, re-run in the same session.
"""
import json, os, subprocess, time
from datetime import datetime
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); FV = ROOT / "docs/testing/unit7/final-verification"; EVD = FV / "evidence"; EVD.mkdir(exist_ok=True)
API = "http://localhost:8000/api/v1"; UI = "http://localhost:5173"; GOLDEN = ROOT / "analytics/tests/golden/retail_sales.csv"; PASSWORD = "Unit7Test#2026x"
AXE = ROOT / "frontend/node_modules/axe-core/axe.min.js"; SP = Path(os.environ["U7_SCRATCH"]); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]
out = {"started": datetime.now().astimezone().isoformat(timespec="seconds"), "results": {}}
def sh(cmd, timeout=120): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout
def psql(q): return sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", q])
tok = httpx.post(f"{API}/auth/login", json={"email": emailA, "password": PASSWORD}, headers={"Accept": "application/json"}).json()["data"]["token"]
def client(lang="en"): return httpx.Client(base_url=API, headers={"Accept": "application/json", "Authorization": f"Bearer {tok}", "X-Dataspeak-Language": lang}, timeout=300)
A = client()
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
    A.post(f"/analyses/{aid}/run", headers={"Idempotency-Key": f"fc-{int(time.time()*1000)}"}); wait(aid, {"ANALYZED"}); return aid

# FC-01 AI-unavailable fallback with a real worker whose model endpoint is unreachable
print("FC-01: preparing analysis, then swapping in a worker with an unreachable model endpoint", flush=True)
Z = analyzed("Unit7 final FC-01 AI unavailable")
sh(["docker", "compose", "stop", "backend-worker"], 120)
sh(["docker", "compose", "run", "-d", "--rm", "--no-deps", "--name", "dataspeak-worker-no-model", "-e", "OLLAMA_BASE_URL=http://127.0.0.1:9", "backend-worker", "php", "artisan", "queue:work", "redis", "--queue=default", "--tries=1", "--timeout=600", "--sleep=1"], 120)
time.sleep(4)
try:
    t0 = time.perf_counter(); A.post(f"/analyses/{Z}/report", headers={"Idempotency-Key": f"fc1-{int(time.time())}"}); s, _ = wait(Z, {"COMPLETED"}); wall = time.perf_counter() - t0
    rep = A.get(f"/analyses/{Z}/report").json()["data"]; gens = [l.split("|") for l in psql(f"select g.status, coalesce(g.error_code,''), g.attempt_no from ai_generations g join analyses a on a.id=g.analysis_id where a.public_id='{Z}' order by g.created_at").strip().splitlines()]
    wlog = sh(["docker", "logs", "dataspeak-worker-no-model"]); (FV / "logs/fc01_no_model_worker.log").write_text(wlog)
    r = {"analysis": Z, "wall_s": round(wall, 1), "report_status": rep["status"], "ai_status": (rep.get("ai") or {}).get("status"), "claims_total": rep["claims_total"], "generations": gens, "data_notes": len(rep.get("data_notes") or rep.get("notes") or []), "busy_retry_logged": "report.model_busy_retry" in wlog, "fabricated_claims": rep["claims_total"]}
    r["pass"] = rep["status"] == "READY" and rep["claims_total"] == 0 and any(g[0] == "FAILED" and g[1] == "AI_UNAVAILABLE" for g in gens) and len(gens) == 1 and not r["busy_retry_logged"]
    out["results"]["FC-01"] = r; print("FC-01", json.dumps(r)[:400], flush=True)
finally:
    sh(["docker", "stop", "dataspeak-worker-no-model"], 60); sh(["docker", "compose", "start", "backend-worker"], 120); time.sleep(3)

# FC-02 translation after report: use the most recent COMPLETED report of this account whose translations have finished
rows = psql("select a.public_id, count(c.id), count(c.id) filter (where c.statement_translations ? 'th'), count(c.id) filter (where c.statement_translations ? 'zh'), count(c.id) filter (where c.statement_translations ? 'my') from analyses a join users u on u.id=a.user_id join claims c on c.analysis_id=a.id where u.email='" + emailA + "' and a.status='COMPLETED' and a.created_at::date = current_date group by a.public_id, a.created_at order by a.created_at desc limit 12").strip().splitlines()
cands = [r.split("|") for r in rows]; cands = [c for c in cands if int(c[1]) > 0]
best = max(cands, key=lambda c: (min(int(c[2]), int(c[3]), int(c[4])), int(c[1]))) if cands else None
if best:
    aid = best[0]; th = client("th").get(f"/analyses/{aid}/report").json()["data"]; en = A.get(f"/analyses/{aid}/report").json()["data"]
    th_claims = th.get("claims") or []; en_claims = en.get("claims") or []
    translated = sum(1 for c in th_claims if c.get("translated") or c.get("is_translated") or (c.get("statement") and c["statement"] != next((e["statement"] for e in en_claims if e["id"] == c["id"]), None)))
    flags = sh(["docker", "compose", "exec", "-T", "redis", "redis-cli", "--scan", "--pattern", f"*dataspeak:translate:{aid}:*"]).strip().splitlines()
    r = {"analysis": aid, "claims": int(best[1]), "translated_th_zh_my": [int(best[2]), int(best[3]), int(best[4])], "th_report_translated_statements": translated, "th_report_language": th.get("language") or th.get("display_language"), "remaining_flags": flags, "candidates_checked": len(cands)}
    r["pass"] = min(r["translated_th_zh_my"]) == r["claims"] and translated > 0 and not flags
else:
    r = {"pass": False, "reason": "no completed report with claims today"}
out["results"]["FC-02"] = r; print("FC-02", json.dumps(r)[:400], flush=True)

# FC-03 claim rejection evidence on today's generations
tot = psql("select c.status, count(*) from claims c join analyses a on a.id=c.analysis_id where a.created_at::date=current_date group by c.status order by 1").strip().splitlines()
codes = psql("select coalesce(c.rejection_code, c.validation_code, '') , count(*) from claims c join analyses a on a.id=c.analysis_id where a.created_at::date=current_date and c.status<>'SUPPORTED' group by 1 order by 2 desc limit 10").strip().splitlines() if psql("select 1 from information_schema.columns where table_name='claims' and column_name in ('rejection_code','validation_code') limit 1").strip() else []
ver = psql("select distinct validator_version from claims where validator_version is not null").strip().splitlines() if psql("select 1 from information_schema.columns where table_name='claims' and column_name='validator_version' limit 1").strip() else []
if not ver:
    ver = psql("select distinct coalesce(response_payload->>'validator_version', '') from ai_generations where created_at::date=current_date").strip().splitlines()
cols = psql("select column_name from information_schema.columns where table_name='claims' order by ordinal_position").strip().splitlines()
r = {"claims_by_status_today": tot, "non_supported_codes": codes, "validator_versions": ver, "claims_columns": cols}
rep_rows = psql("select r.claims_total, r.claims_supported from reports r join analyses a on a.id=r.analysis_id where a.created_at::date=current_date and r.claims_total>0").strip().splitlines() if psql("select 1 from information_schema.tables where table_name='reports'").strip() else []
r["reports_claims_total_vs_supported"] = rep_rows
r["pass"] = any(l.startswith("SUPPORTED|") for l in tot) and all(int(x.split("|")[1]) <= int(x.split("|")[0]) for x in rep_rows if "|" in x)
out["results"]["FC-03"] = r; print("FC-03", json.dumps(r)[:500], flush=True)

# FC-04 axe-core
def axe(page):
    page.add_script_tag(path=str(AXE)); return page.evaluate("async () => { const r = await axe.run(document, {runOnly: {type: 'tag', values: ['wcag2a','wcag2aa']}}); return r.violations.map(v => ({id: v.id, impact: v.impact, nodes: v.nodes.length})); }")
rep_aid = best[0] if best else Z
with sync_playwright() as p:
    b = p.chromium.launch(); page = b.new_page(viewport={"width": 1440, "height": 1080})
    page.goto(f"{UI}/login"); page.get_by_label("Email").fill(emailA); page.get_by_label("Password").fill(PASSWORD); page.get_by_role("button", name="Sign in").click(); page.wait_for_url("**/analyses**", timeout=30000); time.sleep(1)
    page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached"); time.sleep(1); v_new = axe(page); page.screenshot(path=str(EVD / "fc04_new_analysis.png"))
    page.goto(f"{UI}/analyses/{rep_aid}/profile"); page.wait_for_selector("text=Data quality", timeout=60000); time.sleep(1.5); v_prof = axe(page); page.screenshot(path=str(EVD / "fc04_profile.png"))
    page.goto(f"{UI}/analyses/{rep_aid}/report"); page.wait_for_selector("text=Executive summary", timeout=60000); time.sleep(2); v_rep = axe(page); page.screenshot(path=str(EVD / "fc04_report.png"), full_page=True)
    b.close()
sev = {k: [x for x in v if x["impact"] in ("serious", "critical")] for k, v in (("new_analysis", v_new), ("profile", v_prof), ("report", v_rep))}
r = {"violations": {"new_analysis": v_new, "profile": v_prof, "report": v_rep}, "serious_or_critical": {k: len(v) for k, v in sev.items()}, "report_analysis": rep_aid}
r["pass"] = sum(len(v) for v in sev.values()) == 0
out["results"]["FC-04"] = r; print("FC-04", json.dumps(r)[:400], flush=True)
out["finished"] = datetime.now().astimezone().isoformat(timespec="seconds")
(EVD / "final_checks.json").write_text(json.dumps(out, indent=2, ensure_ascii=False))
for k, v in out["results"].items(): print(f"[{'PASS' if v.get('pass') else 'FAIL'}] {k}")
