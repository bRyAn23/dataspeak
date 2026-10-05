"""Group comparison and statistical tests between category groups of a measure."""

from __future__ import annotations

from typing import Any, Dict, List, Optional

import numpy as np
import polars as pl
from scipy import stats

from app.services.analysis import rules
from app.services.analysis.evidence import fmt_number, fmt_p, fmt_pct, make_evidence
from app.services.analysis.roles import ColumnRole, by_role


def _cohens_d(a: np.ndarray, b: np.ndarray) -> Optional[float]:
    na, nb = len(a), len(b)
    if na < 2 or nb < 2:
        return None
    pooled = np.sqrt(((na - 1) * a.var(ddof=1) + (nb - 1) * b.var(ddof=1)) / (na + nb - 2))
    return float((a.mean() - b.mean()) / pooled) if pooled > 0 else None


def choose_test(samples: List[np.ndarray]) -> Optional[Dict[str, Any]]:
    """Explicit test selection (rules.py). Returns None when no test's preconditions hold."""
    k = len(samples)
    sizes = [len(s) for s in samples]
    if k < 2:
        return None
    if k == 2:
        a, b = samples
        if min(sizes) >= rules.WELCH_MIN_N:
            r = stats.ttest_ind(a, b, equal_var=False)
            return {
                "test": "welch_t_test",
                "statistic": float(r.statistic),
                "p_value": float(r.pvalue),
                "effect_name": "cohens_d",
                "effect_size": _cohens_d(a, b),
            }
        if min(sizes) >= rules.MANN_WHITNEY_MIN_N:
            r = stats.mannwhitneyu(a, b, alternative="two-sided")
            u = float(r.statistic)
            rb = 1 - (2 * u) / (len(a) * len(b))
            return {
                "test": "mann_whitney_u",
                "statistic": u,
                "p_value": float(r.pvalue),
                "effect_name": "rank_biserial",
                "effect_size": float(rb),
            }
        return None
    if min(sizes) >= rules.ANOVA_MIN_N:
        lev = stats.levene(*samples)
        if lev.pvalue > rules.LEVENE_P:
            r = stats.f_oneway(*samples)
            grand = np.concatenate(samples).mean()
            ss_between = sum(len(s) * (s.mean() - grand) ** 2 for s in samples)
            ss_total = sum(((s - grand) ** 2).sum() for s in samples)
            return {
                "test": "one_way_anova",
                "statistic": float(r.statistic),
                "p_value": float(r.pvalue),
                "effect_name": "eta_squared",
                "effect_size": float(ss_between / ss_total) if ss_total else None,
                "levene_p": float(lev.pvalue),
            }
    if min(sizes) >= rules.KRUSKAL_MIN_N:
        r = stats.kruskal(*samples)
        n = sum(sizes)
        eps = (float(r.statistic) - k + 1) / (n - k) if n > k else None
        return {
            "test": "kruskal_wallis",
            "statistic": float(r.statistic),
            "p_value": float(r.pvalue),
            "effect_name": "epsilon_squared",
            "effect_size": max(0.0, eps) if eps is not None else None,
        }
    return None


def run(df: pl.DataFrame, roles: Dict[str, ColumnRole]) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    for d in by_role(roles, "dimension", rules.MAX_DIMENSIONS):
        for m in by_role(roles, "measure", 3):
            frame = pl.DataFrame({"g": d.typed.series.cast(pl.Utf8), "v": m.typed.series}).drop_nulls()
            if frame.height == 0:
                continue
            agg = (
                frame.group_by("g")
                .agg(pl.len().alias("n"), pl.col("v").sum().alias("sum"), pl.col("v").mean().alias("mean"))
                .sort(["sum" if m.sums else "mean", "g"], descending=[True, False])
            )
            k = agg.height
            common = dict(source_columns=[d.name, m.name], metric_column=m.name, dimension_column=d.name)
            params = {"aggregation": "sum" if m.sums else "mean"}
            if k < rules.MIN_GROUPS or k > rules.MAX_GROUPS:
                out.append(
                    make_evidence(
                        family="group_comparison",
                        metric="group_aggregates",
                        method="group_by_aggregate",
                        value=None,
                        display_value=None,
                        parameters=params,
                        quality="insufficient",
                        notes=[f"{k} group(s); between {rules.MIN_GROUPS} and {rules.MAX_GROUPS} required"],
                        **common,
                    )
                )
                continue
            total = float(agg["sum"].sum())
            groups = [
                {
                    "group": str(g),
                    "count": int(n),
                    "sum": float(s),
                    "mean": float(mu),
                    "share": (float(s) / total) if total else None,
                }
                for g, n, s, mu in zip(
                    agg["g"].to_list(), agg["n"].to_list(), agg["sum"].to_list(), agg["mean"].to_list()
                )
            ]
            small = [g for g in groups if g["count"] < rules.MIN_GROUP_SIZE]
            quality = "warning" if small else "acceptable"
            notes = [f"{len(small)} group(s) have fewer than {rules.MIN_GROUP_SIZE} rows"] if small else []
            out.append(
                make_evidence(
                    family="group_comparison",
                    metric="group_aggregates",
                    method="group_by_aggregate",
                    value=None,
                    display_value=None,
                    parameters=params,
                    quality=quality,
                    notes=notes,
                    payload={"groups": groups, "unit": m.unit, "top_group": groups[0]["group"]},
                    **common,
                )
            )
            if m.sums and total:
                top = groups[0]
                out.append(
                    make_evidence(
                        family="group_comparison",
                        metric="top_group_share",
                        method="share_of_total",
                        value=top["share"],
                        display_value=fmt_pct(top["share"]),
                        parameters=params,
                        quality=quality,
                        notes=notes,
                        payload={"top_group": top["group"], "top_sum": top["sum"], "total": total, "unit": m.unit},
                        **common,
                    )
                )
            by_mean = sorted(groups, key=lambda g: (-g["mean"], g["group"]))
            hi, lo = by_mean[0], by_mean[-1]
            rel = (hi["mean"] - lo["mean"]) / abs(lo["mean"]) if lo["mean"] else None
            out.append(
                make_evidence(
                    family="group_comparison",
                    metric="top_bottom_mean_difference",
                    method="relative_mean_difference",
                    value=rel,
                    display_value=fmt_pct(rel, signed=True),
                    parameters={"aggregation": "mean"},
                    quality=quality if rel is not None else "insufficient",
                    notes=notes,
                    payload={
                        "highest_group": hi["group"],
                        "highest_mean": hi["mean"],
                        "lowest_group": lo["group"],
                        "lowest_mean": lo["mean"],
                        "unit": m.unit,
                        "difference": hi["mean"] - lo["mean"],
                    },
                    **common,
                )
            )

            samples = [
                frame.filter(pl.col("g") == g["group"])["v"].to_numpy().astype(float)
                for g in groups
                if g["count"] >= rules.MIN_GROUP_SIZE
            ]
            test = choose_test(samples) if len(samples) >= 2 else None
            if test is None:
                out.append(
                    make_evidence(
                        family="statistical_test",
                        metric="group_difference_test",
                        method="none_applicable",
                        value=None,
                        display_value=None,
                        parameters={"groups_tested": len(samples)},
                        quality="insufficient",
                        notes=["group sizes do not meet the preconditions of any approved test"],
                        **common,
                    )
                )
            else:
                out.append(
                    make_evidence(
                        family="statistical_test",
                        metric="group_difference_test",
                        method=test["test"],
                        value=test["p_value"],
                        display_value=fmt_p(test["p_value"]),
                        parameters={"groups_tested": len(samples), "alpha": rules.P_VALUE_MAX},
                        quality=quality,
                        notes=notes,
                        payload={k2: v for k2, v in test.items() if k2 != "test"}
                        | {
                            "significant": test["p_value"] < rules.P_VALUE_MAX,
                            "largest_mean_display": fmt_number(hi["mean"], m.unit),
                        },
                        **common,
                    )
                )
    return out
