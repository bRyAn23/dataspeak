"""Build test-cases.csv, requirements-traceability.md and the execution totals from the recorded results.
Inputs: evidence/system_test_results.json (INT/SYS/retests), evidence/regression/*.log, performance-results.csv.
Only recorded executions are counted; nothing is inferred."""
from __future__ import annotations
import csv, json, re
from pathlib import Path
U7 = Path("/Users/zeyarwint/Documents/MSIT/dataspeak/docs/testing/unit7"); EV = U7 / "evidence"
res = json.loads((EV / "system_test_results.json").read_text())["results"]
rows = []
for r in res:
    rows.append({"test_id": r["id"], "level": r["level"], "objective": r["objective"], "preconditions": r["preconditions"], "steps": r["steps"], "expected": r["expected"], "actual": r["actual"], "result": r["result"], "evidence": r["evidence"], "defect": r["defect"], "duration_s": r["duration_s"], "executed_at": r["executed_at"], "real_or_mocked": "real services"})
# regression suites from logs
def tail(p): return (EV / "regression" / p).read_text(errors="ignore")
g = tail("pytest_golden_verbose.log"); m = re.search(r"(\d+) passed", g)
rows.append({"test_id": "REG-01", "level": "regression", "objective": "Golden-reference and statistical tests (pytest, 3 files)", "preconditions": "analytics venv", "steps": "pytest tests/unit/test_engine_golden.py tests/unit/test_output_validation.py tests/unit/test_rules_and_selection.py -v", "expected": "17 passed; 33 reference assertions hold", "actual": re.search(r"=+ .* =+\s*$", g.strip()).group(0) if re.search(r"=+ .* =+\s*$", g.strip()) else g[-120:], "result": "PASS" if m and "failed" not in g else "FAIL", "evidence": "evidence/regression/figure-05-statistical-validation.png; evidence/regression/pytest_golden_verbose.log", "defect": "", "duration_s": None, "executed_at": re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", g).group(1) if re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", g) else "", "real_or_mocked": "real computation"})
f = tail("pytest_full_coverage.log"); tot = re.search(r"^TOTAL\s+(\d+)\s+(\d+)\s+(\d+)%", f, re.M); pm = re.search(r"(\d+) passed", f)
rows.append({"test_id": "REG-02", "level": "regression", "objective": "Analytics full suite with line coverage", "preconditions": "analytics venv, pytest-cov", "steps": "pytest --cov=app --cov-report=term-missing -q", "expected": "All tests pass; coverage reported", "actual": f"{pm.group(0) if pm else '?'}; TOTAL {tot.group(1)} statements, {tot.group(2)} missed, {tot.group(3)}% line coverage" if tot else f[-160:], "result": "PASS" if pm and "failed" not in f else "FAIL", "evidence": "evidence/regression/pytest-full-coverage.png; evidence/regression/pytest_full_coverage.log", "defect": "", "duration_s": None, "executed_at": re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", f).group(1) if re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", f) else "", "real_or_mocked": "real computation; endpoint tests use TestClient"})
b = re.sub(r"\x1b\[[0-9;]*m", "", tail("phpunit_backend.log")); bm = re.search(r"Tests:\s+(\d+) passed \((\d+) assertions\)", b); bf = re.search(r"(\d+) failed", b)
rows.append({"test_id": "REG-03", "level": "regression", "objective": "Backend PHPUnit feature and unit suite", "preconditions": "PHP 8.4, composer deps; in-memory SQLite", "steps": "php artisan test --compact", "expected": "All tests pass", "actual": (bm.group(0) if bm else b[-160:]) + (f"; {bf.group(0)}" if bf else ""), "result": "PASS" if bm and not bf else "FAIL", "evidence": "evidence/regression/phpunit-backend.png; evidence/regression/phpunit_backend.log", "defect": "", "duration_s": None, "executed_at": re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", b).group(1) if re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", b) else "", "real_or_mocked": "mocked FastAPI and LLM (fakes), real Laravel"})
v = tail("vitest_frontend.log"); vm = re.search(r"Tests\s+(\d+) passed \((\d+)\)", v); vf = re.search(r"(\d+) failed", v)
rows.append({"test_id": "REG-04", "level": "regression", "objective": "Frontend Vitest suite", "preconditions": "Node 24, node_modules", "steps": "npx vitest run", "expected": "All tests pass", "actual": (vm.group(0) if vm else v[-160:]) + (f"; {vf.group(0)}" if vf else ""), "result": "PASS" if vm and not vf else "FAIL", "evidence": "evidence/regression/vitest-frontend.png; evidence/regression/vitest_frontend.log", "defect": "", "duration_s": None, "executed_at": re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", v).group(1) if re.search(r"\((\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)", v) else "", "real_or_mocked": "mocked API (MSW)"})
# performance scenarios
if (U7 / "performance-results.csv").exists():
    perf = list(csv.DictReader((U7 / "performance-results.csv").open()))
    raw = json.loads((EV / "performance/performance_raw.json").read_text()); nerr = len(raw.get("errors", []))
    for scen in sorted({p["scenario"] for p in perf}):
        sub = [p for p in perf if p["scenario"] == scen]
        rows.append({"test_id": f"PERF-{scen.split()[0][1]}", "level": "performance", "objective": scen, "preconditions": "local stack; synthetic datasets 100/1,000/10,000 rows; sequential single client", "steps": "docs/testing/unit7/scripts/performance_tests.py", "expected": "Measurements recorded; HTTP errors counted (no predefined threshold)", "actual": "; ".join(f"{p['dataset']} {p['metric']} median {p['median']}{p['unit']} p95 {p['p95']}{p['unit']} (n={p['runs']})" for p in sub if p["metric"] != "evidence_records")[:600], "result": "MEASURED" if nerr == 0 else "MEASURED (errors)", "evidence": "performance-results.csv; evidence/performance/performance_raw.json", "defect": "", "duration_s": None, "executed_at": raw.get("started", ""), "real_or_mocked": "real services"})
with (U7 / "test-cases.csv").open("w", newline="", encoding="utf-8") as fh:
    w = csv.DictWriter(fh, fieldnames=list(rows[0].keys())); w.writeheader(); [w.writerow(r) for r in sorted(rows, key=lambda r: (r["level"], r["test_id"]))]
# totals: a retest supersedes its first run for the executed/passed summary, but both are listed
final = {}
for r in rows:
    base = r["test_id"].rstrip("R")
    if r["test_id"].endswith("R") and r["level"] in ("integration", "system"): final[base] = r
    elif base not in final: final[base] = r
tally = {}
for r in final.values():
    lv = r["level"]; tally.setdefault(lv, {"executed": 0, "PASS": 0, "FAIL": 0, "BLOCKED": 0, "SKIPPED": 0, "MEASURED": 0})
    tally[lv]["executed"] += 1; key = r["result"].split()[0]; tally[lv][key] = tally[lv].get(key, 0) + 1
first_run_fails = [r["test_id"] for r in rows if r["result"] == "FAIL" and not r["test_id"].endswith("R")]
(EV / "execution_totals.json").write_text(json.dumps({"tally_after_retests": tally, "first_run_failures": first_run_fails, "retests": [r["test_id"] for r in rows if r["test_id"].endswith("R")], "cases_total_listed": len(rows)}, indent=2))
print(json.dumps(tally, indent=1)); print("first-run failures:", first_run_fails)
