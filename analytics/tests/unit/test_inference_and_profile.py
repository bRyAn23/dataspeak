import polars as pl
from app.services.profiling.inference import infer_column
from app.services.profiling.profiler import profile_frame
from app.services.validation.csv_reader import read_csv_bytes


def col(values, name="c"):
    return infer_column(name, 0, pl.Series(name, values, dtype=pl.Utf8))


def test_numeric_with_thousands_and_currency_symbols():
    c = col(["1,200", "฿3,400.5", "500", None])
    assert c.inferred_type == "numeric" and c.null_count == 1
    assert c.series.drop_nulls().to_list() == [1200.0, 3400.5, 500.0]


def test_integer_boolean_date_datetime_category_identifier_text():
    assert col(["1", "2", "3", "4"]).inferred_type == "integer"
    assert col(["yes", "no", "yes", None]).inferred_type == "boolean"
    assert col(["2025-01-01", "2025-02-01", "2025-03-01"]).inferred_type == "date"
    assert col(["01/02/2025", "15/03/2025", "31/12/2025"]).inferred_type == "date"
    assert col(["2025-01-01T10:00:00", "2025-01-02T11:30:00"]).inferred_type == "datetime"
    assert col(["North", "South", "North", "East"] * 5).inferred_type == "category"
    assert col([f"C{i:05d}" for i in range(50)]).inferred_type == "identifier"
    assert col([f"free text number {i} with words" for i in range(10)]).inferred_type == "text"


def test_mixed_numeric_column_counts_parse_failures():
    c = col([str(i) for i in range(40)] + ["abc"])
    assert c.inferred_type in ("numeric", "integer") and c.parse_failures == 1


def test_profile_returns_derived_metadata_only_and_no_category_labels():
    raw = (
        "region,amount,note\n" + "\n".join(f"{'N' if i % 2 else 'S'},{i * 10},secret-{i}" for i in range(20)) + "\n"
    ).encode()
    profile = profile_frame(read_csv_bytes(raw).df)
    assert profile.dataset.rows == 20 and profile.dataset.columns == 3
    region = next(c for c in profile.columns if c.name == "region")
    assert region.inferred_type == "category"
    assert set(region.stats) == {"top_share", "top_count"}  # counts only, no labels
    dumped = profile.model_dump_json()
    assert "secret-" not in dumped and "N" not in region.stats.values()
    amount = next(c for c in profile.columns if c.name == "amount")
    assert amount.stats["min"] == 0 and amount.stats["max"] == 190 and amount.stats["median"] == 95


def test_profile_quality_findings():
    raw = ("a,b,c\n" + "\n".join(f"{i},{'' if i < 18 else 'x'},k" for i in range(20)) + "\n").encode()
    profile = profile_frame(read_csv_bytes(raw).df)
    codes = {(f.code, f.column) for f in profile.quality_findings}
    assert ("MOSTLY_EMPTY_COLUMN", "b") in codes
    assert ("CONSTANT_COLUMN", "c") in codes
