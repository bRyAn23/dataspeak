"""Resolve each column's analytical role from the user's definition first, then inference.

Definitions override inference (FastAPI prompt Section 7). TEXT / IDENTIFIER / IGNORE columns are
excluded from statistics; their values never appear in evidence.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Dict, List, Optional

from app.schemas.common import FieldDefinition
from app.services.analysis import rules
from app.services.profiling.inference import TypedColumn

MEASURE_SEMANTICS = {"NUMBER", "CURRENCY", "PERCENTAGE"}
DIMENSION_SEMANTICS = {"CATEGORY", "BOOLEAN"}
TIME_SEMANTICS = {"DATE"}
EXCLUDED_SEMANTICS = {"TEXT", "IDENTIFIER", "IGNORE"}


@dataclass
class ColumnRole:
    name: str
    role: str  # measure | dimension | time | excluded
    typed: TypedColumn
    label: str
    unit: Optional[str] = None
    semantic_type: Optional[str] = None
    aggregation: Optional[str] = None

    @property
    def sums(self) -> bool:
        """True when totals are the meaningful aggregate (SUM/COUNT, or currency without a stated aggregation)."""
        if self.aggregation in ("SUM", "COUNT"):
            return True
        if self.aggregation in ("AVERAGE", "MIN", "MAX", "NONE"):
            return False
        return self.semantic_type == "CURRENCY"


def resolve_roles(typed: Dict[str, TypedColumn], definitions: List[FieldDefinition]) -> Dict[str, ColumnRole]:
    defs = {d.column: d for d in definitions}
    roles: Dict[str, ColumnRole] = {}
    for name, col in typed.items():
        d = defs.get(name)
        label = d.business_name if d and d.business_name else name
        if d is not None:
            st = d.semantic_type
            if st in MEASURE_SEMANTICS and col.inferred_type in ("numeric", "integer"):
                role = "measure"
            elif st in MEASURE_SEMANTICS:
                role = "excluded"  # defined as a number but the values are not numeric
            elif st in TIME_SEMANTICS and col.inferred_type in ("date", "datetime"):
                role = "time"
            elif st in DIMENSION_SEMANTICS and col.distinct_count <= rules.MAX_GROUPS:
                role = "dimension"
            else:
                role = "excluded"
            roles[name] = ColumnRole(name, role, col, label, d.unit, st, d.aggregation)
            continue

        if col.inferred_type in ("numeric", "integer"):
            role = "measure"
        elif col.inferred_type in ("date", "datetime"):
            role = "time"
        elif col.inferred_type in ("category", "boolean") and col.distinct_count <= rules.MAX_GROUPS:
            role = "dimension"
        else:
            role = "excluded"
        roles[name] = ColumnRole(name, role, col, label)
    return roles


def by_role(roles: Dict[str, ColumnRole], role: str, limit: Optional[int] = None) -> List[ColumnRole]:
    items = [r for r in roles.values() if r.role == role]
    items.sort(key=lambda r: r.typed.position)
    return items[:limit] if limit else items
