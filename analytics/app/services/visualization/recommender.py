"""PY-API-006 chart specification recommendation from evidence and insights.

Output is a specification only (type, title, axes, unit, aggregated series, evidence ids). Series come
from evidence payloads (period totals, group aggregates, histogram bins), never raw rows. Units come
from definitions; unknown units are left empty and flagged with unit_missing.
"""

from __future__ import annotations

from typing import Any, Dict, List, Optional

from app.schemas.common import Evidence, FieldDefinition
from app.schemas.requests import InsightIn

MAX_CHARTS = 8


def _labels_units(definitions: List[FieldDefinition]):
    labels = {d.column: (d.business_name or d.column) for d in definitions}
    units = {d.column: d.unit for d in definitions}
    return labels, units


def _find(evidence: List[Evidence], metric: str, cols: List[str]) -> Optional[Evidence]:
    for e in evidence:
        if e.metric == metric and e.source_columns == cols and e.quality.status != "insufficient":
            return e
    return None


def recommend(
    evidence: List[Evidence], insights: List[InsightIn], definitions: List[FieldDefinition]
) -> List[Dict[str, Any]]:
    labels, units = _labels_units(definitions)
    lab = lambda c: labels.get(c, c)  # noqa: E731
    charts: List[Dict[str, Any]] = []
    used = set()

    def add(spec: Dict[str, Any]) -> None:
        key = (spec["type"], spec["title"])  # one chart per (type, measure, dimension) combination
        if key in used or len(charts) >= MAX_CHARTS:
            return
        used.add(key)
        charts.append(spec)

    def unit_for(col: Optional[str], payload_unit: Optional[str]) -> Optional[str]:
        return units.get(col) if col and units.get(col) else payload_unit

    def from_series(e: Evidence, rank: Optional[int]) -> None:
        time_col, measure = e.source_columns[0], e.source_columns[1]
        p = e.payload or {}
        unit = unit_for(measure, p.get("unit"))
        add(
            {
                "type": "line",
                "title": f"{lab(measure)} by {e.parameters.get('period', 'period')}",
                "x": {"field": "period", "label": lab(time_col), "unit": None},
                "y": {"field": "value", "label": lab(measure), "unit": unit},
                "unit_missing": unit is None,
                "series": [{"period": pt["period"], "value": pt["value"]} for pt in p.get("series", [])],
                "evidence_ids": [e.evidence_id],
                "insight_rank": rank,
            }
        )

    def from_groups(e: Evidence, rank: Optional[int], field: str) -> None:
        dim, measure = e.source_columns[0], e.source_columns[1]
        p = e.payload or {}
        unit = unit_for(measure, p.get("unit"))
        title = f"{'Total' if field == 'sum' else 'Average'} {lab(measure)} by {lab(dim)}"
        add(
            {
                "type": "bar",
                "title": title,
                "x": {"field": "group", "label": lab(dim), "unit": None},
                "y": {"field": field, "label": lab(measure), "unit": unit},
                "unit_missing": unit is None,
                "series": [{"group": g["group"], field: g[field], "count": g["count"]} for g in p.get("groups", [])],
                "evidence_ids": [e.evidence_id],
                "insight_rank": rank,
            }
        )

    def from_distribution(e: Evidence, rank: Optional[int]) -> None:
        measure = e.source_columns[0]
        p = e.payload or {}
        unit = unit_for(measure, None)
        add(
            {
                "type": "histogram",
                "title": f"Distribution of {lab(measure)}",
                "x": {"field": "from", "label": lab(measure), "unit": unit},
                "y": {"field": "count", "label": "Rows", "unit": None},
                "unit_missing": unit is None,
                "series": [{"from": b["from"], "to": b["to"], "count": b["count"]} for b in p.get("bins", [])],
                "evidence_ids": [e.evidence_id],
                "insight_rank": rank,
            }
        )

    by_id = {e.evidence_id: e for e in evidence}
    for ins in sorted(insights, key=lambda i: i.rank):
        e = by_id.get(ins.primary_evidence_id)
        if e is None:
            continue
        cols = e.source_columns
        if ins.insight_type == "trend_change":
            series = _find(evidence, "period_series", cols)
            if series:
                from_series(series, ins.rank)
        elif ins.insight_type in ("concentration", "group_difference"):
            agg = _find(evidence, "group_aggregates", cols)
            if agg:
                from_groups(agg, ins.rank, "sum" if ins.insight_type == "concentration" else "mean")
        elif ins.insight_type == "distribution":
            dist = _find(evidence, "distribution", [cols[0]])
            if dist:
                from_distribution(dist, ins.rank)
        elif ins.insight_type == "association" and e.metric == "spearman_correlation":
            p = e.payload or {}
            add(
                {
                    "type": "table",
                    "title": f"Association between {lab(cols[0])} and {lab(cols[1])}",
                    "x": {"field": "statistic", "label": "Statistic", "unit": None},
                    "y": {"field": "value", "label": "Value", "unit": None},
                    "unit_missing": False,
                    "series": [
                        {"statistic": k, "value": p.get(k)} for k in ("spearman_rho", "pearson_r", "n") if k in p
                    ],
                    "evidence_ids": [e.evidence_id],
                    "insight_rank": ins.rank,
                }
            )

    # Fill remaining slots with the strongest un-charted evidence so every analysis gets at least one chart.
    for e in evidence:
        if len(charts) >= MAX_CHARTS:
            break
        if e.quality.status == "insufficient":
            continue
        if e.metric == "period_series":
            from_series(e, None)
        elif e.metric == "group_aggregates":
            from_groups(e, None, e.parameters.get("aggregation", "sum"))
    for e in evidence:
        if len(charts) >= MAX_CHARTS:
            break
        if e.metric == "distribution" and e.quality.status != "insufficient":
            from_distribution(e, None)

    missing = [e for e in evidence if e.metric == "null_rate" and e.value and e.value > 0]
    if missing and len(charts) < MAX_CHARTS:
        add(
            {
                "type": "bar",
                "title": "Missing values by column",
                "x": {"field": "column", "label": "Column", "unit": None},
                "y": {"field": "null_rate", "label": "Share missing", "unit": "%"},
                "unit_missing": False,
                "series": [
                    {"column": lab(e.source_columns[0]), "null_rate": round((e.value or 0) * 100, 2)} for e in missing
                ],
                "evidence_ids": [e.evidence_id for e in missing],
                "insight_rank": None,
            }
        )
    return charts
