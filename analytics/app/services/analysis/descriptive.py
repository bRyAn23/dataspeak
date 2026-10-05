"""Descriptive family: counts, central tendency, spread, distribution bins, category shares."""

from __future__ import annotations

from typing import Any, Dict, List

import numpy as np
import polars as pl

from app.services.analysis import rules
from app.services.analysis.evidence import fmt_number, fmt_pct, make_evidence
from app.services.analysis.roles import ColumnRole, by_role


def _histogram(values: np.ndarray, bins: int) -> Dict[str, Any]:
    counts, edges = np.histogram(values, bins=bins)
    return {
        "bins": [
            {"from": float(edges[i]), "to": float(edges[i + 1]), "count": int(counts[i])} for i in range(len(counts))
        ]
    }


def run(df: pl.DataFrame, roles: Dict[str, ColumnRole]) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    out.append(
        make_evidence(
            family="descriptive",
            metric="row_count",
            method="count_rows",
            value=float(df.height),
            display_value=fmt_number(float(df.height)),
            source_columns=[],
            parameters={},
        )
    )

    for m in by_role(roles, "measure", rules.MAX_MEASURES):
        s = m.typed.series.drop_nulls()
        n = s.len()
        if n < rules.MIN_ROWS_DESCRIPTIVE:
            out.append(
                make_evidence(
                    family="descriptive",
                    metric="count",
                    method="count_non_null",
                    value=0.0,
                    display_value="0",
                    source_columns=[m.name],
                    metric_column=m.name,
                    quality="insufficient",
                    notes=["no numeric values"],
                )
            )
            continue
        quality = "warning" if m.typed.null_rate >= rules.HIGH_NULL_WARN else "acceptable"
        notes = [f"{m.typed.null_rate:.1%} missing"] if quality == "warning" else []
        common = dict(family="descriptive", source_columns=[m.name], metric_column=m.name, quality=quality, notes=notes)
        values = s.to_numpy().astype(float)

        out.append(
            make_evidence(
                metric="count", method="count_non_null", value=float(n), display_value=fmt_number(float(n)), **common
            )
        )
        if m.sums:
            total = float(values.sum())
            out.append(
                make_evidence(
                    metric="sum",
                    method="arithmetic_sum",
                    value=total,
                    display_value=fmt_number(total, m.unit),
                    **common,
                )
            )
        mean = float(values.mean())
        out.append(
            make_evidence(
                metric="mean", method="arithmetic_mean", value=mean, display_value=fmt_number(mean, m.unit), **common
            )
        )
        median = float(np.median(values))
        out.append(
            make_evidence(
                metric="median", method="median", value=median, display_value=fmt_number(median, m.unit), **common
            )
        )
        out.append(
            make_evidence(
                metric="min",
                method="minimum",
                value=float(values.min()),
                display_value=fmt_number(float(values.min()), m.unit),
                **common,
            )
        )
        out.append(
            make_evidence(
                metric="max",
                method="maximum",
                value=float(values.max()),
                display_value=fmt_number(float(values.max()), m.unit),
                **common,
            )
        )
        if n >= rules.MIN_ROWS_STD:
            std = float(values.std(ddof=1))
            out.append(
                make_evidence(
                    metric="std",
                    method="sample_standard_deviation",
                    value=std,
                    display_value=fmt_number(std, m.unit),
                    parameters={"ddof": 1},
                    **common,
                )
            )
            p25, p75 = float(np.percentile(values, 25)), float(np.percentile(values, 75))
            payload = {"p25": p25, "p75": p75, "iqr": p75 - p25}
            payload.update(_histogram(values, rules.HISTOGRAM_BINS))
            out.append(
                make_evidence(
                    metric="distribution",
                    method="histogram_equal_width",
                    value=None,
                    display_value=None,
                    parameters={"bins": rules.HISTOGRAM_BINS},
                    payload=payload,
                    **common,
                )
            )

    for d in by_role(roles, "dimension", rules.MAX_DIMENSIONS):
        s = d.typed.series.drop_nulls()
        n = s.len()
        if n == 0:
            continue
        counts = s.cast(pl.Utf8).value_counts().sort(["count", d.name], descending=[True, False])
        labels = counts[d.name].to_list()
        cnts = counts["count"].to_list()
        groups = [{"group": str(lbl), "count": int(c), "share": int(c) / n} for lbl, c in zip(labels, cnts)]
        quality = "warning" if d.typed.null_rate >= rules.HIGH_NULL_WARN else "acceptable"
        out.append(
            make_evidence(
                family="descriptive",
                metric="distinct_count",
                method="count_distinct",
                value=float(len(groups)),
                display_value=str(len(groups)),
                source_columns=[d.name],
                dimension_column=d.name,
                quality=quality,
            )
        )
        out.append(
            make_evidence(
                family="descriptive",
                metric="top_category_share",
                method="frequency_share",
                value=groups[0]["share"],
                display_value=fmt_pct(groups[0]["share"]),
                source_columns=[d.name],
                dimension_column=d.name,
                quality=quality,
                payload={"groups": groups, "top_group": groups[0]["group"]},
            )
        )
    return out
