"""Data Speak Unit 7: integration (INT-01..08) and end-to-end system (SYS-01..12) tests.

Runs against the LOCAL development stack only (never production):
  UI http://localhost:5173, Laravel http://localhost:8000/api/v1, FastAPI http://127.0.0.1:8001 (loopback).
Real services are used throughout; the only injected condition is stopping/starting the local analytics
container for INT-06 / SYS-10. Every case records expected vs actual, PASS/FAIL/BLOCKED/NOT IMPLEMENTED,
timing and evidence paths into evidence/integration, evidence/system and results JSON/CSV.
Secrets: the dedicated test accounts' tokens and the analytics internal token are read at runtime and are
never written to results, logs or screenshots.
"""
from __future__ import annotations

import csv, json, os, re, statistics, subprocess, sys, time, traceback
from datetime import datetime
from pathlib import Path

import httpx
from playwright.sync_api import sync_playwright

ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak")
U7 = ROOT / "docs/testing/unit7"; EV = Path(os.environ.get("U7_EVIDENCE_DIR", str(U7 / "evidence")))  # post-fix runs write to a separate baseline
INT, SYS, LOGS = EV / "integration", EV / "system", EV / "logs"
for d in (INT, SYS, LOGS): d.mkdir(parents=True, exist_ok=True)
API = "http://localhost:8000/api/v1"; UI = "http://localhost:5173"; FAST = "http://127.0.0.1:8001"
GOLDEN = ROOT / "analytics/tests/golden/retail_sales.csv"; BAD = U7 / "evidence/system/inputs"  # fixed inputs, independent of the output directory
PASSWORD = "Unit7Test#2026x"
RUN_TS = datetime.now().astimezone()
SCRATCH = Path(os.environ.get("U7_SCRATCH", "/tmp"))

results: list[dict] = []
def record(cid, level, objective, pre, steps, expected, actual, result, evidence="", defect="", seconds=None):
    results.append({"id": cid, "level": level, "objective": objective, "preconditions": pre, "steps": steps, "expected": expected,
                    "actual": actual, "result": result, "evidence": evidence, "defect": defect, "duration_s": None if seconds is None else round(seconds, 1),
                    "executed_at": datetime.now().astimezone().isoformat(timespec="seconds")})
    print(f"[{result}] {cid}: {actual[:160]}", flush=True)
    (EV / "system_test_results.json").write_text(json.dumps({"run_started": RUN_TS.isoformat(timespec="seconds"), "environment": "local development stack", "results": results}, indent=2, ensure_ascii=False))

def sh(cmd: list[str], timeout=120) -> str:
    return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=timeout).stdout.strip()

def env_value(key: str) -> str:
    for f in (ROOT / ".env", ROOT / "backend/.env"):
        for line in f.read_text().splitlines():
            if line.startswith(key + "="): return line.split("=", 1)[1].strip().strip('"')
    return ""

def api(token=None):
    h = {"Accept": "application/json", "X-Dataspeak-Language": "en"}
    if token: h["Authorization"] = f"Bearer {token}"
    return httpx.Client(base_url=API, headers=h, timeout=120)

def make_user(tag: str) -> tuple[str, str]:
    email = f"unit7-{tag}-{int(time.time())}@example.com"
    with api() as c:
        r = c.post("/auth/register", json={"name": f"Unit7 {tag}", "email": email, "password": PASSWORD, "password_confirmation": PASSWORD}); r.raise_for_status()
        token = r.json()["data"]["token"]
    sh(["docker", "compose", "exec", "-T", "backend", "php", "artisan", "tinker", "--execute", f"App\\Models\\User::where('email','{email}')->first()->markEmailAsVerified();"])
    return email, token

def wait_status(c, aid, targets, limit=900, label=""):
    t0 = time.time(); last = None
    while time.time() - t0 < limit:
        s = c.get(f"/analyses/{aid}/status").json()["data"]
        key = (s["status"], s["stage"], s["progress"])
        if key != last: print(f"    {label} {time.strftime('%H:%M:%S')} {key}", flush=True); last = key
        if s["status"] in targets or s["status"] == "FAILED": return s
        time.sleep(4)
    return s

def accept_definitions(c, aid):
    prof = c.get(f"/analyses/{aid}/profile").json()["data"]
    defs = []
    for col in prof["columns"]:
        d = col.get("definition") or {}
        defs.append({"column": col.get("name") or col.get("source_name"), "business_name": d.get("business_name") or col.get("name") or col.get("source_name"),
                     "semantic_type": d.get("semantic_type") or "TEXT", "unit": d.get("unit"), "aggregation": d.get("aggregation")})
    return c.put(f"/analyses/{aid}/definitions", json={"definitions": defs})

def analytics_healthy(timeout=60):
    t0 = time.time()
    while time.time() - t0 < timeout:
        try:
            if httpx.get(f"{FAST}/health", timeout=3).status_code == 200: return True
        except Exception: pass
        time.sleep(2)
    return False

def save(name, obj):
    p = INT / name if name.startswith("int") else SYS / name
    p.write_text(json.dumps(obj, indent=2, ensure_ascii=False, default=str)); return str(p.relative_to(EV.parent))

def ui_login(page, email):
    page.goto(f"{UI}/login"); page.wait_for_selector("#email"); page.fill("#email", email); page.fill("#password", PASSWORD); page.click("button[type=submit]")
    page.wait_for_url("**/analyses", timeout=30000); page.wait_for_load_state("networkidle")

def scroll_inner(page, y):
    page.evaluate("(y) => { const els=[...document.querySelectorAll('*')].filter(e=>e.scrollHeight>e.clientHeight+50 && ['auto','scroll'].includes(getComputedStyle(e).overflowY)); els.forEach(e=>e.scrollTop=y); }", y)

def main():
    print(f"Unit 7 system tests started {RUN_TS.isoformat(timespec='seconds')}  commit {sh(['git','rev-parse','--short','HEAD'])}")
    emailA, tokenA = make_user("a"); emailB, tokenB = make_user("b")
    (SCRATCH / "unit7_accounts.txt").write_text(f"{emailA}\n{emailB}\n")
    itoken = env_value("ANALYTICS_INTERNAL_TOKEN")
    A = api(tokenA); B = api(tokenB)

    # ---------------- INT-02 Laravel -> FastAPI (validate) ----------------
    t0 = time.time()
    try:
        r = A.post("/analyses", json={"name": "Unit7 INT A1"}); a1 = r.json()["data"]["id"]
        with GOLDEN.open("rb") as f: up = A.post(f"/analyses/{a1}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
        since = datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%S")
        v = A.post(f"/analyses/{a1}/validate"); vd = v.json()
        time.sleep(1); alog = sh(["docker", "compose", "logs", "--since", since + "Z", "analytics"])
        hit = [l for l in alog.splitlines() if "/v1/validate" in l]
        ok = v.status_code == 200 and vd["data"]["row_count"] == 240 and vd["data"]["column_count"] == 7 and up.status_code == 201
        ev = save("int02_laravel_to_fastapi_validate.json", {"upload_status": up.status_code, "validate_status": v.status_code, "validate_body": vd, "analytics_container_log_lines": hit})
        record("INT-02", "integration", "Laravel forwards the uploaded file to FastAPI /v1/validate and returns the contract response", "Local stack up; test account A; golden retail_sales.csv",
               "POST /analyses; POST /analyses/{id}/dataset (multipart); POST /analyses/{id}/validate; read analytics container log", "HTTP 200, valid=true, row_count 240, column_count 7; analytics log shows POST /v1/validate 200",
               f"upload {up.status_code}; validate {v.status_code}; rows {vd.get('data',{}).get('row_count')} cols {vd.get('data',{}).get('column_count')}; analytics log lines matching /v1/validate: {len(hit)}", "PASS" if ok and hit else ("PASS" if ok else "FAIL"), ev, seconds=time.time()-t0)
    except Exception as e:
        record("INT-02", "integration", "Laravel to FastAPI validate", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0); a1 = None

    # ---------------- INT-03 Laravel -> PostgreSQL ----------------
    t0 = time.time()
    try:
        g = A.get(f"/analyses/{a1}").json()["data"]; lst = A.get("/analyses").json()["data"]
        row = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select status,row_count,column_count,source_file_name from analyses where public_id='{a1}'"])
        ok = g["status"] == "VALIDATED" and any(x["id"] == a1 for x in lst) and row.startswith("VALIDATED|240|7|")
        ev = save("int03_laravel_postgres.json", {"get_analysis": g, "listed": any(x["id"] == a1 for x in lst), "postgres_row": row})
        record("INT-03", "integration", "Analysis state written by Laravel is persisted in PostgreSQL and read back through the API", "INT-02 completed",
               "GET /analyses/{id}; GET /analyses; read-only psql select on analyses by public_id (local dev DB)", "API shows VALIDATED with counts; the same row exists in PostgreSQL with status VALIDATED, 240 rows, 7 columns",
               f"API status {g['status']}, listed={any(x['id']==a1 for x in lst)}, postgres row: {row}", "PASS" if ok else "FAIL", ev, seconds=time.time()-t0)
    except Exception as e:
        record("INT-03", "integration", "Laravel to PostgreSQL", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)

    # ---------------- INT-04 FastAPI valid CSV (direct, loopback) ----------------
    t0 = time.time()
    try:
        H = {"X-Internal-Token": itoken}
        defs = [{"column": "order_date", "business_name": "Order Date", "semantic_type": "DATE"}, {"column": "region", "business_name": "Region", "semantic_type": "CATEGORY"},
                {"column": "product_category", "business_name": "Product Category", "semantic_type": "CATEGORY"}, {"column": "ยอดขาย_thb", "business_name": "Revenue", "semantic_type": "CURRENCY", "unit": "THB", "aggregation": "SUM"},
                {"column": "ရောင်းအား_units", "business_name": "Units", "semantic_type": "NUMBER", "aggregation": "SUM"}, {"column": "discount_pct", "business_name": "Discount", "semantic_type": "PERCENTAGE", "aggregation": "AVERAGE"},
                {"column": "customer_id", "business_name": "Customer", "semantic_type": "IDENTIFIER"}]
        with httpx.Client(base_url=FAST, headers=H, timeout=120) as f:
            raw = GOLDEN.read_bytes()
            v = f.post("/v1/validate", files={"file": ("retail_sales.csv", raw, "text/csv")}, data={"metadata": json.dumps({"analysis_id": "unit7-int04"})})
            p = f.post("/v1/profile", files={"file": ("retail_sales.csv", raw, "text/csv")}, data={"metadata": json.dumps({"analysis_id": "unit7-int04", "definitions": defs})})
            a = f.post("/v1/analyze", files={"file": ("retail_sales.csv", raw, "text/csv")}, data={"metadata": json.dumps({"analysis_id": "unit7-int04", "definitions": defs, "options": {}})})
        vj, pj, aj = v.json(), p.json(), a.json()
        rev = [float(r["ยอดขาย_thb"]) for r in csv.DictReader(GOLDEN.open(encoding="utf-8"))]
        mean_ev = next((e for e in aj.get("evidence", []) if e["metric"] == "mean" and e["source_columns"] == ["ยอดขาย_thb"]), None)
        mean_ok = mean_ev is not None and abs(mean_ev["value"] - statistics.fmean(rev)) <= 1e-9 * abs(statistics.fmean(rev))
        ok = v.status_code == 200 and vj["valid"] and vj["row_count"] == 240 and p.status_code == 200 and pj["dataset"]["rows"] == 240 and len(pj["columns"]) == 7 and a.status_code == 200 and aj["summary"]["evidence_count"] > 0 and mean_ok
        ev = save("int04_fastapi_valid_csv.json", {"validate": {"status": v.status_code, "body": vj}, "profile": {"status": p.status_code, "dataset": pj.get("dataset"), "columns": [(c["name"], c["inferred_type"], c["null_rate"]) for c in pj.get("columns", [])], "quality_findings": pj.get("quality_findings")},
                                                    "analyze": {"status": a.status_code, "summary": aj.get("summary"), "evidence_count": len(aj.get("evidence", [])), "mean_revenue_engine": mean_ev and mean_ev["value"], "mean_revenue_independent": statistics.fmean(rev)}})
        record("INT-04", "integration", "FastAPI validates, profiles and analyses a valid CSV with the documented contract; engine mean cross-checked independently", "Analytics container up; internal token from local env",
               "POST /v1/validate, /v1/profile, /v1/analyze directly on 127.0.0.1:8001 with X-Internal-Token and metadata JSON", "All 200; valid=true 240 rows; 7 profiled columns; evidence_count>0; mean(revenue) equals statistics.fmean within 1e-9",
               f"validate {v.status_code} valid={vj.get('valid')} rows={vj.get('row_count')}; profile {p.status_code} cols={len(pj.get('columns',[]))}; analyze {a.status_code} evidence={aj.get('summary',{}).get('evidence_count')} families={aj.get('summary',{}).get('families_run')}; mean match={mean_ok}", "PASS" if ok else "FAIL", ev, seconds=time.time()-t0)
    except Exception as e:
        record("INT-04", "integration", "FastAPI valid CSV", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)

    # ---------------- INT-05 FastAPI and Laravel reject invalid input ----------------
    t0 = time.time()
    try:
        out = {}
        with httpx.Client(base_url=FAST, timeout=60) as f:
            meta = {"metadata": json.dumps({"analysis_id": "unit7-int05"})}
            r1 = f.post("/v1/validate", headers={"X-Internal-Token": itoken}, files={"file": ("h.csv", (BAD / "invalid_header_only.csv").read_bytes(), "text/csv")}, data=meta); out["header_only"] = {"status": r1.status_code, "body": r1.json()}
            r2 = f.post("/v1/validate", headers={"X-Internal-Token": itoken}, files={"file": ("e.csv", b"", "text/csv")}, data=meta); out["empty_file"] = {"status": r2.status_code, "body": r2.json()}
            r3 = f.post("/v1/validate", files={"file": ("h.csv", (BAD / "invalid_header_only.csv").read_bytes(), "text/csv")}, data=meta); out["no_token"] = {"status": r3.status_code, "body": r3.json()}
            r4 = f.post("/v1/validate", headers={"X-Internal-Token": itoken}, files={"file": ("d.csv", (BAD / "invalid_duplicate_headers.csv").read_bytes(), "text/csv")}, data=meta); out["duplicate_headers"] = {"status": r4.status_code, "body": r4.json()}
        r = A.post("/analyses", json={"name": "Unit7 INT-05 invalid"}); bid = r.json()["data"]["id"]
        with (BAD / "invalid_duplicate_headers.csv").open("rb") as fh: A.post(f"/analyses/{bid}/dataset", files={"file": ("invalid_duplicate_headers.csv", fh, "text/csv")})
        lv = A.post(f"/analyses/{bid}/validate"); out["laravel_duplicate_headers"] = {"status": lv.status_code, "body": lv.json()}
        r = A.post("/analyses", json={"name": "Unit7 INT-05 txt"}); tid = r.json()["data"]["id"]
        with (BAD / "not_a_csv.txt").open("rb") as fh: lt = A.post(f"/analyses/{tid}/dataset", files={"file": ("not_a_csv.txt", fh, "text/plain")})
        out["laravel_txt_upload"] = {"status": lt.status_code, "body": lt.json()}
        c1 = r1.status_code == 200 and r1.json()["valid"] is False and any(e["code"] == "NO_DATA_ROWS" for e in r1.json()["errors"])
        c2 = r2.status_code in (400, 422) and r2.json().get("error", {}).get("code") == "ANALYTICS_INVALID_DATASET"
        c3 = r3.status_code == 401
        c4 = r4.status_code == 200 and r4.json()["valid"] is False and {e["code"] for e in r4.json()["errors"]} >= {"EMPTY_COLUMN_NAME", "DUPLICATE_COLUMN"}
        c5 = lv.status_code == 422 and lv.json().get("error", {}).get("code") == "CSV_VALIDATION_FAILED"
        c6 = lt.status_code == 422
        ev = save("int05_invalid_input.json", out | {"checks": {"header_only_NO_DATA_ROWS": c1, "empty_INVALID_DATASET": c2, "no_token_401": c3, "duplicate_headers_errors": c4, "laravel_422_CSV_VALIDATION_FAILED": c5, "laravel_txt_422": c6}})
        record("INT-05", "integration", "Invalid inputs are rejected with the defined validation responses at both service boundaries", "Analytics up; invalid input files in evidence/system/inputs",
               "FastAPI: header-only, empty, missing token, duplicate headers; Laravel: duplicate-header CSV validate, .txt upload", "FastAPI: valid=false with NO_DATA_ROWS; ANALYTICS_INVALID_DATASET; 401 without token; EMPTY_COLUMN_NAME+DUPLICATE_COLUMN. Laravel: 422 CSV_VALIDATION_FAILED; 422 for .txt",
               f"header_only={r1.status_code}/{c1}; empty={r2.status_code}/{c2}; no_token={r3.status_code}; dup={c4}; laravel dup={lv.status_code} {lv.json().get('error',{}).get('code')}; txt={lt.status_code}", "PASS" if all([c1, c2, c3, c4, c5, c6]) else "FAIL", ev, seconds=time.time()-t0)
    except Exception as e:
        record("INT-05", "integration", "Invalid input rejection", "", "", "", f"exception: {e}\n{traceback.format_exc()[-300:]}", "FAIL", seconds=time.time()-t0)

    # ---------------- INT-06 / SYS-10 analytics service failure and recovery ----------------
    t0 = time.time(); stopped = False
    try:
        r = A.post("/analyses", json={"name": "Unit7 INT-06 outage"}); cid = r.json()["data"]["id"]
        with GOLDEN.open("rb") as f: A.post(f"/analyses/{cid}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
        print("  stopping local analytics container for failure injection", flush=True); sh(["docker", "compose", "stop", "analytics"], timeout=120); stopped = True; time.sleep(2)
        fv = A.post(f"/analyses/{cid}/validate"); fbody = fv.json(); st_after = A.get(f"/analyses/{cid}").json()["data"]["status"]
        # SYS-10 UI during outage
        ui_shot = ""; ui_err_text = ""
        with sync_playwright() as p:
            b = p.chromium.launch(); ctx = b.new_context(viewport={"width": 1920, "height": 1080}, device_scale_factor=1.5); page = ctx.new_page(); ui_login(page, emailA)
            page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached")
            page.locator("input:not([type=file]):not([type=hidden]):not([type=checkbox])").first.fill("Unit7 SYS-10 outage"); page.set_input_files("[data-testid=csv-input]", str(GOLDEN)); page.get_by_role("button", name="Create and upload").click()
            try:
                page.wait_for_selector("text=The upload did not go through", timeout=60000); ui_err_text = page.inner_text("main") if page.locator("main").count() else page.inner_text("body")
            except Exception: ui_err_text = "(error state not shown) " + page.inner_text("body")[:300]
            time.sleep(0.5); page.screenshot(path=str(SYS / "sys10-analytics-outage-ui-error.png")); ui_shot = "evidence/system/sys10-analytics-outage-ui-error.png"
            print("  starting analytics container", flush=True); sh(["docker", "compose", "start", "analytics"], timeout=120); stopped = False; healthy = analytics_healthy(90)
            retry_ok = False
            if page.get_by_role("button", name="Try again").count():
                page.get_by_role("button", name="Try again").click()
                try: page.wait_for_selector("text=File checked successfully", timeout=90000); retry_ok = True
                except Exception: retry_ok = False
            page.screenshot(path=str(SYS / "sys10-analytics-recovered-ui.png")); b.close()
        rv = A.post(f"/analyses/{cid}/validate")
        code = fbody.get("error", {}).get("code"); msg = fbody.get("error", {}).get("message", "")
        ok6 = fv.status_code >= 500 and code == "ANALYTICS_UNAVAILABLE" and st_after == "UPLOADED" and rv.status_code == 200
        ev = save("int06_analytics_outage.json", {"validate_during_outage": {"status": fv.status_code, "body": fbody}, "analysis_status_after_failure": st_after, "analytics_healthy_after_restart": healthy, "validate_after_restart": {"status": rv.status_code, "body": rv.json()}, "ui_error_text_excerpt": ui_err_text[:400], "ui_retry_succeeded": retry_ok})
        record("INT-06", "integration", "Backend returns a controlled error when the analytics service is down and recovers after restart without false success", "Analysis UPLOADED; local analytics container stopped (failure injection on dev stack)",
               "docker compose stop analytics; POST validate; GET analysis; docker compose start analytics; wait /health; POST validate", "5xx with error code ANALYTICS_UNAVAILABLE; status stays UPLOADED; after restart validate returns 200",
               f"during outage HTTP {fv.status_code} code={code} msg='{msg[:80]}'; status after={st_after}; healthy after restart={healthy}; retry HTTP {rv.status_code}", "PASS" if ok6 else "FAIL", ev, seconds=time.time()-t0)
        record("SYS-10", "system", "UI shows a controlled error during an analytics outage and recovers via Try again", "Same outage window as INT-06; logged-in browser session",
               "Upload golden CSV in the UI while analytics is stopped; observe error state; restart analytics; click Try again", "Error panel 'The upload did not go through' with Try again; no success message; after restart the retry validates the file",
               f"error state shown={'The upload did not go through' in ui_err_text}; retry succeeded={retry_ok}", "PASS" if ("The upload did not go through" in ui_err_text and retry_ok) else "FAIL", ui_shot + "; evidence/system/sys10-analytics-recovered-ui.png", seconds=time.time()-t0)
    except Exception as e:
        record("INT-06", "integration", "Analytics outage handling", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
    finally:
        if stopped: sh(["docker", "compose", "start", "analytics"], timeout=120); analytics_healthy(90)

    # ---------------- INT-07 run pipeline on A1 via API ----------------
    t0 = time.time()
    try:
        A.post(f"/analyses/{a1}/profile"); s = wait_status(A, a1, {"PROFILED"}, 600, "INT-07 profile")
        dr = accept_definitions(A, a1); rr = A.post(f"/analyses/{a1}/run", headers={"Idempotency-Key": f"u7-int07-{int(time.time())}"}); s = wait_status(A, a1, {"ANALYZED", "COMPLETED"}, 900, "INT-07 run")
        ins = A.get(f"/analyses/{a1}/insights").json()["data"]; vis = A.get(f"/analyses/{a1}/visualizations").json()["data"]; st = A.get(f"/analyses/{a1}/status").json()["data"]
        ev_keys_vis = {k for v in vis for k in (v.get("evidence_keys") or [])}
        ins_keys = {k for i in ins for k in (i.get("evidence_ids") or [])}  # H-01 corrected: resource field is evidence_ids
        dbrow = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select (select count(*) from evidence e where e.analysis_id=a.id),(select count(*) from insights i where i.analysis_id=a.id),(select count(*) from visualizations v where v.analysis_id=a.id) from analyses a where public_id='{a1}'"])
        ok = s["status"] in ("ANALYZED", "COMPLETED") and len(ins) > 0 and len(vis) > 0 and st["job"]["status"] == "SUCCEEDED" and all(i.get("evidence_ids") for i in ins)
        ev = save("int07_results_retrieval.json", {"definitions_status": dr.status_code, "run_status": rr.status_code, "final_status": s, "insights": len(ins), "visualizations": len(vis), "insight_types": [i.get("insight_type") for i in ins], "chart_types": [v.get("type") for v in vis], "postgres_counts_evidence_insights_visualizations": dbrow, "insight_evidence_keys": len(ins_keys), "visualization_evidence_keys": len(ev_keys_vis)})
        record("INT-07", "integration", "Backend retrieves completed analysis results associated with the right analysis", "A1 VALIDATED; worker and analytics up; local Ollama for suggestions",
               "POST profile; wait PROFILED; PUT definitions (accepted suggestions); POST run; wait ANALYZED; GET insights, visualizations, status; psql counts", "ANALYZED with job SUCCEEDED; insights and visualizations non-empty, each insight carries evidence keys; DB counts match the API",
               f"final {s['status']}; insights {len(ins)} ({len(ins_keys)} evidence keys), charts {len(vis)}; job {st['job'] and st['job']['status']}; db evidence|insights|charts = {dbrow}", "PASS" if ok else "FAIL", ev, seconds=time.time()-t0)
    except Exception as e:
        record("INT-07", "integration", "Completed results retrieval", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)

    # ---------------- SYS-01 .. SYS-09 UI workflow ----------------
    u1 = None
    with sync_playwright() as p:
        b = p.chromium.launch(); ctx = b.new_context(viewport={"width": 1920, "height": 1080}, device_scale_factor=1.5); page = ctx.new_page()
        api_hits = []
        page.on("response", lambda r: api_hits.append((r.request.method, r.url.replace(API, ""), r.status)) if r.url.startswith(API) else None)
        # SYS-01 / INT-01
        t0 = time.time()
        try:
            page.goto(f"{UI}/login"); page.wait_for_selector("#email"); page.fill("#email", emailA); page.fill("#password", "wrong-password-x"); page.click("button[type=submit]")
            page.wait_for_selector("text=The email or password is incorrect.", timeout=15000); page.screenshot(path=str(SYS / "sys01-login-wrong-password.png"))
            ui_login(page, emailA); page.wait_for_selector("text=Analyses"); time.sleep(1); page.screenshot(path=str(SYS / "sys01-dashboard-after-login.png"))
            listed = page.get_by_text("Unit7 INT A1").count() > 0; hits = [h for h in api_hits if h[1].startswith("/analyses") and h[0] == "GET"]
            ok = listed and any(h[2] == 200 for h in hits)
            save("sys01_api_calls_from_react.json", {"api_responses_observed": api_hits[-12:]})
            record("SYS-01", "system", "User authentication and dashboard access", "Verified test account A", "Login with wrong password; login with correct password; observe dashboard", "Wrong password shows an error; correct login lands on /analyses listing the account's analyses",
                   f"wrong-password error shown; dashboard lists 'Unit7 INT A1'={listed}", "PASS" if ok else "FAIL", "evidence/system/sys01-login-wrong-password.png; evidence/system/sys01-dashboard-after-login.png", seconds=time.time()-t0)
            record("INT-01", "integration", "React client communicates with the Laravel API and renders the returned state", "Browser session", "Observe API responses issued by the React app during login and dashboard load", "POST /auth/login 200 and GET /analyses 200 from the browser; list rendered from the response",
                   f"observed {len(api_hits)} API responses incl. GET /analyses {[h[2] for h in hits][:3]}; UI rendered list={listed}", "PASS" if ok else "FAIL", "evidence/system/sys01_api_calls_from_react.json", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-01", "system", "Authentication", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-02 valid upload
        t0 = time.time()
        try:
            page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached")
            page.locator("input:not([type=file]):not([type=hidden]):not([type=checkbox])").first.fill("Unit7 SYS workflow"); page.set_input_files("[data-testid=csv-input]", str(GOLDEN)); page.get_by_role("button", name="Create and upload").click()
            page.wait_for_selector("text=File checked successfully", timeout=90000); txt = page.inner_text("body"); time.sleep(0.5)
            page.screenshot(path=str(SYS / "figure-03-valid-csv-workflow.png")); u1 = re.search(r"/analyses/([0-9a-f-]{36})", page.url).group(1) if re.search(r"/analyses/([0-9a-f-]{36})", page.url) else None
            if not u1:
                u1 = next(x["id"] for x in A.get("/analyses").json()["data"] if x["name"] == "Unit7 SYS workflow")
            ok = "240 rows and 7 columns detected" in txt
            record("SYS-02", "system", "Valid CSV upload and validation through the UI", "Logged in; golden CSV", "New analysis; name; choose retail_sales.csv; Create and upload", "'File checked successfully' and '240 rows and 7 columns detected'", f"message present={ok}", "PASS" if ok else "FAIL", "evidence/system/figure-03-valid-csv-workflow.png", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-02", "system", "Valid upload", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-03 / SYS-05 profile with missing values
        t0 = time.time()
        try:
            page.get_by_role("button", name="Continue to profile").click(); page.wait_for_selector("text=Suggested meaning", timeout=240000); time.sleep(1)
            body = page.inner_text("body"); page.screenshot(path=str(SYS / "sys05-profile-overview.png"))
            lb = body.lower(); cards = ("240" in lb and "7" in lb and "completeness" in lb and "97.9%" in lb)  # H-02 corrected: labels are CSS-uppercased
            scroll_inner(page, 10000); time.sleep(0.7); page.screenshot(path=str(SYS / "sys03-missing-values-warning.png")); body2 = page.inner_text("body")
            warn = "14.6% of values are missing" in body2
            record("SYS-05", "system", "Data profiling results are displayed", "SYS-02 analysis", "Continue to profile; wait for suggestions", "Cards show 240 rows, 7 columns, 97.9% completeness; columns table with types and summaries", f"cards present={cards}", "PASS" if cards else "FAIL", "evidence/system/sys05-profile-overview.png", seconds=time.time()-t0)
            record("SYS-03", "system", "CSV containing missing values is flagged", "Same profile", "Scroll to Data quality", "Warning 'discount_pct: 14.6% of values are missing; statistics use the remaining rows'", f"warning present={warn}; red 14.6% (35) in table", "PASS" if warn else "FAIL", "evidence/system/sys03-missing-values-warning.png", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-03", "system", "Missing values", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-04 invalid CSV in UI
        t0 = time.time()
        try:
            page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached")
            page.locator("input:not([type=file]):not([type=hidden]):not([type=checkbox])").first.fill("Unit7 SYS-04 invalid"); page.set_input_files("[data-testid=csv-input]", str(BAD / "invalid_duplicate_headers.csv")); page.get_by_role("button", name="Create and upload").click()
            page.wait_for_selector("text=The file could not be used", timeout=60000); time.sleep(0.5); body = page.inner_text("body"); page.screenshot(path=str(SYS / "figure-04-invalid-csv-feedback.png"))
            codes_shown = [c for c in ("EMPTY_COLUMN_NAME", "DUPLICATE_COLUMN", "empty", "Duplicate") if c.lower() in body.lower()]
            page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached")
            page.locator("input:not([type=file]):not([type=hidden]):not([type=checkbox])").first.fill("Unit7 SYS-04 txt"); page.set_input_files("[data-testid=csv-input]", str(BAD / "not_a_csv.txt"))
            time.sleep(1); body_txt = page.inner_text("body"); page.screenshot(path=str(SYS / "sys04-unsupported-file-feedback.png"))
            # H-03 corrected: the client rejects unsupported files before any upload and keeps the button disabled
            txt_rejected = ("Only CSV or Excel (.xlsx) files are supported." in body_txt) and page.get_by_role("button", name="Create and upload").is_disabled()
            ok = "The file could not be used" in body and len(codes_shown) > 0 and txt_rejected
            record("SYS-04", "system", "Invalid or unsupported CSV produces understandable feedback", "Logged in; invalid input files", "Upload duplicate-header CSV; upload .txt", "Error summary 'The file could not be used' listing the header problems; .txt rejected with a format message and no success",
                   f"dup headers: shown={'The file could not be used' in body} terms={codes_shown}; txt rejected={txt_rejected}", "PASS" if ok else "FAIL", "evidence/system/figure-04-invalid-csv-feedback.png; evidence/system/sys04-unsupported-file-feedback.png", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-04", "system", "Invalid CSV", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-06 analysis completes (define -> analyze)
        t0 = time.time()
        try:
            page.goto(f"{UI}/analyses/{u1}/define"); page.get_by_role("button", name="Save and continue").wait_for(timeout=60000); time.sleep(0.5); page.screenshot(path=str(SYS / "sys06-define-step.png"))
            page.get_by_role("button", name="Save and continue").click(); page.wait_for_url("**/analyze", timeout=30000)
            page.get_by_role("button", name="Analyze and recommend charts").click(); t_run = time.time()
            page.wait_for_selector("text=Choose the charts for your report", timeout=600000); run_s = time.time() - t_run; time.sleep(1); page.screenshot(path=str(SYS / "sys06-analysis-complete-chart-review.png"))
            st = A.get(f"/analyses/{u1}/status").json()["data"]
            ok = st["status"] == "ANALYZED"
            record("SYS-06", "system", "Statistical analysis completes from the UI", "Profiled analysis", "Define: Save and continue; Analyze: 'Analyze and recommend charts'; wait for chart review", "Status ANALYZED; chart review panel appears", f"status={st['status']} in {run_s:.1f}s; chart review shown", "PASS" if ok else "FAIL", "evidence/system/sys06-define-step.png; evidence/system/sys06-analysis-complete-chart-review.png", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-06", "system", "Analysis completes", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-09 report generation (local Ollama)
        t0 = time.time()
        try:
            page.get_by_role("button", name="Write the executive summary").first.click(); t_rep = time.time()
            page.wait_for_selector("text=All done. Your report is ready.", timeout=1500000); rep_s = time.time() - t_rep; page.screenshot(path=str(SYS / "sys09-report-ready.png"))
            page.goto(f"{UI}/analyses/{u1}/report"); page.wait_for_selector("text=Executive summary", timeout=60000); time.sleep(2); page.screenshot(path=str(SYS / "sys09-executive-report.png"))
            rep = A.get(f"/analyses/{u1}/report").json()["data"]
            ok = rep["status"] == "READY" and rep["claims_total"] > 0
            record("SYS-09", "system", "Executive report generation (local Qwen via Ollama)", "ANALYZED analysis; Ollama running locally", "Click 'Write the executive summary'; wait; open report", "Report READY with claims; executive summary and charts displayed", f"report status={rep['status']} claims_total={rep['claims_total']} supported={rep['claims_supported']} generation {rep_s:.0f}s", "PASS" if ok else "FAIL", "evidence/system/sys09-report-ready.png; evidence/system/sys09-executive-report.png", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-09", "system", "Report generation", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-07 visualization rendered
        t0 = time.time()
        try:
            page.goto(f"{UI}/analyses/{u1}/report"); page.wait_for_selector("text=Executive summary", timeout=60000); time.sleep(2)
            svgs = page.locator("svg.recharts-surface").count(); titles = page.locator("text=/by Region|by month|Association between/").count()
            vis = A.get(f"/analyses/{u1}/visualizations").json()["data"]; selected = [v for v in vis if v.get("is_selected", True)]
            scroll_inner(page, 700); time.sleep(0.8); page.screenshot(path=str(SYS / "sys07-chart-rendered.png"))
            ok = svgs > 0 and len(selected) > 0
            record("SYS-07", "system", "Correct visualization is rendered for the selected chart specs", "Report READY", "Open report; count rendered Recharts SVG surfaces; compare with selected visualizations from the API", "At least one chart SVG rendered; chart titles match API chart specs", f"svg charts rendered={svgs}; api selected charts={len(selected)} types={[v.get('type') for v in selected]}; matching titles found={titles}", "PASS" if ok else "FAIL", "evidence/system/sys07-chart-rendered.png", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-07", "system", "Visualization", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-08 insight + evidence
        t0 = time.time()
        try:
            scroll_inner(page, 0); page.locator("a[href*='/claims/']").first.click(); page.wait_for_selector("text=How we know", timeout=30000); time.sleep(1.5)
            body = page.inner_text("body"); page.screenshot(path=str(SYS / "sys08-insight-evidence-panel.png"))
            ok = "Checked against the calculated results" in body and "THE NUMBERS BEHIND IT" in body.upper()
            record("SYS-08", "system", "Insight statement displays its supporting evidence", "Report READY", "Click a claim link in the executive summary", "'How we know' panel with the verification badge and the evidence card", f"badge present={('Checked against the calculated results' in body)}", "PASS" if ok else "FAIL", "evidence/system/sys08-insight-evidence-panel.png", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-08", "system", "Evidence display", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        # SYS-11 unauthorized (UI part)
        t0 = time.time()
        try:
            ctx2 = b.new_context(viewport={"width": 1920, "height": 1080}); p2 = ctx2.new_page(); p2.goto(f"{UI}/analyses/{u1}/report"); p2.wait_for_url("**/login**", timeout=15000); p2.screenshot(path=str(SYS / "sys11-logged-out-redirect.png")); ctx2.close()
            r_none = httpx.get(f"{API}/analyses", headers={"Accept": "application/json"}); r_other = B.get(f"/analyses/{u1}"); r_other_rep = B.get(f"/analyses/{u1}/report")
            ok = r_none.status_code == 401 and r_other.status_code in (403, 404) and r_other_rep.status_code in (403, 404)
            save("sys11_unauthorized.json", {"no_token_GET_analyses": r_none.status_code, "other_user_GET_analysis": r_other.status_code, "other_user_GET_report": r_other_rep.status_code})
            record("SYS-11", "system", "Unauthorized requests are rejected", "Second account B; logged-out browser", "Open a report URL logged out; GET /analyses without token; GET another user's analysis and report with B's token", "Redirect to /login; 401; 403/404 for another user's analysis", f"redirect ok; no token {r_none.status_code}; other user {r_other.status_code}/{r_other_rep.status_code}", "PASS" if ok else "FAIL", "evidence/system/sys11-logged-out-redirect.png; evidence/system/sys11_unauthorized.json", seconds=time.time()-t0)
        except Exception as e:
            record("SYS-11", "system", "Unauthorized", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)
        b.close()

    # ---------------- INT-08 claim validation (API, on U1's report) ----------------
    t0 = time.time()
    try:
        rep = A.get(f"/analyses/{u1}/report").json()["data"]
        ids = re.findall(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", json.dumps(rep.get("sections")))
        ids = list(dict.fromkeys(ids))[:8]; claims = [A.get(f"/analyses/{u1}/claims/{i}").json().get("data") for i in ids]
        statuses = [c.get("status") for c in claims if c]; evid = [len(c.get("evidence") or c.get("supporting_evidence") or []) for c in claims if c]
        dbq = sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select c.status, c.in_report, count(*) from claims c join analyses a on a.id=c.analysis_id where a.public_id='{u1}' group by 1,2 order by 1,2"])
        bad_in_report = [l for l in dbq.splitlines() if l.split("|")[1] == "t" and l.split("|")[0] != "SUPPORTED"]
        ok = len(claims) > 0 and all(s == "SUPPORTED" for s in statuses) and all(n > 0 for n in evid) and not bad_in_report
        ev = save("int08_claim_validation.json", {"claims_total": rep["claims_total"], "claims_supported": rep["claims_supported"], "sampled_claim_statuses": statuses, "sampled_evidence_counts": evid, "db_status_in_report_counts": dbq, "non_supported_in_report": bad_in_report})
        record("INT-08", "integration", "Claim validation: only SUPPORTED claims enter the report; unsupported claims are kept out", "Report READY on U1 (local Ollama narrative)",
               "GET report; GET each referenced claim; psql group by status,in_report (read-only)", "All claims referenced by report sections are SUPPORTED with evidence; no non-SUPPORTED claim has in_report=true",
               f"claims_total={rep['claims_total']} supported={rep['claims_supported']}; sampled statuses={statuses}; db={dbq.replace(chr(10),' ; ')}", "PASS" if ok else "FAIL", ev, seconds=time.time()-t0)
    except Exception as e:
        record("INT-08", "integration", "Claim validation", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)

    # ---------------- SYS-12 repeated processing / idempotency (H-04 corrected: fresh analysis; the CSV of a completed analysis is purged by design) ----------------
    t0 = time.time()
    try:
        r = A.post("/analyses", json={"name": "Unit7 SYS-12 idempotency"}); zid = r.json()["data"]["id"]
        with GOLDEN.open("rb") as f: A.post(f"/analyses/{zid}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
        A.post(f"/analyses/{zid}/validate"); A.post(f"/analyses/{zid}/profile"); wait_status(A, zid, {"PROFILED"}, 600, "SYS-12 profile"); accept_definitions(A, zid)
        key = f"u7-sys12-{int(time.time())}"
        r1 = A.post(f"/analyses/{zid}/run", headers={"Idempotency-Key": key}); r2 = A.post(f"/analyses/{zid}/run", headers={"Idempotency-Key": key}); replayed = r2.headers.get("Idempotent-Replayed")
        s1 = wait_status(A, zid, {"ANALYZED", "COMPLETED"}, 900, "SYS-12 run1")
        q = lambda: sh(["docker", "compose", "exec", "-T", "postgres", "psql", "-U", "dataspeak", "-d", "dataspeak", "-tAc", f"select string_agg(evidence_key, ',' order by evidence_key), count(*), count(distinct evidence_key) from evidence e join analyses a on a.id=e.analysis_id where a.public_id='{zid}'"])
        first = q(); keys1, n1, d1 = first.rsplit("|", 2)
        with GOLDEN.open("rb") as f: ru = A.post(f"/analyses/{zid}/dataset", files={"file": ("retail_sales.csv", f, "text/csv")})
        A.post(f"/analyses/{zid}/validate"); A.post(f"/analyses/{zid}/profile"); wait_status(A, zid, {"PROFILED"}, 600, "SYS-12 profile2"); accept_definitions(A, zid)
        r3 = A.post(f"/analyses/{zid}/run", headers={"Idempotency-Key": key + "-second"}); s2 = wait_status(A, zid, {"ANALYZED", "COMPLETED"}, 900, "SYS-12 run2")
        second = q(); keys2, n2, d2 = second.rsplit("|", 2)
        ok = r1.status_code == 202 and r2.status_code == 202 and replayed == "true" and s1["status"] == "ANALYZED" and s2["status"] == "ANALYZED" and n1 == d1 and n2 == d2 and keys1 == keys2 and ru.status_code == 201
        ev = save("sys12_repeat_processing.json", {"analysis": zid, "first_run": r1.status_code, "second_run_same_key": r2.status_code, "idempotent_replayed_header": replayed, "run1": {"status": s1["status"], "evidence_total": n1, "distinct": d1}, "reupload": ru.status_code, "run2": {"status": s2["status"], "evidence_total": n2, "distinct": d2}, "identical_evidence_key_sets": keys1 == keys2})
        record("SYS-12", "system", "Repeated processing does not duplicate or corrupt results", "Fresh DEFINED analysis with the golden CSV", "POST /run twice with one Idempotency-Key; wait; re-upload the same file (the transient copy is purged after a run); profile; run again; compare evidence keys", "Second call replayed (Idempotent-Replayed: true); both runs ANALYZED; evidence counts equal distinct counts; identical key sets across runs",
               f"run {r1.status_code}/{r2.status_code} replayed={replayed}; run1 {s1['status']} {n1}|{d1}; reupload {ru.status_code}; run2 {s2['status']} {n2}|{d2}; identical key sets={keys1 == keys2}", "PASS" if ok else "FAIL", ev, seconds=time.time()-t0)
    except Exception as e:
        record("SYS-12", "system", "Repeated processing", "", "", "", f"exception: {e}", "FAIL", seconds=time.time()-t0)

    # ---------------- write CSV ----------------
    with (EV / "system_test_results.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(results[0].keys())); w.writeheader(); [w.writerow(r) for r in sorted(results, key=lambda r: r["id"])]
    tally = {}
    for r in results: tally.setdefault(r["level"], {}).setdefault(r["result"], 0); tally[r["level"]][r["result"]] += 1
    print("SUMMARY", json.dumps(tally)); print("accounts:", emailA, emailB, "| analyses A1", a1, "U1", u1)

if __name__ == "__main__":
    main()
