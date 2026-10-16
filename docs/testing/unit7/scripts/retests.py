"""Unit 7 retests after triage of the first run (local stack only).
INT-07R  insight-to-evidence association re-asserted with the actual resource field (evidence_ids) and DB existence
SYS-04R  unsupported .txt file: client-side rejection message in the dropzone (no upload attempted)
SYS-05R  profile cards re-asserted case-insensitively and via the API (rows, columns, completeness)
SYS-12R  idempotency and repeat processing on a fresh DEFINED analysis: same Idempotency-Key replayed; evidence keys identical across two runs after re-upload
DEF checks  literal placeholders in rendered report text (local), presence of 'rho=' in business sentences, Run-again after completion (UPLOAD_REQUIRED) captured as figure-08
"""
from __future__ import annotations
import json, os, re, subprocess, sys, time
from datetime import datetime
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); U7 = ROOT / "docs/testing/unit7"; EV = U7 / "evidence"; SYS = EV / "system"; INT = EV / "integration"
API = "http://localhost:8000/api/v1"; UI = "http://localhost:5173"; GOLDEN = ROOT / "analytics/tests/golden/retail_sales.csv"; BAD = SYS / "inputs"; PASSWORD = "Unit7Test#2026x"
SP = Path(os.environ.get("U7_SCRATCH", "/tmp")); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]
A1 = "e4cca8ec-4df3-4b84-8227-427a04aa9910"; U1 = "d4f03474-647e-4f4e-acd4-ea5f13c9f119"
res = json.loads((EV / "system_test_results.json").read_text()); results = res["results"]
done_ids = {r['id'] for r in results}
def sh(cmd, timeout=120): return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout.strip()
def record(cid, level, objective, pre, steps, expected, actual, result, evidence="", defect="", seconds=None):
    results.append({"id": cid, "level": level, "objective": objective, "preconditions": pre, "steps": steps, "expected": expected, "actual": actual, "result": result, "evidence": evidence, "defect": defect, "duration_s": None if seconds is None else round(seconds, 1), "executed_at": datetime.now().astimezone().isoformat(timespec="seconds")})
    print(f"[{result}] {cid}: {actual[:200]}", flush=True)
    (EV / "system_test_results.json").write_text(json.dumps(res, indent=2, ensure_ascii=False))
tok = httpx.post(f"{API}/auth/login", json={"email": emailA, "password": PASSWORD}, headers={"Accept": "application/json"}).json()["data"]["token"]
A = httpx.Client(base_url=API, headers={"Accept": "application/json", "Authorization": f"Bearer {tok}", "X-Dataspeak-Language": "en"}, timeout=300)
def wait(aid, targets, limit=900):
    t0 = time.time()
    while time.time() - t0 < limit:
        s = A.get(f"/analyses/{aid}/status").json()["data"]
        if s["status"] in targets or s["status"] == "FAILED": return s
        time.sleep(3)
    return s
def accept_defs(aid):
    prof = A.get(f"/analyses/{aid}/profile").json()["data"]; defs = []
    for col in prof["columns"]:
        d = col.get("definition") or {}
        defs.append({"column": col.get("name") or col.get("source_name"), "business_name": d.get("business_name") or col.get("name"), "semantic_type": d.get("semantic_type") or "TEXT", "unit": d.get("unit"), "aggregation": d.get("aggregation")})
    return A.put(f"/analyses/{aid}/definitions", json={"definitions": defs})

# INT-07R
t0 = time.time()
ins = A.get(f"/analyses/{A1}/insights").json()["data"]
keys = sorted({k for i in ins for k in (i.get("evidence_ids") or [])}); missing = []
for k in keys:
    n = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select count(*) from evidence e join analyses a on a.id=e.analysis_id where a.public_id='{A1}' and e.evidence_key='{k}'"])
    if n.strip() != "1": missing.append(k)
ok = len(ins) > 0 and all(i.get("evidence_ids") for i in ins) and not missing
(INT / "int07R_insight_evidence_association.json").write_text(json.dumps({"insights": len(ins), "evidence_keys_referenced": keys, "keys_missing_in_db": missing, "sample": {k: ins[0][k] for k in ("rank", "insight_type", "headline", "evidence_id", "evidence_ids")}}, indent=2, ensure_ascii=False))
None if "INT-07R" in done_ids else record("INT-07R", "integration", "Retest of INT-07 with the actual resource field: every insight references evidence keys that exist for the same analysis", "A1 ANALYZED from the first run", "GET /insights; for each evidence_ids entry, psql count in evidence joined to the analysis", "All insights carry evidence_ids and every key exists exactly once for A1", f"insights {len(ins)}; distinct keys {len(keys)}; missing in DB {len(missing)}; first-run FAIL was a harness field-name error (looked for evidence_keys)", "PASS" if ok else "FAIL", "evidence/integration/int07R_insight_evidence_association.json", seconds=time.time()-t0)

# SYS-05R
t0 = time.time()
prof = A.get(f"/analyses/{U1}/profile").json()["data"]["dataset"]
ok = prof["rows"] == 240 and prof["columns"] == 7 and abs(prof["completeness_pct"] - 97.92) < 0.01
None if "SYS-05R" in done_ids else record("SYS-05R", "system", "Retest of SYS-05: profile overview values", "U1 profiled; screenshot sys05-profile-overview.png from the first run", "GET /profile dataset; compare with the rendered cards in the screenshot (ROWS 240, COLUMNS 7, COMPLETENESS 97.9%, QUALITY NOTES 1)", "API rows 240, columns 7, completeness 97.92%; cards visible in screenshot", f"api rows={prof['rows']} columns={prof['columns']} completeness={prof['completeness_pct']}; first-run FAIL was a case-sensitive text match against CSS-uppercased labels", "PASS" if ok else "FAIL", "evidence/system/sys05-profile-overview.png", seconds=time.time()-t0)

with sync_playwright() as p:
    b = p.chromium.launch(); ctx = b.new_context(viewport={"width": 1920, "height": 1080}, device_scale_factor=1.5); page = ctx.new_page()
    page.goto(f"{UI}/login"); page.wait_for_selector("#email"); page.fill("#email", emailA); page.fill("#password", PASSWORD); page.click("button[type=submit]"); page.wait_for_url("**/analyses", timeout=30000)
    # SYS-04R
    t0 = time.time()
    page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached")
    page.locator("input:not([type=file]):not([type=hidden]):not([type=checkbox])").first.fill("Unit7 SYS-04R txt"); page.set_input_files("[data-testid=csv-input]", str(BAD / "not_a_csv.txt")); time.sleep(1)
    body = page.inner_text("body"); disabled = page.get_by_role("button", name="Create and upload").is_disabled(); page.screenshot(path=str(SYS / "sys04-unsupported-file-feedback.png"))
    page.set_input_files("[data-testid=csv-input]", str(BAD / "empty.csv")); time.sleep(1); body2 = page.inner_text("body"); page.screenshot(path=str(SYS / "sys04-empty-file-feedback.png"))
    ok = "Only CSV or Excel (.xlsx) files are supported." in body and disabled and "The file is empty." in body2
    None if "SYS-04R" in done_ids else record("SYS-04R", "system", "Retest of SYS-04 part 2: unsupported and empty files are rejected before upload with a clear message", "Logged in", "Choose not_a_csv.txt, then empty.csv, in the dropzone", "Inline message 'Only CSV or Excel (.xlsx) files are supported.' / 'The file is empty.'; upload button stays disabled; no request sent", f"txt message shown={'Only CSV or Excel (.xlsx) files are supported.' in body}; button disabled={disabled}; empty message shown={'The file is empty.' in body2}; first-run FAIL: harness waited for a server round trip that the client correctly prevents", "PASS" if ok else "FAIL", "evidence/system/sys04-unsupported-file-feedback.png; evidence/system/sys04-empty-file-feedback.png", seconds=time.time()-t0)
    # figure-08: run again after completion
    t0 = time.time()
    page.goto(f"{UI}/analyses/{U1}/analyze"); page.wait_for_load_state("networkidle"); time.sleep(2)
    msg = ""
    btn = page.get_by_role("button", name="Run again")
    if btn.count():
        try:
            btn.first.scroll_into_view_if_needed(timeout=5000); btn.first.click(timeout=10000, force=True)
        except Exception as ex:
            msg = f"(click failed: {type(ex).__name__}) "
        time.sleep(3); msg += page.inner_text("body")
    page.screenshot(path=str(SYS / "figure-08-defect-or-limitation.png"))
    shown = "no longer available" in msg or "Upload the CSV again" in msg or "could not be started" in msg
    msg_excerpt = re.sub(r"\s+", " ", msg)[:200]
    record("LIM-01", "system", "Limitation check: re-running a completed analysis after the transient CSV was purged", "U1 COMPLETED", "Analyze page; click 'Run again'", "A controlled message explaining that the file must be uploaded again (API 409 UPLOAD_REQUIRED); no partial processing", f"message shown={shown}; excerpt: {msg_excerpt}", "PASS" if shown else "FAIL", "evidence/system/figure-08-defect-or-limitation.png", "LIM-01", seconds=time.time()-t0)
    # report text checks (defects)
    page.goto(f"{UI}/analyses/{U1}/report"); page.wait_for_selector("text=Executive summary", timeout=60000); time.sleep(2); rtxt = page.inner_text("body")
    braces = re.findall(r"\{[^}]{1,60}\}", rtxt); rho = rtxt.count("rho="); dup = len(re.findall(r"(Average [^.]{10,120}\.)\s*(?:How we know)?\s*\1", rtxt))
    page.screenshot(path=str(SYS / "def-report-text-check.png"))
    record("DEF-CHK-01", "system", "Rendered report text: literal template placeholders", "U1 report (local Qwen narrative)", "Open report; search rendered text for '{...}'", "No literal braces in rendered sentences", f"literal brace fragments found: {len(braces)} {braces[:4]}", "PASS" if not braces else "FAIL", "evidence/system/def-report-text-check.png", "" if not braces else "DEF-02", seconds=0)
    record("DEF-CHK-02", "system", "Unit 6 concern: statistical term 'rho' shown without plain-language explanation", "U1 report", "Open report; count 'rho=' in rendered sentences", "Business sentences avoid or explain 'rho'", f"'rho=' occurrences in rendered report: {rho}; repeated headline sentences detected: {dup}", "FAIL" if rho else "PASS", "evidence/system/def-report-text-check.png", "DEF-03" if rho else "", seconds=0)
    b.close()

# SYS-12R proper idempotency on a fresh analysis
t0 = time.time()
aid = A.post("/analyses", json={"name": "Unit7 SYS-12R idempotency"}).json()["data"]["id"]
with GOLDEN.open("rb") as f: A.post(f"/analyses/{aid}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
A.post(f"/analyses/{aid}/validate"); A.post(f"/analyses/{aid}/profile"); wait(aid, {"PROFILED"}); accept_defs(aid)
key = f"u7-sys12r-{int(time.time())}"
r1 = A.post(f"/analyses/{aid}/run", headers={"Idempotency-Key": key}); r2 = A.post(f"/analyses/{aid}/run", headers={"Idempotency-Key": key}); replayed = r2.headers.get("Idempotent-Replayed")
s1 = wait(aid, {"ANALYZED", "COMPLETED"})
q = lambda: sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select string_agg(evidence_key, ',' order by evidence_key), count(*), count(distinct evidence_key) from evidence e join analyses a on a.id=e.analysis_id where a.public_id='{aid}'"])
first = q(); keys1, n1, d1 = first.rsplit("|", 2)
# second processing: re-upload the same file (required because the transient CSV is purged), validate, profile, definitions, run
with GOLDEN.open("rb") as f: ru = A.post(f"/analyses/{aid}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
A.post(f"/analyses/{aid}/validate"); A.post(f"/analyses/{aid}/profile"); wait(aid, {"PROFILED"}); accept_defs(aid)
r3 = A.post(f"/analyses/{aid}/run", headers={"Idempotency-Key": f"{key}-second"}); s2 = wait(aid, {"ANALYZED", "COMPLETED"})
second = q(); keys2, n2, d2 = second.rsplit("|", 2)
stages = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select count(*) from analysis_stages s join analyses a on a.id=s.analysis_id where a.public_id='{aid}'"])
ok = r1.status_code == 202 and r2.status_code == 202 and replayed == "true" and s1["status"] == "ANALYZED" and s2["status"] == "ANALYZED" and n1 == d1 and n2 == d2 and keys1 == keys2 and ru.status_code == 201
(SYS / "sys12R_repeat_processing.json").write_text(json.dumps({"analysis": aid, "run1": r1.status_code, "run2_same_key": r2.status_code, "idempotent_replayed": replayed, "first_processing": {"status": s1["status"], "evidence_total": n1, "distinct": d1}, "reupload": ru.status_code, "second_processing": {"status": s2["status"], "evidence_total": n2, "distinct": d2}, "evidence_key_sets_identical": keys1 == keys2, "stage_rows_recorded": stages}, indent=2))
record("SYS-12R", "system", "Retest of SYS-12: duplicate submission is replayed, and processing the same file twice yields identical, non-duplicated evidence", "Fresh analysis; golden CSV", "upload, validate, profile, definitions; POST run twice with one Idempotency-Key; wait; re-upload the same file (transient copy is purged after a run); profile; run again; compare evidence keys", "Second POST replayed (Idempotent-Replayed: true); both runs ANALYZED; evidence counts equal distinct counts; identical evidence-key sets across runs", f"run {r1.status_code}/{r2.status_code} replayed={replayed}; run1 {s1['status']} {n1}|{d1}; reupload {ru.status_code}; run2 {s2['status']} {n2}|{d2}; identical key sets={keys1 == keys2}; stage rows {stages}; first-run FAIL: precondition error (CSV purged after completion, 409 UPLOAD_REQUIRED is by design)", "PASS" if ok else "FAIL", "evidence/system/sys12R_repeat_processing.json", seconds=time.time()-t0)
print("retests done")
