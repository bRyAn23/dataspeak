"""Re-run SYS-09, SYS-08 and INT-08 for the post-fix baseline once the local model is idle (the first attempt timed out
while a translation job occupied Ollama; see DEF-09). Appends SYS-09b/SYS-08b/INT-08b to the system-run results."""
import json, os, re, subprocess, sys, time
from datetime import datetime
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); EV = ROOT / "docs/testing/unit7/retesting/evidence/system-run"; SYSD = EV / "system"; INT = EV / "integration"
API = "http://localhost:8000/api/v1"; UI = "http://localhost:5173"; PASSWORD = "Unit7Test#2026x"; SP = Path(os.environ["U7_SCRATCH"]); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]
def sh(cmd): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=120).stdout.strip()
res = json.loads((EV / "system_test_results.json").read_text())
def record(cid, level, objective, pre, steps, expected, actual, result, evidence="", defect=""):
    res["results"].append({"id": cid, "level": level, "objective": objective, "preconditions": pre, "steps": steps, "expected": expected, "actual": actual, "result": result, "evidence": evidence, "defect": defect, "duration_s": None, "executed_at": datetime.now().astimezone().isoformat(timespec="seconds")})
    (EV / "system_test_results.json").write_text(json.dumps(res, indent=2, ensure_ascii=False)); print(f"[{result}] {cid}: {actual[:160]}", flush=True)
# wait for an idle model: translations queue empty and no running TranslateReportJob
for _ in range(120):
    q = sh(["docker", "compose", "exec", "-T", "redis", "redis-cli", "LLEN", "laravel-database-queues:translations"])
    busy = "RUNNING" in sh(["docker", "compose", "logs", "--tail", "1", "backend-worker-translations"]) and "DONE" not in sh(["docker", "compose", "logs", "--tail", "1", "backend-worker-translations"])
    if q == "0" and not busy: break
    time.sleep(10)
ps = httpx.get("http://127.0.0.1:11434/api/ps", timeout=5).json(); print("ollama loaded models before start:", [m["name"] for m in ps.get("models", [])], "| translations queue:", q, flush=True)
tok = httpx.post(f"{API}/auth/login", json={"email": emailA, "password": PASSWORD}, headers={"Accept": "application/json"}).json()["data"]["token"]
A = httpx.Client(base_url=API, headers={"Accept": "application/json", "Authorization": f"Bearer {tok}", "X-Dataspeak-Language": "en"}, timeout=300)
u1 = next(x["id"] for x in A.get("/analyses").json()["data"] if x["name"] == "Unit7 SYS workflow")
t0 = time.time(); r = A.post(f"/analyses/{u1}/report", headers={"Idempotency-Key": f"u7-sys09b-{int(time.time())}"}); print("report requested", r.status_code, flush=True)
while time.time() - t0 < 1500:
    s = A.get(f"/analyses/{u1}/status").json()["data"]
    if s["status"] in ("COMPLETED", "FAILED"): break
    time.sleep(4)
rep = A.get(f"/analyses/{u1}/report").json()["data"]; gen_s = time.time() - t0
ok9 = rep["status"] == "READY" and rep["claims_total"] > 0 and (rep.get("ai") or {}).get("status") == "ready"
record("SYS-09b", "system", "Executive report generation re-run with an idle local model (first attempt in this run timed out while a translation occupied the model, see DEF-09)", "ANALYZED analysis; translations queue empty", "POST /report; wait; read report", "Report READY with claims and ai status ready", f"report status={rep['status']} ai={(rep.get('ai') or {}).get('status')} claims_total={rep['claims_total']} supported={rep['claims_supported']} generation {gen_s:.0f}s", "PASS" if ok9 else "FAIL", "evidence/system-run/system/sys09b-executive-report.png", "DEF-09")
with sync_playwright() as p:
    b = p.chromium.launch(); page = b.new_context(viewport={"width": 1920, "height": 1080}, device_scale_factor=1.5).new_page()
    page.goto(f"{UI}/login"); page.wait_for_selector("#email"); page.fill("#email", emailA); page.fill("#password", PASSWORD); page.click("button[type=submit]"); page.wait_for_url("**/analyses", timeout=30000)
    page.goto(f"{UI}/analyses/{u1}/report"); page.wait_for_selector("text=Executive summary", timeout=60000); time.sleep(2); page.screenshot(path=str(SYSD / "sys09b-executive-report.png"))
    links = page.locator("a[href*='/claims/']"); n = links.count(); ok8 = False; body = ""
    if n:
        links.first.click(); page.wait_for_selector("text=How we know", timeout=30000); time.sleep(1.5); body = page.inner_text("body"); page.screenshot(path=str(SYSD / "sys08b-insight-evidence-panel.png"))
        ok8 = "Checked against the calculated results" in body and "THE NUMBERS BEHIND IT" in body.upper()
    b.close()
record("SYS-08b", "system", "Insight statement displays its supporting evidence (re-run)", "Report READY with claims", "Click a claim link in the executive summary", "'How we know' panel with the verification badge and the evidence card", f"claim links={n}; badge present={('Checked against the calculated results' in body)}", "PASS" if ok8 else "FAIL", "evidence/system-run/system/sys08b-insight-evidence-panel.png")
ids = list(dict.fromkeys(re.findall(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", json.dumps(rep.get("sections")))))[:8]
claims = [A.get(f"/analyses/{u1}/claims/{i}").json().get("data") for i in ids]; statuses = [c.get("status") for c in claims if c]; evid = [len(c.get("evidence") or []) for c in claims if c]
methods = sorted({(c.get("validation") or {}).get("method") for c in claims if c})
dbq = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select c.status, c.in_report, count(*) from claims c join analyses a on a.id=c.analysis_id where a.public_id='{u1}' group by 1,2 order by 1,2"])
bad = [l for l in dbq.splitlines() if l.split("|")[1] == "t" and l.split("|")[0] != "SUPPORTED"]
braces = [c["statement"] for c in (rep.get("claims") or []) if re.search(r"[{}]", c.get("statement") or "")]
ok = len(claims) > 0 and all(s == "SUPPORTED" for s in statuses) and all(x > 0 for x in evid) and not bad and not braces
(INT / "int08b_claim_validation.json").write_text(json.dumps({"claims_total": rep["claims_total"], "claims_supported": rep["claims_supported"], "sampled_claim_statuses": statuses, "sampled_evidence_counts": evid, "validation_methods": methods, "db_status_in_report_counts": dbq, "non_supported_in_report": bad, "statements_with_braces": braces}, indent=2, ensure_ascii=False))
record("INT-08b", "integration", "Claim validation (re-run): only SUPPORTED claims enter the report; no rendered braces (DEF-01)", "Report READY", "GET report; GET each referenced claim; psql group by status,in_report", "All referenced claims SUPPORTED with evidence; no non-SUPPORTED claim in report; validator rule_based_v2; 0 brace fragments", f"claims_total={rep['claims_total']} supported={rep['claims_supported']}; sampled={statuses}; methods={methods}; db={dbq.replace(chr(10),' ; ')}; braces={len(braces)}", "PASS" if ok else "FAIL", "evidence/system-run/integration/int08b_claim_validation.json", "DEF-01")
print("narrative re-run done")
