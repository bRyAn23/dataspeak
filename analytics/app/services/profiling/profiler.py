"""PY-API-002 profiling: derived metadata only, no raw row samples, no category labels."""

from __future__ import annotations

from typing import Any, Dict, List

import polars as pl

from app.schemas.common import QualityFinding
from app.schemas.responses import ColumnProfile, DatasetSummary, ProfileResponse
from app.services.analysis import rules
from app.services.profiling.inference import TypedColumn, infer_frame


def _round(v: Any, nd: int = 6) -> Any:
    if v is None:
        return None
    try:
        return round(float(v), nd)
    except (TypeError, ValueError):
        return None


def column_stats(col: TypedColumn) -> Dict[str, Any]:
    s = col.series.drop_nulls()
    if s.len() == 0:
        return {}
    if col.inferred_type in ("numeric", "integer"):
        return {
            "min": _round(s.min()),
            "max": _round(s.max()),
            "mean": _round(s.mean()),
            "median": _round(s.median()),
            "p25": _round(s.quantile(0.25, "linear")),
            "p75": _round(s.quantile(0.75, "linear")),
            "std": _round(s.std()) if s.len() > 1 else None,
        }
    if col.inferred_type in ("date", "datetime"):
        lo, hi = s.min(), s.max()
        span = (hi - lo).days if hasattr(hi - lo, "days") else int((hi - lo).total_seconds() // 86400)
        return {"min": str(lo)[:19], "max": str(hi)[:19], "span_days": int(span)}
    if col.inferred_type == "boolean":
        return {"true_rate": _round(s.cast(pl.Float64).mean())}
    if col.inferred_type == "category":
        counts = s.value_counts().sort("count", descending=True)
        top = int(counts["count"][0])
        return {"top_share": _round(top / s.len()), "top_count": top}
    return {}


def profile_frame(df: pl.DataFrame) -> ProfileResponse:
    typed = infer_frame(df)
    rows, cols = df.height, df.width
    total_cells = rows * cols
    non_null = sum(rows - c.null_count for c in typed.values())
    completeness = (non_null / total_cells) if total_cells else 0.0
    duplicate_rows = (rows - df.unique().height) if rows else 0

    columns: List[ColumnProfile] = []
    findings: List[QualityFinding] = []
    for name, col in typed.items():
        columns.append(
            ColumnProfile(
                name=name,
                position=col.position,
                inferred_type=col.inferred_type,
                null_count=col.null_count,
                null_rate=round(col.null_rate, 6),
                distinct_count=col.distinct_count,
                stats=column_stats(col),
            )
        )
        if col.null_rate >= rules.NULL_INSUFFICIENT:
            findings.append(
                QualityFinding(
                    code="MOSTLY_EMPTY_COLUMN",
                    severity="insufficient",
                    message=f"{col.null_rate:.1%} of values are missing.",
                    column=name,
                )
            )
        elif col.null_rate >= rules.HIGH_NULL_WARN:
            findings.append(
                QualityFinding(
                    code="HIGH_NULL_RATE",
                    severity="warning",
                    message=f"{col.null_rate:.1%} of values are missing.",
                    column=name,
                )
            )
        if rows > 1 and col.distinct_count <= 1 and col.null_count < rows:
            findings.append(
                QualityFinding(
                    code="CONSTANT_COLUMN",
                    severity="warning",
                    message="The column has a single value.",
                    column=name,
                )
            )
        if col.parse_failures > 0:
            findings.append(
                QualityFinding(
                    code="MIXED_TYPES",
                    severity="warning",
                    message=f"{col.parse_failures} value(s) did not match the inferred type {col.inferred_type}.",
                    column=name,
                )
            )
    if rows and duplicate_rows / rows >= rules.DUPLICATE_ROWS_WARN:
        findings.append(
            QualityFinding(
                code="DUPLICATE_ROWS",
                severity="warning",
                message=f"{duplicate_rows} duplicate row(s) ({duplicate_rows / rows:.1%}).",
            )
        )

    return ProfileResponse(
        dataset=DatasetSummary(
            rows=rows,
            columns=cols,
            completeness=round(completeness, 6),
            duplicate_rows=duplicate_rows,
        ),
        columns=columns,
        quality_findings=findings,
    )
