"""PY-API-005 insight detection and ranking over derived evidence (never raw CSV).

Each rule maps evidence -> candidate with a magnitude score in [0, 1]. Final score =
W_MAGNITUDE * magnitude + W_SUPPORT * support + W_QUALITY * quality (rules.py).
INSUFFICIENT evidence is excluded; WARNING evidence is downgraded through the quality term.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

from app.schemas.common import Evidence, FieldDefinition
from app.services.analysis import rules
from app.services.analysis.evidence import fmt_number, fmt_p, fmt_pct
from app.services.insights import wording


@dataclass
class Candidate:
    insight_type: str
    headline: str
    description: str
    magnitude: float
    support: float
    quality: float
    primary: str
    evidence_ids: List[str]
    dedupe_key: tuple
    score: float = field(default=0.0)

    def finalize(self) -> Candidate:
        self.score = round(
            min(
                1.0,
                max(
                    0.0,
                    rules.W_MAGNITUDE * self.magnitude
                    + rules.W_SUPPORT * self.support
                    + rules.W_QUALITY * self.quality,
                ),
            ),
            4,
        )
        return self


def _labels(definitions: List[FieldDefinition]) -> Dict[str, str]:
    return {d.column: (d.business_name or d.column) for d in definitions}


def _support_from_p(p: Optional[float]) -> float:
    if p is None:
        return 0.5
    if p < rules.P_VALUE_STRONG:
        return 1.0
    if p < rules.P_VALUE_MAX:
        return 0.8
    return 0.2


def detect(evidence: List[Evidence], definitions: List[FieldDefinition], max_insights: int) -> List[Dict[str, Any]]:
    labels = _labels(definitions)
    lab = lambda c: labels.get(c, c)  # noqa: E731
    by_id = {e.evidence_id: e for e in evidence}
    tests = [e for e in evidence if e.analysis_family == "statistical_test" and e.value is not None]
    candidates: List[Candidate] = []

    for e in evidence:
        q = rules.QUALITY_SCORE.get(e.quality.status, 0.0)
        if e.quality.status == "insufficient":
            continue
        cols = tuple(e.source_columns)
        p = e.payload or {}

        if e.metric == "average_period_growth" and e.value is not None and abs(e.value) >= rules.GROWTH_RATE_MIN:
            measure, period = e.metric_column or cols[-1], e.parameters.get("period", "period")
            candidates.append(
                Candidate(
                    "trend_change",
                    wording.trend(lab(measure), e.display_value or fmt_pct(e.value, True), period),
                    f"From {p.get('first_period')} to {p.get('last_period')}, {lab(measure)} went from "
                    f"{fmt_number(p.get('first_value'))} to {fmt_number(p.get('last_value'))}.",
                    min(1.0, abs(e.value) / 0.25),
                    0.7,
                    q,
                    e.evidence_id,
                    [e.evidence_id],
                    ("trend_change", cols),
                )
            )
        elif e.metric == "latest_period_change" and e.value is not None and abs(e.value) >= rules.LATEST_CHANGE_MIN:
            measure = e.metric_column or cols[-1]
            candidates.append(
                Candidate(
                    "trend_change",
                    wording.latest(
                        lab(measure),
                        e.display_value or fmt_pct(e.value, True),
                        str(e.parameters.get("from")),
                        str(e.parameters.get("to")),
                    ),
                    "Change between the two most recent periods.",
                    min(1.0, abs(e.value) / 0.5),
                    0.5,
                    q,
                    e.evidence_id,
                    [e.evidence_id],
                    ("trend_latest", cols),
                )
            )
        elif e.metric == "top_group_share" and e.value is not None and e.value >= rules.TOP_SHARE_MIN:
            measure, dim = e.metric_column or cols[-1], e.dimension_column or cols[0]
            candidates.append(
                Candidate(
                    "concentration",
                    wording.concentration(
                        str(p.get("top_group")), e.display_value or fmt_pct(e.value), lab(measure), lab(dim)
                    ),
                    f"Total {lab(measure)} is {fmt_number(p.get('total'), p.get('unit'))}; "
                    f"the largest group contributes {fmt_number(p.get('top_sum'), p.get('unit'))}.",
                    min(1.0, (e.value - rules.TOP_SHARE_MIN) / (1 - rules.TOP_SHARE_MIN) + 0.3),
                    0.7,
                    q,
                    e.evidence_id,
                    [e.evidence_id],
                    ("concentration", cols),
                )
            )
        elif (
            e.metric == "top_bottom_mean_difference"
            and e.value is not None
            and abs(e.value) >= rules.GROUP_DIFF_REL_MIN
        ):
            measure, dim = e.metric_column or cols[-1], e.dimension_column or cols[0]
            test = next((t for t in tests if tuple(t.source_columns) == cols), None)
            p_value = test.value if test else None
            ids = [e.evidence_id] + ([test.evidence_id] if test else [])
            desc = (
                f"Highest group {p.get('highest_group')} averages {fmt_number(p.get('highest_mean'), p.get('unit'))}; "
                f"lowest group {p.get('lowest_group')} averages {fmt_number(p.get('lowest_mean'), p.get('unit'))}."
            )
            if test is not None:
                desc += f" {test.method.replace('_', ' ')}: {test.display_value}."
            else:
                desc += " No statistical test met its preconditions."
            candidates.append(
                Candidate(
                    "group_difference",
                    wording.group_difference(
                        lab(measure),
                        lab(dim),
                        str(p.get("highest_group")),
                        str(p.get("lowest_group")),
                        e.display_value or fmt_pct(e.value, True),
                    ),
                    desc,
                    min(1.0, abs(e.value) / 1.0),
                    _support_from_p(p_value),
                    q,
                    e.evidence_id,
                    ids,
                    ("group_difference", cols),
                )
            )
        elif e.metric == "spearman_correlation" and e.value is not None and abs(e.value) >= rules.CORR_MIN:
            a, b = cols[0], cols[1]
            candidates.append(
                Candidate(
                    "association",
                    wording.association_numeric(
                        lab(a), lab(b), p.get("direction", "positive"), p.get("strength", "moderate")
                    ),
                    f"Spearman rho {e.value:+.2f} (n={p.get('n')}, {fmt_p(p.get('spearman_p'))}). "
                    "Association is descriptive, not causal.",
                    min(1.0, abs(e.value)),
                    _support_from_p(p.get("spearman_p")),
                    q,
                    e.evidence_id,
                    [e.evidence_id],
                    ("association", tuple(sorted(cols))),
                )
            )
        elif e.metric == "cramers_v" and e.value is not None and e.value >= rules.CRAMERS_V_MIN:
            a, b = cols[0], cols[1]
            candidates.append(
                Candidate(
                    "association",
                    wording.association_categorical(lab(a), lab(b), e.display_value or f"V={e.value:.2f}"),
                    f"Chi-square test of independence (n={p.get('n')}), Cramér's V {e.value:.2f}.",
                    min(1.0, e.value),
                    _support_from_p(p.get("p_value")),
                    q,
                    e.evidence_id,
                    [e.evidence_id],
                    ("association", tuple(sorted(cols))),
                )
            )
        elif e.metric == "null_rate" and e.value is not None and e.value >= rules.MISSING_RATE_MIN:
            candidates.append(
                Candidate(
                    "data_quality",
                    wording.missing(lab(cols[0]), e.display_value or fmt_pct(e.value)),
                    f"{p.get('null_count')} of {p.get('rows')} rows have no value; "
                    "statistics for this column use the remaining rows.",
                    min(1.0, e.value),
                    1.0,
                    1.0,
                    e.evidence_id,
                    [e.evidence_id],
                    ("missing", cols),
                )
            )
        elif e.metric == "duplicate_rows" and e.value and (p.get("share") or 0) >= rules.DUPLICATE_ROWS_WARN:
            candidates.append(
                Candidate(
                    "data_quality",
                    wording.duplicates(e.display_value or str(int(e.value)), fmt_pct(p.get("share"))),
                    "Exact duplicate rows are counted in every total unless removed upstream.",
                    min(1.0, (p.get("share") or 0) * 10),
                    1.0,
                    1.0,
                    e.evidence_id,
                    [e.evidence_id],
                    ("duplicates", ()),
                )
            )
        elif e.metric == "outlier_count" and e.value and (p.get("share") or 0) >= rules.OUTLIER_SHARE_WARN:
            candidates.append(
                Candidate(
                    "distribution",
                    wording.outliers(lab(cols[0]), e.display_value or str(int(e.value)), fmt_pct(p.get("share"))),
                    f"Values outside {fmt_number(p.get('lower_fence'), p.get('unit'))} to "
                    f"{fmt_number(p.get('upper_fence'), p.get('unit'))} (IQR fence, k={e.parameters.get('k')}).",
                    min(1.0, (p.get("share") or 0) * 4),
                    0.8,
                    1.0,
                    e.evidence_id,
                    [e.evidence_id],
                    ("outliers", cols),
                )
            )

    best: Dict[tuple, Candidate] = {}
    for c in candidates:
        c.finalize()
        if c.dedupe_key not in best or c.score > best[c.dedupe_key].score:
            best[c.dedupe_key] = c
    ranked = sorted(best.values(), key=lambda c: (-c.score, c.insight_type, c.headline))[
        : max(1, min(max_insights, rules.MAX_INSIGHTS * 3))
    ]
    ranked = ranked[:max_insights]

    out = []
    for i, c in enumerate(ranked, start=1):
        for eid in c.evidence_ids:
            if eid not in by_id:
                continue
        out.append(
            {
                "insight_type": c.insight_type,
                "headline": c.headline[:255],
                "description": c.description[:1000],
                "score": c.score,
                "rank": i,
                "primary_evidence_id": c.primary,
                "evidence_ids": c.evidence_ids,
            }
        )
    return out
