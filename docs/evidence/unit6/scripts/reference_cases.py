"""Reproduce every reference-value assertion of the statistical accuracy tests and record
expected value, actual value, tolerance and outcome. Expected values come ONLY from the test
files (hand-verified constants) or from Python's statistics module on the golden CSV, exactly as
the tests derive them. Nothing here changes the tests or the engine."""
import csv, json, math, statistics, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4] / "analytics"
sys.path.insert(0, str(ROOT))
from app.schemas.common import FieldDefinition
from app.schemas.requests import AnalysisOptions
from app.services.analysis.engine import analyze_frame
from app.services.validation.csv_reader import read_csv_bytes
from tests.unit.test_engine_golden import RETAIL_DEFS, find
from tests.unit.test_output_validation import SAMPLE, metric

rows = []
def approx_ok(actual, expected, rel, abs_=1e-12):
    return math.isclose(float(actual), float(expected), rel_tol=rel, abs_tol=abs_)

def rec(test, dataset, calc, lib, expected, actual, tol, kind, ok):
    rows.append({"test": test, "dataset": dataset, "calculation": calc, "library": lib,
                 "expected_value": expected, "actual_value": actual, "tolerance": tol,
                 "case_type": kind, "outcome": "PASS" if ok else "FAIL"})

# ---- tiny.csv: hand-verified constants (tests/unit/test_engine_golden.py::test_tiny_golden_hand_verified_values)
T = "test_tiny_golden_hand_verified_values"; D = "tiny.csv"
df = read_csv_bytes((ROOT / "tests/golden/tiny.csv").read_bytes()).df
ev, summary = analyze_frame(df, [FieldDefinition(column="amount", semantic_type="NUMBER", aggregation="SUM")], AnalysisOptions())
for m, exp in [("row_count", 6)]:
    a = find(ev, m)["value"]; rec(T, D, m, "Polars", exp, a, "exact (==)", "numeric_reference", a == exp)
for m, exp in [("mean", 30), ("median", 30), ("min", 10), ("max", 50), ("sum", 180)]:
    a = find(ev, m, ["amount"])["value"]; rec(T, D, f"{m}(amount)", "Polars/NumPy", exp, a, "exact (==)", "numeric_reference", a == exp)
by = {g["group"]: g for g in find(ev, "group_aggregates", ["region", "amount"])["payload"]["groups"]}
for g, k, exp in [("N", "mean", 20), ("S", "mean", 40), ("S", "sum", 120)]:
    a = by[g][k]; rec(T, D, f"group {k}(amount | region={g})", "Polars", exp, a, "exact (==)", "numeric_reference", a == exp)
a = find(ev, "top_bottom_mean_difference", ["region", "amount"])["value"]
rec(T, D, "top_bottom_mean_difference = (40-20)/20", "NumPy", 1.0, a, "exact (==)", "numeric_reference", a == 1.0)
t = find(ev, "group_difference_test", ["region", "amount"])
rec(T, D, "group test selection with 3 rows per group", "SciPy (selection rule)", "none_applicable / insufficient", f'{t["method"]} / {t["quality"]["status"]}', "exact match", "structural_reference", t["method"] == "none_applicable" and t["quality"]["status"] == "insufficient")
p = find(ev, "period_series", ["order_date", "amount"])["parameters"]["period"]
rec(T, D, "trend granularity for 6 monthly dates", "rules.choose_granularity", "month", p, "exact match", "structural_reference", p == "month")

# ---- retail_sales.csv: cross-checked with the statistics module (same test file)
T = "test_retail_golden_cross_checked_with_statistics_module"; D = "retail_sales.csv"
df = read_csv_bytes((ROOT / "tests/golden/retail_sales.csv").read_bytes()).df
ev, summary = analyze_frame(df, RETAIL_DEFS, AnalysisOptions())
revenue = [float(x) for x in df["ยอดขาย_thb"].to_list()]
col = ["ยอดขาย_thb"]
for m, exp, lib in [("mean", statistics.fmean(revenue), "Polars vs statistics.fmean"), ("median", statistics.median(revenue), "Polars vs statistics.median"), ("std", statistics.stdev(revenue), "Polars vs statistics.stdev")]:
    a = find(ev, m, col)["value"]; rec(T, D, f"{m}(revenue THB), n={len(revenue)}", lib, exp, a, "rel 1e-9", "numeric_reference", approx_ok(a, exp, 1e-9))
dv = find(ev, "sum", col)["display_value"]
rec(T, D, "sum display value carries unit", "formatting", "ends with 'THB'", dv, "string suffix", "structural_reference", dv.endswith("THB"))
north = [float(r) for r, g in zip(df["ยอดขาย_thb"].to_list(), df["region"].to_list()) if g == "North"]
by = {g["group"]: g for g in find(ev, "group_aggregates", ["region", "ยอดขาย_thb"])["payload"]["groups"]}
rec(T, D, "group mean(revenue | region=North)", "Polars vs statistics.fmean", statistics.fmean(north), by["North"]["mean"], "rel 1e-9", "numeric_reference", approx_ok(by["North"]["mean"], statistics.fmean(north), 1e-9))
rec(T, D, "group count(region=North)", "Polars vs len()", len(north), by["North"]["count"], "exact (==)", "numeric_reference", by["North"]["count"] == len(north))
share = find(ev, "top_group_share", ["region", "ยอดขาย_thb"])
exp = sum(north) / sum(revenue)
rec(T, D, "top_group_share = sum(North)/sum(all)", "Polars vs Python sum()", exp, share["value"], "rel 1e-6 (pytest.approx default)", "numeric_reference", approx_ok(share["value"], exp, 1e-6))
rec(T, D, "top group identity", "Polars", "North", share["payload"]["top_group"], "exact match", "structural_reference", share["payload"]["top_group"] == "North")
t = find(ev, "group_difference_test", ["region", "ยอดขาย_thb"])
rec(T, D, "group difference test method (4 regions, n>=30)", "SciPy", "one_way_anova or kruskal_wallis", t["method"], "set membership", "structural_reference", t["method"] in ("one_way_anova", "kruskal_wallis"))
rec(T, D, "group difference test p-value", "SciPy", "< 0.05", t["value"], "threshold", "numeric_reference", t["value"] < 0.05)
s = find(ev, "period_series", ["order_date", "ยอดขาย_thb"])
rec(T, D, "trend granularity (240 daily rows over 2025)", "rules.choose_granularity", "month", s["parameters"]["period"], "exact match", "structural_reference", s["parameters"]["period"] == "month")
rec(T, D, "number of periods in series", "Polars", 12, len(s["payload"]["series"]), "exact (==)", "numeric_reference", len(s["payload"]["series"]) == 12)
sl = find(ev, "trend_slope", ["order_date", "ยอดขาย_thb"])
rec(T, D, "trend slope sign (growth baked into data)", "statsmodels OLS", "> 0", sl["value"], "sign", "numeric_reference", sl["value"] > 0)
rec(T, D, "trend slope n_periods", "statsmodels OLS", 12, sl["payload"]["n_periods"], "exact (==)", "numeric_reference", sl["payload"]["n_periods"] == 12)
nr = find(ev, "null_rate", ["discount_pct"])
exp = df["discount_pct"].null_count() / df.height
rec(T, D, "null_rate(discount_pct)", "Polars vs null_count/height", exp, nr["value"], "rel 1e-6 (pytest.approx default)", "numeric_reference", approx_ok(nr["value"], exp, 1e-6))
rec(T, D, "null_rate quality flag at 15% missing", "rules.HIGH_NULL_WARN", "warning", nr["quality"]["status"], "exact match", "structural_reference", nr["quality"]["status"] == "warning")

# ---- eight-row sample: statistics module (tests/unit/test_output_validation.py::test_descriptive_statistics_match_reference_values)
T = "test_descriptive_statistics_match_reference_values"; D = "SAMPLE (8-row CSV in test file)"
df = read_csv_bytes(SAMPLE).df
ev, summary = analyze_frame(df, [FieldDefinition(column="revenue", semantic_type="NUMBER", aggregation="SUM")], AnalysisOptions())
rev = [50.0, 60.0, 99.0, 90.0, 60.0, 495.0, 25.0]
for m, exp, tol, lib in [("sum", sum(rev), 1e-6, "Polars vs Python sum()"), ("mean", statistics.fmean(rev), 1e-6, "Polars vs statistics.fmean"), ("median", statistics.median(rev), 1e-6, "Polars vs statistics.median")]:
    a = metric(ev, m, ["revenue"])["value"]; rec(T, D, f"{m}(revenue), n=7 non-null", lib, exp, a, "rel 1e-6 (pytest.approx default)", "numeric_reference", approx_ok(a, exp, tol))
for m, exp in [("min", 25.0), ("max", 495.0)]:
    a = metric(ev, m, ["revenue"])["value"]; rec(T, D, f"{m}(revenue)", "Polars", exp, a, "exact (==)", "numeric_reference", a == exp)

out = Path(__file__).resolve().parents[1] / "02_statistical_accuracy.csv"
with out.open("w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=["case_id", *rows[0].keys()])
    w.writeheader()
    for i, r in enumerate(rows, 1):
        w.writerow({"case_id": f"SA-{i:02d}", **r})
total = len(rows); passed = sum(r["outcome"] == "PASS" for r in rows)
num = [r for r in rows if r["case_type"] == "numeric_reference"]; num_pass = sum(r["outcome"] == "PASS" for r in num)
print(json.dumps({"total": total, "passed": passed, "pass_rate": round(100 * passed / total, 2), "numeric_total": len(num), "numeric_passed": num_pass, "numeric_rate": round(100 * num_pass / len(num), 2)}))
for i, r in enumerate(rows, 1):
    print(f'SA-{i:02d} {r["outcome"]} {r["test"][:30]:30} {r["calculation"][:48]:48} exp={r["expected_value"]} act={r["actual_value"]} tol={r["tolerance"]}')
