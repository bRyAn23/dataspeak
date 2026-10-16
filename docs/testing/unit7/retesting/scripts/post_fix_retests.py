"""Post-fix retests on the LOCAL dev stack (never production).
RT-04  DEF-04 queue contention: generate a report (queues 3 translation jobs), then immediately profile another dataset and time it
RT-01  DEF-01 rendered report has no literal braces (and the validator now records STRAY_BRACES/BRACES_NORMALISED when they occur)
RT-02  DEF-02 no chart explanation repeats the preceding sentence
RT-03  DEF-03 no 'rho=' in business sentences
RT-05  DEF-05 completion panel explains the purged file and offers 'Upload the file again'
RT-08  DEF-08 missing-value warning visible without scrolling at 1080 px
RT-07  DEF-07 axe contrast violations on new-analysis/profile/report and claim-link target size
"""
import json, os, re, subprocess, sys, time
from datetime import datetime
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); RT = ROOT / "docs/testing/unit7/retesting"; EVD = RT / "evidence"; EVD.mkdir(exist_ok=True)
API = "http://localhost:8000/api/v1"; UI = "http://localhost:5173"; GOLDEN = ROOT / "analytics/tests/golden/retail_sales.csv"; PASSWORD = "Unit7Test#2026x"
AXE = ROOT / "frontend/node_modules/axe-core/axe.min.js"; SP = Path(os.environ["U7_SCRATCH"]); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]
out = {"started": datetime.now().astimezone().isoformat(timespec="seconds"), "results": {}}
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
print("creating analysis X and generating a report (local Qwen)", flush=True)
X = new_analysis("Unit7 retest X (report)"); A.post(f"/analyses/{X}/profile"); wait(X, {"PROFILED"}); accept_defs(X)
A.post(f"/analyses/{X}/run", headers={"Idempotency-Key": f"rt-x-{int(time.time())}"}); wait(X, {"ANALYZED"})
A.post(f"/analyses/{X}/report", headers={"Idempotency-Key": f"rt-xr-{int(time.time())}"}); s, rep_s = wait(X, {"COMPLETED"}); t_done = datetime.utcnow()
print(f"report {s['status']} in {rep_s:.0f}s; translations now queued. Starting profile of Y immediately", flush=True)
# RT-04: profile Y right after the report completes (translation jobs are now queued/running)
Y = new_analysis("Unit7 retest Y (contention)"); t0 = time.perf_counter(); A.post(f"/analyses/{Y}/profile"); sy, _ = wait(Y, {"PROFILED"}); prof_s = time.perf_counter() - t0
time.sleep(3)
wl = sh(["docker", "compose", "logs", "--since", t_done.strftime("%Y-%m-%dT%H:%M:%SZ"), "backend-worker", "backend-worker-translations"])
lines = [l.strip() for l in wl.splitlines() if re.search(r"(Processing|Processed|RUNNING|DONE)", l)]
trans_running_on = sorted({l.split("|")[0].strip() for l in lines if "TranslateReportJob" in l})
profile_on = sorted({l.split("|")[0].strip() for l in lines if "ProfileAnalysisJob" in l})
(EVD / "rt04_worker_logs.log").write_text("\n".join(lines))
out["results"]["RT-04"] = {"defect": "DEF-04", "profile_job_wall_seconds_during_translations": round(prof_s, 1), "profile_status": sy["status"], "translate_jobs_seen_on": trans_running_on, "profile_job_seen_on": profile_on, "baseline_before_fix_seconds": 73.3, "pass": sy["status"] == "PROFILED" and prof_s < 40 and any("translations" in c for c in trans_running_on)}
print("RT-04", out["results"]["RT-04"], flush=True)
# RT-01/02/03 on report X
rep = A.get(f"/analyses/{X}/report").json()["data"]
claims = rep.get("claims") or []; statements = [c.get("statement") or "" for c in claims]
braces = [s for s in statements if re.search(r"[{}]", s)]
reasons = [r for c in claims for r in ((c.get("validation") or {}).get("reasons") or [])]
rho = [s for s in statements if "rho=" in s]
dups = []
for v in rep.get("visualizations") or []:
    items = [i.get("text", "") for i in (v.get("summary") or [])]
    for i in range(1, len(items)):
        if items[i].startswith(items[i-1]) and items[i] != items[i-1]: dups.append(items[i][:120])
# fall back: chart summaries may live under sections
secs_txt = json.dumps(rep.get("sections"), ensure_ascii=False)
out["results"]["RT-01"] = {"defect": "DEF-01", "claims": len(claims), "statements_with_braces": len(braces), "validator_reasons_brace_related": sorted({r for r in reasons if "BRACE" in r}), "validator_method": sorted({(c.get("validation") or {}).get("method") for c in claims if c.get("validation")}), "pass": not braces}
out["results"]["RT-02"] = {"defect": "DEF-02", "repeated_lead_sentences_under_charts": dups, "pass": not dups}
out["results"]["RT-03"] = {"defect": "DEF-03", "statements_with_rho": len(rho), "pass": not rho}
print("RT-01/02/03", out["results"]["RT-01"]["pass"], out["results"]["RT-02"]["pass"], out["results"]["RT-03"]["pass"], flush=True)
def axe(page):
    page.add_script_tag(path=str(AXE)); return page.evaluate("async () => { const r = await axe.run(document, {resultTypes:['violations']}); return r.violations.map(v => ({id:v.id, impact:v.impact, nodes:v.nodes.length, targets:v.nodes.slice(0,3).map(n=>n.target.join(' '))})); }")
with sync_playwright() as p:
    b = p.chromium.launch(); page = b.new_context(viewport={"width": 1920, "height": 1080}, device_scale_factor=1.5).new_page()
    page.goto(f"{UI}/login"); page.wait_for_selector("#email"); page.fill("#email", emailA); page.fill("#password", PASSWORD); page.click("button[type=submit]"); page.wait_for_url("**/analyses", timeout=30000)
    # RT-05 completion panel on X (file purged after run)
    page.goto(f"{UI}/analyses/{X}/analyze"); page.wait_for_load_state("networkidle"); time.sleep(2); body = page.inner_text("body")
    btn = page.get_by_role("button", name="Upload the file again"); has_btn = btn.count() > 0; run_again = page.get_by_role("button", name="Run again").count()
    page.screenshot(path=str(EVD / "rt05_completion_panel_after_fix.png"))
    if has_btn: btn.first.click(); page.wait_for_load_state("networkidle"); time.sleep(1); page.screenshot(path=str(EVD / "rt05_upload_again_destination.png"))
    out["results"]["RT-05"] = {"defect": "DEF-05", "explanation_shown": "upload it again" in body, "upload_again_button": has_btn, "disabled_run_again_present": run_again > 0, "destination_url": page.url, "pass": has_btn and "upload it again" in body and "/analyses/new" in page.url}
    # RT-08 warning position on Y profile
    page.goto(f"{UI}/analyses/{Y}/profile"); page.wait_for_selector("text=Suggested meaning", timeout=120000); time.sleep(1)
    w = page.get_by_text("of values are missing").first; bb = w.bounding_box(); page.screenshot(path=str(EVD / "rt08_profile_warning_position.png"))
    out["results"]["RT-08"] = {"defect": "DEF-08", "warning_top_px": round(bb["y"]) if bb else None, "viewport_px": 1080, "visible_without_scroll": bool(bb and bb["y"] + bb["height"] <= 1080), "baseline_before_fix_px": 1187, "pass": bool(bb and bb["y"] + bb["height"] <= 1080)}
    v_profile = axe(page)
    # RT-07 axe on new analysis and report + link size
    page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached"); time.sleep(1); v_new = axe(page)
    page.goto(f"{UI}/analyses/{X}/report"); page.wait_for_selector("text=Executive summary", timeout=60000); time.sleep(2); v_rep = axe(page)
    link = page.locator("a[href*='/claims/']").first; lb = link.bounding_box(); page.screenshot(path=str(EVD / "rt07_report_after_fix.png"))
    contrast = {k: sum(x["nodes"] for x in v if x["id"] == "color-contrast") for k, v in (("new_analysis", v_new), ("profile", v_profile), ("report", v_rep))}
    out["results"]["RT-07"] = {"defect": "DEF-07", "color_contrast_nodes": contrast, "baseline_before_fix": {"new_analysis": 4, "profile": 10, "report": 1}, "other_violations": {k: [x["id"] for x in v if x["id"] != "color-contrast"] for k, v in (("new_analysis", v_new), ("profile", v_profile), ("report", v_rep))}, "claim_link_size_px": {k: round(lb[k]) for k in ("width", "height")} if lb else None, "baseline_link_px": "21x16", "pass": sum(contrast.values()) == 0 and bool(lb and lb["width"] >= 24 and lb["height"] >= 24)}
    b.close()
out["finished"] = datetime.now().astimezone().isoformat(timespec="seconds"); out["analyses"] = {"X": X, "Y": Y}
(RT / "evidence/post_fix_retests.json").write_text(json.dumps(out, indent=2, ensure_ascii=False))
for k, v in out["results"].items(): print(f"[{'PASS' if v['pass'] else 'FAIL'}] {k} ({v['defect']})")
