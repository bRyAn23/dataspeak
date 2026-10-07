"""Output-validation test cases (assignment "Testing Approach" section).

Each test maps to one planned scenario: normal, boundary, and invalid-input conditions for the
CSV validator, the profiler, and the deterministic descriptive statistics.
"""

import statistics

import pytest
from app.core.errors import AnalyticsError
from app.schemas.common import FieldDefinition
from app.schemas.requests import AnalysisOptions
from app.services.analysis.engine import analyze_frame
from app.services.profiling.profiler import profile_frame
from app.services.validation.csv_reader import read_csv_bytes
from app.services.validation.validator import validate_dataset

SAMPLE = b"""order_id,order_date,region,quantity,unit_price,revenue
1001,2026-01-05,North,4,12.50,50.00
1002,2026-01-06,South,2,30.00,60.00
1003,2026-01-07,East,,12.50,
1004,2026-01-08,West,1,99.00,99.00
1005,2026-01-09,North,3,30.00,90.00
1002,2026-01-06,South,2,30.00,60.00
1006,2026-01-10,East,5,99.00,495.00
1007,2026-01-11,South,2,12.50,25.00
"""


def metric(evidence, name, cols):
    return next(e for e in evidence if e["metric"] == name and e["source_columns"] == cols)


def test_valid_dataset_is_accepted():
    result = validate_dataset(read_csv_bytes(SAMPLE))
    assert result.valid
    assert result.row_count == 8 and result.column_count == 6
    assert result.errors == []


def test_empty_dataset_is_rejected():
    with pytest.raises(AnalyticsError) as exc:
        read_csv_bytes(b"")
    assert exc.value.code == "ANALYTICS_INVALID_DATASET"


def test_dataset_without_usable_rows_is_rejected():
    result = validate_dataset(read_csv_bytes(b"order_id,region,revenue\n"))
    assert not result.valid
    assert [e.code for e in result.errors] == ["NO_DATA_ROWS"]


def test_invalid_headers_are_rejected():
    result = validate_dataset(read_csv_bytes(b"order_id,,region,region\n1,2,3,4\n"))
    assert not result.valid
    assert {e.code for e in result.errors} == {"EMPTY_COLUMN_NAME", "DUPLICATE_COLUMN"}


def test_missing_values_are_detected():
    profile = profile_frame(read_csv_bytes(SAMPLE).df)
    by_name = {c.name: c for c in profile.columns}
    assert by_name["quantity"].null_count == 1
    assert by_name["revenue"].null_count == 1
    assert sum(c.null_count for c in profile.columns) == 2
    assert profile.dataset.completeness == pytest.approx(46 / 48)


def test_duplicate_rows_are_detected():
    parsed = read_csv_bytes(SAMPLE)
    warnings = {w.code for w in validate_dataset(parsed).warnings}
    assert "DUPLICATE_ROWS" in warnings
    assert profile_frame(parsed.df).dataset.duplicate_rows == 1


def test_numeric_columns_are_recognised():
    profile = profile_frame(read_csv_bytes(SAMPLE).df)
    types = {c.name: c.inferred_type for c in profile.columns}
    assert types["quantity"] == "integer"
    assert types["unit_price"] == "numeric"
    assert types["revenue"] == "integer"  # every revenue value is a whole number
    numeric = [c.name for c in profile.columns if c.inferred_type in ("integer", "numeric")]
    assert numeric == ["order_id", "quantity", "unit_price", "revenue"]
    assert types["order_date"] == "date"
    assert types["region"] == "category"


def test_descriptive_statistics_match_reference_values():
    df = read_csv_bytes(SAMPLE).df
    evidence, summary = analyze_frame(
        df, [FieldDefinition(column="revenue", semantic_type="NUMBER", aggregation="SUM")], AnalysisOptions()
    )
    revenue = [50.0, 60.0, 99.0, 90.0, 60.0, 495.0, 25.0]  # the blank row is excluded
    assert metric(evidence, "sum", ["revenue"])["value"] == pytest.approx(sum(revenue))
    assert metric(evidence, "mean", ["revenue"])["value"] == pytest.approx(statistics.fmean(revenue))
    assert metric(evidence, "median", ["revenue"])["value"] == pytest.approx(statistics.median(revenue))
    assert metric(evidence, "min", ["revenue"])["value"] == 25.0
    assert metric(evidence, "max", ["revenue"])["value"] == 495.0
    assert "descriptive" in summary["families_run"]
