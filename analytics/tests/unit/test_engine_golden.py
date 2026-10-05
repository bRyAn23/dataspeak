import json
import statistics
from pathlib import Path

import pytest
from app.schemas.common import Evidence, FieldDefinition
from app.schemas.requests import AnalysisOptions, InsightIn
from app.services.analysis.engine import analyze_frame
from app.services.insights.detector import detect
from app.services.insights.wording import CAUSAL_WORDS
from app.services.validation.csv_reader import read_csv_bytes
from app.services.visualization.recommender import recommend

GOLDEN = Path(__file__).resolve().parents[1] / "golden"
RETAIL_DEFS = [
    FieldDefinition(column="order_date", business_name="Order Date", semantic_type="DATE"),
    FieldDefinition(column="region", business_name="Sales Region", semantic_type="CATEGORY"),
    FieldDefinition(column="product_category", business_name="Product Category", semantic_type="CATEGORY"),
    FieldDefinition(
        column="ยอดขาย_thb", business_name="Revenue", semantic_type="CURRENCY", unit="THB", aggregation="SUM"
    ),
    FieldDefinition(column="ရောင်းအား_units", business_name="Units", semantic_type="NUMBER", aggregation="SUM"),
    FieldDefinition(column="discount_pct", business_name="Discount", semantic_type="PERCENTAGE", aggregation="AVERAGE"),
    FieldDefinition(column="customer_id", business_name="Customer", semantic_type="IDENTIFIER"),
]


def find(evidence, metric, cols=None, method=None):
    for e in evidence:
        if (
            e["metric"] == metric
            and (cols is None or e["source_columns"] == cols)
            and (method is None or e["method"] == method)
        ):
            return e
    return None


def test_tiny_golden_hand_verified_values():
    df = read_csv_bytes((GOLDEN / "tiny.csv").read_bytes()).df
    evidence, summary = analyze_frame(
        df, [FieldDefinition(column="amount", semantic_type="NUMBER", aggregation="SUM")], AnalysisOptions()
    )
    assert find(evidence, "row_count")["value"] == 6
    assert find(evidence, "mean", ["amount"])["value"] == 30
    assert find(evidence, "median", ["amount"])["value"] == 30
    assert find(evidence, "min", ["amount"])["value"] == 10
    assert find(evidence, "max", ["amount"])["value"] == 50
    assert find(evidence, "sum", ["amount"])["value"] == 180
    groups = find(evidence, "group_aggregates", ["region", "amount"])
    by = {g["group"]: g for g in groups["payload"]["groups"]}
    assert by["N"]["mean"] == 20 and by["S"]["mean"] == 40 and by["S"]["sum"] == 120
    diff = find(evidence, "top_bottom_mean_difference", ["region", "amount"])
    assert diff["value"] == 1.0  # (40-20)/20
    test = find(evidence, "group_difference_test", ["region", "amount"])
    assert test["method"] == "none_applicable" and test["quality"]["status"] == "insufficient"  # 3 rows per group
    assert find(evidence, "period_series", ["order_date", "amount"])["parameters"]["period"] == "month"
    assert "descriptive" in summary["families_run"] and summary["evidence_count"] == len(evidence)
    for e in evidence:
        Evidence.model_validate(e)  # every record satisfies the contract


def test_retail_golden_cross_checked_with_statistics_module():
    raw = (GOLDEN / "retail_sales.csv").read_bytes()
    df = read_csv_bytes(raw).df
    evidence, summary = analyze_frame(df, RETAIL_DEFS, AnalysisOptions())
    revenue = [float(x) for x in df["ยอดขาย_thb"].to_list()]
    assert find(evidence, "mean", ["ยอดขาย_thb"])["value"] == pytest.approx(statistics.fmean(revenue), rel=1e-9)
    assert find(evidence, "median", ["ยอดขาย_thb"])["value"] == pytest.approx(statistics.median(revenue), rel=1e-9)
    assert find(evidence, "std", ["ยอดขาย_thb"])["value"] == pytest.approx(statistics.stdev(revenue), rel=1e-9)
    assert find(evidence, "sum", ["ยอดขาย_thb"])["display_value"].endswith("THB")

    north = [float(r) for r, g in zip(df["ยอดขาย_thb"].to_list(), df["region"].to_list()) if g == "North"]
    groups = find(evidence, "group_aggregates", ["region", "ยอดขาย_thb"])
    by = {g["group"]: g for g in groups["payload"]["groups"]}
    assert by["North"]["mean"] == pytest.approx(statistics.fmean(north), rel=1e-9)
    assert by["North"]["count"] == len(north)
    share = find(evidence, "top_group_share", ["region", "ยอดขาย_thb"])
    assert share["payload"]["top_group"] == "North" and share["value"] == pytest.approx(sum(north) / sum(revenue))

    test = find(evidence, "group_difference_test", ["region", "ยอดขาย_thb"])
    assert test["method"] in ("one_way_anova", "kruskal_wallis") and test["value"] < 0.05

    series = find(evidence, "period_series", ["order_date", "ยอดขาย_thb"])
    assert series["parameters"]["period"] == "month" and len(series["payload"]["series"]) == 12
    slope = find(evidence, "trend_slope", ["order_date", "ยอดขาย_thb"])
    assert slope["value"] > 0 and slope["payload"]["n_periods"] == 12

    null_rate = find(evidence, "null_rate", ["discount_pct"])
    expected = df["discount_pct"].null_count() / df.height
    assert null_rate["value"] == pytest.approx(expected) and null_rate["quality"]["status"] == "warning"

    # identifiers never appear in evidence
    dumped = json.dumps(evidence, ensure_ascii=False)
    assert "C01000" not in dumped and "customer_id" not in dumped
    assert "statistical_test" in summary["families_run"]


def test_determinism_same_input_same_evidence():
    raw = (GOLDEN / "retail_sales.csv").read_bytes()
    a, _ = analyze_frame(read_csv_bytes(raw).df, RETAIL_DEFS, AnalysisOptions())
    b, _ = analyze_frame(read_csv_bytes(raw).df, RETAIL_DEFS, AnalysisOptions())
    assert json.dumps(a, sort_keys=True) == json.dumps(b, sort_keys=True)
    ids = [e["evidence_id"] for e in a]
    assert len(ids) == len(set(ids))


def test_insights_reference_evidence_and_avoid_causal_language():
    raw = (GOLDEN / "retail_sales.csv").read_bytes()
    evidence, _ = analyze_frame(read_csv_bytes(raw).df, RETAIL_DEFS, AnalysisOptions())
    models = [Evidence.model_validate(e) for e in evidence]
    insights = detect(models, RETAIL_DEFS, 10)
    assert 1 <= len(insights) <= 10
    ids = {e.evidence_id for e in models}
    for i in insights:
        assert i["primary_evidence_id"] in ids and all(x in ids for x in i["evidence_ids"])
        text = (i["headline"] + " " + i["description"]).lower()
        assert not any(w in text for w in CAUSAL_WORDS), text
    assert [i["rank"] for i in insights] == list(range(1, len(insights) + 1))
    assert insights == sorted(insights, key=lambda i: -i["score"])
    types = {i["insight_type"] for i in insights}
    assert "concentration" in types or "group_difference" in types
    assert "data_quality" in types  # 15% missing discounts
    # Business labels from definitions are used, not raw column names
    assert any("Revenue" in i["headline"] for i in insights)


def test_visualizations_use_units_from_definitions_and_flag_missing_units():
    raw = (GOLDEN / "retail_sales.csv").read_bytes()
    evidence, _ = analyze_frame(read_csv_bytes(raw).df, RETAIL_DEFS, AnalysisOptions())
    models = [Evidence.model_validate(e) for e in evidence]
    insights = [InsightIn(**i) for i in detect(models, RETAIL_DEFS, 10)]
    charts = recommend(models, insights, RETAIL_DEFS)
    assert charts and all(c["evidence_ids"] for c in charts)
    revenue_charts = [c for c in charts if c["y"]["label"] == "Revenue"]
    assert revenue_charts and all(c["y"]["unit"] == "THB" and not c["unit_missing"] for c in revenue_charts)
    unit_charts = [c for c in charts if c["y"]["label"] == "Units"]
    assert all(c["unit_missing"] for c in unit_charts)
    line = next(c for c in charts if c["type"] == "line")
    assert len(line["series"]) == 12 and set(line["series"][0]) == {"period", "value"}
    # no raw rows: a chart never has as many points as the dataset
    assert all(len(c["series"]) < 240 for c in charts)
    dumped = json.dumps(charts, ensure_ascii=False)
    assert "C01000" not in dumped
