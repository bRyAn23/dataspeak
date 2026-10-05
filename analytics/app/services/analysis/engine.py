"""PY-API-004 orchestration: run every family whose preconditions hold; record skips explicitly."""

from __future__ import annotations

from typing import Any, Dict, List, Tuple

import numpy as np
import polars as pl

from app.core.logging import get_logger
from app.schemas.common import FieldDefinition
from app.schemas.requests import AnalysisOptions
from app.services.analysis import association, descriptive, groups, quality, rules, trend
from app.services.analysis.roles import by_role, resolve_roles
from app.services.profiling.inference import infer_frame

log = get_logger(__name__)


def analyze_frame(
    df: pl.DataFrame, definitions: List[FieldDefinition], options: AnalysisOptions, analysis_id: str = ""
) -> Tuple[List[Dict[str, Any]], Dict[str, Any]]:
    np.random.seed(options.seed or rules.SEED)  # no stochastic step exists today; fixed for safety
    typed = infer_frame(df)
    roles = resolve_roles(typed, definitions)
    measures, dims, times = by_role(roles, "measure"), by_role(roles, "dimension"), by_role(roles, "time")

    evidence: List[Dict[str, Any]] = []
    families_run: List[str] = []
    skipped: List[Dict[str, str]] = []

    def run_family(name: str, fn, precondition: bool, reason: str) -> None:
        if not precondition:
            skipped.append({"family": name, "reason": reason})
            return
        items = fn(df, roles)
        evidence.extend(items)
        families_run.append(name)
        log.info("analysis.family", extra={"analysis_id": analysis_id, "method": name, "row_count": len(items)})

    run_family("descriptive", descriptive.run, df.height >= rules.MIN_ROWS_DESCRIPTIVE, "ANALYTICS_INSUFFICIENT_DATA")
    run_family("trend", trend.run, bool(times) and bool(measures), "no date column with a numeric measure")
    run_family(
        "group_comparison", groups.run, bool(dims) and bool(measures), "no category column with a numeric measure"
    )
    run_family(
        "association", association.run, len(measures) >= 2 or len(dims) >= 2, "fewer than two comparable columns"
    )
    run_family("quality", quality.run, df.height > 0, "ANALYTICS_INSUFFICIENT_DATA")
    if any(e["analysis_family"] == "statistical_test" for e in evidence):
        families_run.append("statistical_test")

    # Deduplicate by evidence_id (deterministic), keep first.
    seen = set()
    unique: List[Dict[str, Any]] = []
    for e in evidence:
        if e["evidence_id"] in seen:
            continue
        seen.add(e["evidence_id"])
        unique.append(e)

    summary = {"evidence_count": len(unique), "families_run": families_run, "skipped": skipped}
    return unique, summary
