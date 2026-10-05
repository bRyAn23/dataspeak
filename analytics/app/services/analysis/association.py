"""Association family: Pearson+Spearman for numeric pairs, chi-square/Cramér's V for category pairs."""

from __future__ import annotations

from itertools import combinations
from typing import Any, Dict, List

import numpy as np
import polars as pl
from scipy import stats

from app.services.analysis import rules
from app.services.analysis.evidence import make_evidence
from app.services.analysis.roles import ColumnRole, by_role


def strength(r: float) -> str:
    a = abs(r)
    if a >= 0.7:
        return "strong"
    if a >= 0.5:
        return "moderate"
    if a >= 0.3:
        return "weak"
    return "negligible"


def run(df: pl.DataFrame, roles: Dict[str, ColumnRole]) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    measures = by_role(roles, "measure", rules.MAX_MEASURES)
    candidates = []
    for a, b in combinations(measures, 2):
        frame = pl.DataFrame({"a": a.typed.series, "b": b.typed.series}).drop_nulls()
        n = frame.height
        common = dict(source_columns=[a.name, b.name], metric_column=a.name, dimension_column=b.name)
        if n < rules.ASSOC_MIN_PAIRS:
            out.append(
                make_evidence(
                    family="association",
                    metric="spearman_correlation",
                    method="spearman_rank_correlation",
                    value=None,
                    display_value=None,
                    parameters={"n": n},
                    quality="insufficient",
                    notes=[f"{n} complete pairs; at least {rules.ASSOC_MIN_PAIRS} required"],
                    **common,
                )
            )
            continue
        x, y = frame["a"].to_numpy().astype(float), frame["b"].to_numpy().astype(float)
        if np.std(x) == 0 or np.std(y) == 0:
            continue
        sp = stats.spearmanr(x, y)
        pe = stats.pearsonr(x, y)
        candidates.append(
            (
                abs(float(sp.statistic)),
                a,
                b,
                n,
                float(sp.statistic),
                float(sp.pvalue),
                float(pe.statistic),
                float(pe.pvalue),
            )
        )

    candidates.sort(key=lambda c: (-c[0], c[1].name, c[2].name))
    for _, a, b, n, rho, rho_p, r, r_p in candidates[: rules.MAX_ASSOC_PAIRS]:
        out.append(
            make_evidence(
                family="association",
                metric="spearman_correlation",
                method="spearman_rank_correlation",
                value=rho,
                display_value=f"rho={rho:+.2f}",
                parameters={"n": n},
                quality="acceptable",
                payload={
                    "spearman_rho": rho,
                    "spearman_p": rho_p,
                    "pearson_r": r,
                    "pearson_p": r_p,
                    "n": n,
                    "strength": strength(rho),
                    "direction": "positive" if rho > 0 else "negative",
                },
                source_columns=[a.name, b.name],
                metric_column=a.name,
                dimension_column=b.name,
            )
        )

    dims = by_role(roles, "dimension", rules.MAX_DIMENSIONS)
    pairs = list(combinations(dims, 2))[: rules.MAX_CAT_PAIRS]
    for a, b in pairs:
        frame = pl.DataFrame({"a": a.typed.series.cast(pl.Utf8), "b": b.typed.series.cast(pl.Utf8)}).drop_nulls()
        if frame.height == 0:
            continue
        table = frame.pivot(on="b", index="a", values="b", aggregate_function="len").fill_null(0)
        matrix = table.drop("a").to_numpy().astype(float)
        if matrix.shape[0] < 2 or matrix.shape[1] < 2:
            continue
        chi2, p, dof, expected = stats.chi2_contingency(matrix)
        share_ok = float((expected >= rules.CHI_EXPECTED_MIN).mean())
        n = int(matrix.sum())
        v = float(np.sqrt(chi2 / (n * (min(matrix.shape) - 1)))) if n else None
        common = dict(source_columns=[a.name, b.name], metric_column=None, dimension_column=a.name)
        if share_ok < rules.CHI_EXPECTED_SHARE:
            out.append(
                make_evidence(
                    family="association",
                    metric="cramers_v",
                    method="chi_square_independence",
                    value=None,
                    display_value=None,
                    parameters={"n": n},
                    quality="insufficient",
                    notes=[f"only {share_ok:.0%} of expected cell counts are >= {rules.CHI_EXPECTED_MIN}"],
                    **common,
                )
            )
            continue
        out.append(
            make_evidence(
                family="association",
                metric="cramers_v",
                method="chi_square_independence",
                value=v,
                display_value=f"V={v:.2f}" if v is not None else None,
                parameters={"n": n, "dof": int(dof)},
                quality="acceptable",
                payload={"chi2": float(chi2), "p_value": float(p), "cramers_v": v, "n": n},
                **common,
            )
        )
    return out
