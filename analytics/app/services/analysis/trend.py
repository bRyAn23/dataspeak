"""Trend family: period aggregation, period-over-period growth, average growth, OLS slope."""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Tuple

import numpy as np
import polars as pl
import statsmodels.api as sm

from app.services.analysis import rules
from app.services.analysis.evidence import fmt_number, fmt_pct, make_evidence
from app.services.analysis.roles import ColumnRole, by_role

TRUNCS = {"day": "1d", "week": "1w", "month": "1mo", "quarter": "1q", "year": "1y"}


GRANULARITY_PREFERENCE = ("month", "quarter", "week", "year", "day")


def choose_granularity(dates: pl.Series) -> Tuple[Optional[str], int]:
    """Business-preferred granularity whose period count fits [MIN_PERIODS_TREND, MAX_PERIODS].

    Preference order is month, quarter, week, year, day. When none fits the window, the coarsest
    granularity with at least MIN_PERIODS_TREND periods is used; (None, best_count) when nothing does.
    """
    counts = {g: dates.dt.truncate(TRUNCS[g]).n_unique() for g in rules.GRANULARITIES}
    for g in GRANULARITY_PREFERENCE:
        if rules.MIN_PERIODS_TREND <= counts[g] <= rules.MAX_PERIODS:
            return g, counts[g]
    for g in reversed(rules.GRANULARITIES):
        if counts[g] >= rules.MIN_PERIODS_TREND:
            return g, counts[g]
    return None, max(counts.values()) if counts else 0


def period_label(g: str) -> str:
    return {"day": "%Y-%m-%d", "week": "%Y-%m-%d", "month": "%Y-%m", "quarter": "%Y-%m", "year": "%Y"}[g]


def _average_growth(series: List[float]) -> Optional[float]:
    if len(series) < 2:
        return None
    ratios = []
    for prev, cur in zip(series[:-1], series[1:]):
        if prev is None or cur is None or prev == 0:
            continue
        ratios.append(cur / prev)
    if not ratios:
        return None
    if all(r > 0 for r in ratios):
        return float(np.exp(np.mean(np.log(ratios))) - 1)
    return float(np.mean([r - 1 for r in ratios]))


def run(df: pl.DataFrame, roles: Dict[str, ColumnRole]) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    times = by_role(roles, "time", 1)
    measures = by_role(roles, "measure", 5)
    if not times or not measures:
        return out
    t = times[0]
    tseries = t.typed.series
    if t.typed.inferred_type == "datetime":
        tseries = tseries.cast(pl.Date)

    valid_dates = tseries.drop_nulls()
    if valid_dates.len() == 0:
        return out
    granularity, periods = choose_granularity(valid_dates)
    if granularity is None:
        for m in measures:
            out.append(
                make_evidence(
                    family="trend",
                    metric="period_series",
                    method="period_aggregation",
                    value=None,
                    display_value=None,
                    source_columns=[t.name, m.name],
                    metric_column=m.name,
                    dimension_column=t.name,
                    parameters={"period": "none"},
                    quality="insufficient",
                    notes=[f"only {periods} distinct period(s); at least {rules.MIN_PERIODS_TREND} required"],
                )
            )
        return out

    for m in measures:
        frame = pl.DataFrame({"t": tseries, "v": m.typed.series}).drop_nulls()
        if frame.height == 0:
            continue
        agg = pl.col("v").sum() if m.sums else pl.col("v").mean()
        method_agg = "sum" if m.sums else "mean"
        per = (
            frame.with_columns(pl.col("t").dt.truncate(TRUNCS[granularity]).alias("p"))
            .group_by("p")
            .agg(agg.alias("value"), pl.len().alias("n"))
            .sort("p")
        )
        labels = per["p"].dt.strftime(period_label(granularity)).to_list()
        values = [float(v) for v in per["value"].to_list()]
        n_periods = len(values)
        params = {"period": granularity, "aggregation": method_agg}
        quality = "acceptable" if n_periods >= rules.MIN_PERIODS_TREND else "insufficient"
        notes: List[str] = []
        if m.typed.null_rate >= rules.HIGH_NULL_WARN and quality == "acceptable":
            quality, notes = "warning", [f"{m.typed.null_rate:.1%} missing"]
        common = dict(source_columns=[t.name, m.name], metric_column=m.name, dimension_column=t.name)

        out.append(
            make_evidence(
                family="trend",
                metric="period_series",
                method=f"period_aggregation_{method_agg}",
                value=None,
                display_value=None,
                parameters=params,
                quality=quality,
                notes=notes,
                payload={
                    "series": [
                        {"period": lbl, "value": v, "n": int(c)}
                        for lbl, v, c in zip(labels, values, per["n"].to_list())
                    ],
                    "unit": m.unit,
                    "periods": n_periods,
                },
                **common,
            )
        )
        if n_periods < 2 or quality == "insufficient":
            continue

        prev, last = values[-2], values[-1]
        latest = (last - prev) / abs(prev) if prev else None
        out.append(
            make_evidence(
                family="trend",
                metric="latest_period_change",
                method="period_over_period_change",
                value=latest,
                display_value=fmt_pct(latest, signed=True),
                parameters={**params, "from": labels[-2], "to": labels[-1]},
                quality=quality if latest is not None else "insufficient",
                notes=notes,
                **common,
            )
        )
        avg = _average_growth(values)
        out.append(
            make_evidence(
                family="trend",
                metric="average_period_growth",
                method="geometric_mean_growth",
                value=avg,
                display_value=fmt_pct(avg, signed=True),
                parameters=params,
                quality=quality if avg is not None else "insufficient",
                notes=notes,
                payload={
                    "first_period": labels[0],
                    "last_period": labels[-1],
                    "first_value": values[0],
                    "last_value": values[-1],
                },
                **common,
            )
        )
        total_change = (values[-1] - values[0]) / abs(values[0]) if values[0] else None
        out.append(
            make_evidence(
                family="trend",
                metric="total_change",
                method="first_to_last_change",
                value=total_change,
                display_value=fmt_pct(total_change, signed=True),
                parameters=params,
                quality=quality if total_change is not None else "insufficient",
                notes=notes,
                **common,
            )
        )
        if n_periods >= rules.MIN_PERIODS_SLOPE:
            x = sm.add_constant(np.arange(n_periods, dtype=float))
            model = sm.OLS(np.array(values, dtype=float), x).fit()
            slope = float(model.params[1])
            mean_level = float(np.mean(values))
            out.append(
                make_evidence(
                    family="trend",
                    metric="trend_slope",
                    method="ols_linear_trend",
                    value=slope,
                    display_value=f"{fmt_number(slope, m.unit)} per {granularity}",
                    parameters=params,
                    quality=quality,
                    notes=notes,
                    payload={
                        "p_value": float(model.pvalues[1]),
                        "r_squared": float(model.rsquared),
                        "n_periods": n_periods,
                        "relative_slope": slope / abs(mean_level) if mean_level else None,
                    },
                    **common,
                )
            )
        else:
            out.append(
                make_evidence(
                    family="trend",
                    metric="trend_slope",
                    method="ols_linear_trend",
                    value=None,
                    display_value=None,
                    parameters=params,
                    quality="insufficient",
                    notes=[f"{n_periods} periods; at least {rules.MIN_PERIODS_SLOPE} required for a slope"],
                    **common,
                )
            )
    return out
