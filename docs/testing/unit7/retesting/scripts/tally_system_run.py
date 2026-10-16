"""Tally the post-fix harness run and fill the placeholders in the retesting documents."""
import json, re, sys
from pathlib import Path
RT = Path("/Users/zeyarwint/Documents/MSIT/dataspeak/docs/testing/unit7/retesting")
d = json.loads((RT / "evidence/system-run/system_test_results.json").read_text()); res = d["results"]
by = {}
for r in res: by.setdefault(r["level"], {"executed": 0, "PASS": 0, "FAIL": 0}); by[r["level"]]["executed"] += 1; by[r["level"]][r["result"]] = by[r["level"]].get(r["result"], 0) + 1
ex = sum(v["executed"] for v in by.values()); ps = sum(v["PASS"] for v in by.values()); fl = sum(v["FAIL"] for v in by.values())
fails = [r["id"] for r in res if r["result"] == "FAIL"]
(RT / "evidence/system-run/tally.json").write_text(json.dumps({"run_started": d["run_started"], "by_level": by, "executed": ex, "passed": ps, "failed": fl, "failed_ids": fails}, indent=2))
sys09 = next((r for r in res if r["id"] == "SYS-09"), None); int08 = next((r for r in res if r["id"] == "INT-08"), None)
proc = (f"Finally the complete Unit 7 integration and system harness (INT-01 to INT-08, SYS-01 to SYS-12) was re-executed against the corrected build with its known harness issues corrected, writing to a separate evidence directory; it started at {d['run_started'][11:16]} and produced {ex} recorded cases.")
result = (f"The full harness re-run recorded {ex} cases: {ps} passed and {fl} failed" + (f" ({', '.join(fails)})" if fails else "") + f"; the report generated during the run ({sys09['actual'] if sys09 else ''}) and the claim-validation check ({int08['actual'][:120] if int08 else ''}) confirm the corrected validator in an end-to-end context.")
for f, m in {"unit7-part-a-defect-resolution-summary.md": {"{{HARNESS_PROC}}": proc, "{{HARNESS_RESULT}}": result},
             "final-test-summary.md": {"{{H_EXEC}}": str(ex), "{{H_PASS}}": str(ps), "{{H_FAIL}}": (str(fl) + (f" ({', '.join(fails)})" if fails else ""))}}.items():
    p = RT / f; s = p.read_text()
    for k, v in m.items(): s = s.replace(k, v)
    p.write_text(s)
print(json.dumps({"by_level": by, "executed": ex, "passed": ps, "failed": fl, "failed_ids": fails}, indent=1))
