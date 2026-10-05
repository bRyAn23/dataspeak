"""Evidence construction with deterministic identifiers and display formatting."""

from __future__ import annotations

import hashlib
import json
import math
from typing import Any, Dict, Iterable, List, Optional


def evidence_id(metric: str, method: str, source_columns: Iterable[str], parameters: Dict[str, Any]) -> str:
    basis = "|".join(
        [metric, method, ",".join(sorted(source_columns)), json.dumps(parameters, sort_keys=True, default=str)]
    )
    return "ev_" + hashlib.sha256(basis.encode("utf-8")).hexdigest()[:12]


def _clean(v: Any) -> Any:
    if isinstance(v, float):
        if math.isnan(v) or math.isinf(v):
            return None
        return float(v)
    if isinstance(v, dict):
        return {k: _clean(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [_clean(x) for x in v]
    if hasattr(v, "item"):  # numpy scalars
        return _clean(v.item())
    return v


def make_evidence(
    *,
    family: str,
    metric: str,
    method: str,
    value: Optional[float],
    display_value: Optional[str],
    source_columns: List[str],
    parameters: Optional[Dict[str, Any]] = None,
    quality: str = "acceptable",
    notes: Optional[List[str]] = None,
    payload: Optional[Dict[str, Any]] = None,
    metric_column: Optional[str] = None,
    dimension_column: Optional[str] = None,
) -> Dict[str, Any]:
    params = _clean(parameters or {})
    value = _clean(value)
    return {
        "evidence_id": evidence_id(metric, method, source_columns, params),
        "analysis_family": family,
        "metric": metric,
        "value": value,
        "display_value": display_value,
        "method": method,
        "source_columns": list(source_columns),
        "metric_column": metric_column,
        "dimension_column": dimension_column,
        "parameters": params,
        "quality": {"status": quality, "notes": list(notes or [])},
        "payload": _clean(payload) if payload is not None else None,
    }


def fmt_number(v: Optional[float], unit: Optional[str] = None) -> Optional[str]:
    if v is None or (isinstance(v, float) and (math.isnan(v) or math.isinf(v))):
        return None
    a = abs(v)
    if a >= 1_000_000_000:
        s = f"{v / 1_000_000_000:.2f}B"
    elif a >= 1_000_000:
        s = f"{v / 1_000_000:.2f}M"
    elif a >= 10_000:
        s = f"{v:,.0f}"
    elif a >= 100:
        s = f"{v:,.1f}"
    elif float(v).is_integer():
        s = f"{int(v)}"
    else:
        s = f"{v:.2f}"
    return f"{s} {unit}" if unit else s


def fmt_pct(v: Optional[float], signed: bool = False) -> Optional[str]:
    if v is None or (isinstance(v, float) and (math.isnan(v) or math.isinf(v))):
        return None
    return f"{v * 100:+.1f}%" if signed else f"{v * 100:.1f}%"


def fmt_p(p: Optional[float]) -> Optional[str]:
    if p is None:
        return None
    return "p<0.001" if p < 0.001 else f"p={p:.3f}"
