"""Quality family: missingness, duplicates, constants, outliers, parse failures."""

from __future__ import annotations

from typing import Any, Dict, List

import numpy as np
import polars as pl

from app.services.analysis import rules
from app.services.analysis.evidence import fmt_number, fmt_pct, make_evidence
from app.services.analysis.roles import ColumnRole, by_role


def run(df: pl.DataFrame, roles: Dict[str, ColumnRole]) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    rows = df.height
    if rows == 0:
        return out
    total_cells = rows * df.width
    non_null = sum(rows - r.typed.null_count for r in roles.values())
    completeness = non_null / total_cells if total_cells else 0.0
    out.append(
        make_evidence(
            family="quality",
            metric="completeness",
            method="non_null_cell_share",
            value=completeness,
            display_value=fmt_pct(completeness),
            source_columns=[],
            parameters={},
            quality="acceptable" if completeness >= 1 - rules.HIGH_NULL_WARN else "warning",
        )
    )
    dupes = rows - df.unique().height
    out.append(
        make_evidence(
            family="quality",
            metric="duplicate_rows",
            method="exact_duplicate_count",
            value=float(dupes),
            display_value=fmt_number(float(dupes)),
            source_columns=[],
            parameters={},
            quality="warning" if rows and dupes / rows >= rules.DUPLICATE_ROWS_WARN else "acceptable",
            payload={"share": dupes / rows},
        )
    )
    for r in roles.values():
        col = r.typed
        if col.null_count > 0:
            status = (
                "insufficient"
                if col.null_rate >= rules.NULL_INSUFFICIENT
                else ("warning" if col.null_rate >= rules.HIGH_NULL_WARN else "acceptable")
            )
            out.append(
                make_evidence(
                    family="quality",
                    metric="null_rate",
                    method="missing_value_share",
                    value=col.null_rate,
                    display_value=fmt_pct(col.null_rate),
                    source_columns=[r.name],
                    parameters={},
                    quality=status,
                    payload={"null_count": col.null_count, "rows": rows},
                )
            )
        if rows > 1 and col.distinct_count <= 1 and col.null_count < rows:
            out.append(
                make_evidence(
                    family="quality",
                    metric="constant_column",
                    method="distinct_count",
                    value=1.0,
                    display_value="1",
                    source_columns=[r.name],
                    parameters={},
                    quality="warning",
                )
            )
        if col.parse_failures > 0:
            out.append(
                make_evidence(
                    family="quality",
                    metric="type_parse_failures",
                    method="type_inference_mismatch",
                    value=float(col.parse_failures),
                    display_value=fmt_number(float(col.parse_failures)),
                    source_columns=[r.name],
                    parameters={"inferred_type": col.inferred_type},
                    quality="warning",
                )
            )
    for m in by_role(roles, "measure", rules.MAX_MEASURES):
        values = m.typed.series.drop_nulls().to_numpy().astype(float)
        if len(values) < rules.MIN_ROWS_STD * 2:
            continue
        p25, p75 = np.percentile(values, 25), np.percentile(values, 75)
        iqr = p75 - p25
        lo, hi = p25 - rules.IQR_K * iqr, p75 + rules.IQR_K * iqr
        n_out = int(((values < lo) | (values > hi)).sum())
        share = n_out / len(values)
        out.append(
            make_evidence(
                family="quality",
                metric="outlier_count",
                method="iqr_fence",
                value=float(n_out),
                display_value=fmt_number(float(n_out)),
                source_columns=[m.name],
                metric_column=m.name,
                parameters={"k": rules.IQR_K},
                quality="warning" if share >= rules.OUTLIER_SHARE_WARN else "acceptable",
                payload={"share": share, "lower_fence": float(lo), "upper_fence": float(hi), "unit": m.unit},
            )
        )
    return out
