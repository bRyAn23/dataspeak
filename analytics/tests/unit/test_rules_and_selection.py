import numpy as np
import polars as pl
from app.schemas.common import FieldDefinition
from app.services.analysis import rules
from app.services.analysis.groups import choose_test
from app.services.analysis.roles import resolve_roles
from app.services.analysis.trend import choose_granularity
from app.services.profiling.inference import infer_frame


def test_rule_constants_are_sane():
    assert abs(rules.W_MAGNITUDE + rules.W_SUPPORT + rules.W_QUALITY - 1.0) < 1e-9
    assert rules.MIN_PERIODS_TREND <= rules.MIN_PERIODS_SLOPE <= rules.MAX_PERIODS
    assert rules.MANN_WHITNEY_MIN_N < rules.WELCH_MIN_N
    assert 0 < rules.P_VALUE_STRONG < rules.P_VALUE_MAX < 1


def test_test_selection_follows_sample_sizes():
    rng = np.random.default_rng(rules.SEED)
    big_a, big_b = rng.normal(0, 1, 40), rng.normal(1, 1, 40)
    assert choose_test([big_a, big_b])["test"] == "welch_t_test"
    small_a, small_b = rng.normal(0, 1, 10), rng.normal(1, 1, 10)
    assert choose_test([small_a, small_b])["test"] == "mann_whitney_u"
    assert choose_test([rng.normal(0, 1, 4), rng.normal(1, 1, 4)]) is None
    three_big = [rng.normal(i, 1, 35) for i in range(3)]
    assert choose_test(three_big)["test"] in ("one_way_anova", "kruskal_wallis")
    three_small = [rng.normal(i, 1, 10) for i in range(3)]
    assert choose_test(three_small)["test"] == "kruskal_wallis"


def test_granularity_selection_follows_business_preference():
    days = pl.date_range(pl.date(2025, 1, 1), pl.date(2025, 12, 31), "1d", eager=True)
    assert choose_granularity(days) == ("month", 12)
    months = pl.date_range(pl.date(2024, 1, 1), pl.date(2025, 12, 1), "1mo", eager=True)
    assert choose_granularity(months) == ("month", 24)
    decade = pl.date_range(pl.date(2015, 1, 1), pl.date(2024, 12, 1), "1mo", eager=True)
    assert choose_granularity(decade) == ("quarter", 40)
    ten_days = pl.date_range(pl.date(2025, 1, 1), pl.date(2025, 1, 10), "1d", eager=True)
    assert choose_granularity(ten_days) == ("day", 10)
    few = pl.date_range(pl.date(2025, 1, 1), pl.date(2025, 3, 1), "1mo", eager=True)
    assert choose_granularity(few)[0] is None


def test_definitions_override_inference_and_exclude_text_identifier():
    df = pl.DataFrame({"code": [str(i) for i in range(30)], "amt": [str(i * 2) for i in range(30)], "note": ["x"] * 30})
    roles = resolve_roles(
        infer_frame(df),
        [
            FieldDefinition(column="code", semantic_type="IDENTIFIER"),
            FieldDefinition(column="amt", semantic_type="CURRENCY", unit="THB", aggregation="SUM"),
            FieldDefinition(column="note", semantic_type="IGNORE"),
        ],
    )
    assert roles["code"].role == "excluded" and roles["note"].role == "excluded"
    assert roles["amt"].role == "measure" and roles["amt"].unit == "THB" and roles["amt"].sums
