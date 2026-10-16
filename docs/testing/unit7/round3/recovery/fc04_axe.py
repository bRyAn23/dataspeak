"""FC-04 accessibility re-run (recovery): axe-core (WCAG 2.0/2.1 A+AA tags) on new-analysis, profile and report pages. The first run
crashed on a Playwright strict-mode violation: get_by_label('Password') matched both the password input and the 'Show password'
button; the login now targets the textbox role. Output under round3/recovery/evidence/."""
import json, os, time
from datetime import datetime
from pathlib import Path
import httpx
from playwright.sync_api import sync_playwright
ROOT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak"); OUT = ROOT / "docs/testing/unit7/round3/recovery/evidence/accessibility"; OUT.mkdir(parents=True, exist_ok=True)
API = "http://localhost:8000/api/v1"; UI = "http://localhost:5173"; PASSWORD = "Unit7Test#2026x"; AXE = ROOT / "frontend/node_modules/axe-core/axe.min.js"
SP = Path(os.environ["U7_SCRATCH"]); emailA = (SP / "unit7_accounts.txt").read_text().split()[0]
rep_aid = os.environ.get("FC04_ANALYSIS", "aa06f5fe-436f-4cc9-986e-48af3cf8a623")  # R3-AI-06 recovery report: READY, 23 claims
prof_aid = os.environ.get("FC04_PROFILE_ANALYSIS", "f3e5bd68-c690-4326-9d4b-e48c5bef2398")  # "Unit7 INT A1", PROFILED: the profile route of a completed analysis forwards to its furthest step
def axe(page):
    page.add_script_tag(path=str(AXE)); return page.evaluate("async () => { const r = await axe.run(document, {runOnly: {type: 'tag', values: ['wcag2a','wcag2aa','wcag21a','wcag21aa']}}); return r.violations.map(v => ({id: v.id, impact: v.impact, nodes: v.nodes.length, help: v.help})); }")
with sync_playwright() as p:
    b = p.chromium.launch(); page = b.new_page(viewport={"width": 1440, "height": 1080})
    page.goto(f"{UI}/login"); page.wait_for_selector("#email"); page.fill("#email", emailA); page.fill("#password", PASSWORD); page.click("button[type=submit]"); page.wait_for_url("**/analyses**", timeout=30000); time.sleep(1)
    v_login_after = None
    page.goto(f"{UI}/analyses/new"); page.wait_for_selector("[data-testid=csv-input]", state="attached"); time.sleep(1); v_new = axe(page); page.screenshot(path=str(OUT / "fc04_new_analysis.png"))
    page.goto(f"{UI}/analyses/{prof_aid}/profile"); page.wait_for_selector("text=Suggested meaning", timeout=120000); time.sleep(1.5); v_prof = axe(page); page.screenshot(path=str(OUT / "fc04_profile.png"))
    page.goto(f"{UI}/analyses/{rep_aid}/report"); page.wait_for_selector("text=Executive summary", timeout=60000); time.sleep(2); v_rep = axe(page); page.screenshot(path=str(OUT / "fc04_report.png"), full_page=True)
    b.close()
sev = {k: [x for x in v if x["impact"] in ("serious", "critical")] for k, v in (("new_analysis", v_new), ("profile", v_prof), ("report", v_rep))}
out = {"executed": datetime.now().astimezone().isoformat(timespec="seconds"), "report_analysis": rep_aid, "profile_analysis": prof_aid, "violations": {"new_analysis": v_new, "profile": v_prof, "report": v_rep}, "serious_or_critical": {k: len(v) for k, v in sev.items()}, "pass": sum(len(v) for v in sev.values()) == 0}
(OUT / "fc04_axe_results.json").write_text(json.dumps(out, indent=2)); print(json.dumps(out)[:900]); print("[PASS]" if out["pass"] else "[FAIL]", "FC-04")
