"""Explicit, testable column type inference over text columns.

Order: numeric -> boolean -> date/datetime -> category -> identifier -> text.
Thresholds live in app/services/analysis/rules.py.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Dict, List, Optional

import polars as pl

from app.services.analysis import rules

DATE_FORMATS = [
    "%Y-%m-%d",
    "%d/%m/%Y",
    "%m/%d/%Y",
    "%Y/%m/%d",
    "%d-%m-%Y",
    "%d.%m.%Y",
    "%Y%m%d",
    "%b %d, %Y",
    "%d %b %Y",
]
DATETIME_FORMATS = [
    "%Y-%m-%dT%H:%M:%S",
    "%Y-%m-%d %H:%M:%S",
    "%Y-%m-%dT%H:%M:%S%.f",
    "%Y-%m-%d %H:%M",
    "%d/%m/%Y %H:%M",
    "%Y-%m-%dT%H:%M:%SZ",
]
BOOL_TRUE = {"true", "yes", "y", "1", "t"}
BOOL_FALSE = {"false", "no", "n", "0", "f"}


@dataclass
class TypedColumn:
    name: str
    position: int
    inferred_type: str  # lowercase contract value
    series: pl.Series  # converted values (Float64 / Date / Datetime / Boolean / Utf8)
    null_count: int
    distinct_count: int
    parse_failures: int = 0

    @property
    def total(self) -> int:
        return len(self.series)

    @property
    def null_rate(self) -> float:
        return self.null_count / self.total if self.total else 0.0


def _numeric(s: pl.Series) -> Optional[pl.Series]:
    """Parse numbers after removing thousands separators, one leading currency symbol and a trailing %.

    Letters are never stripped, so identifiers such as C01000 are not mistaken for numbers.
    """
    cleaned = s.str.replace_all(r"[,\s]", "").str.replace_all(r"^[$€£¥฿₹₭]", "").str.replace_all(r"%$", "")
    return cleaned.cast(pl.Float64, strict=False)


def _dates(s: pl.Series, formats: List[str], dtype: str) -> Optional[pl.Series]:
    best = None
    best_ok = -1
    for fmt in formats:
        try:
            if dtype == "date":
                parsed = s.str.to_date(fmt, strict=False)
            else:
                parsed = s.str.to_datetime(fmt, strict=False)
        except Exception:  # noqa: BLE001
            continue
        ok = parsed.drop_nulls().len()
        if ok > best_ok:
            best, best_ok = parsed, ok
    return best


def infer_column(name: str, position: int, s: pl.Series) -> TypedColumn:
    total = len(s)
    non_null = s.drop_nulls()
    n = len(non_null)
    null_count = total - n
    if n == 0:
        return TypedColumn(name, position, "unknown", s, null_count, 0)

    distinct = non_null.n_unique()

    numeric = _numeric(non_null)
    if numeric is not None:
        ok = numeric.drop_nulls().len()
        if ok / n >= rules.TYPE_MATCH_SHARE and not (
            distinct <= 2
            and set(non_null.str.to_lowercase().unique().to_list()) <= (BOOL_TRUE | BOOL_FALSE) - {"1", "0"}
        ):
            values = numeric.drop_nulls()
            is_int = bool((values == values.floor()).all()) if ok else False
            full = _numeric(s)
            # Numeric columns with very few distinct values behave like categories, except when the
            # user later defines them otherwise (definitions override inference).
            kind = "integer" if is_int else "numeric"
            return TypedColumn(name, position, kind, full, null_count, distinct, parse_failures=n - ok)

    lowered = set(non_null.str.to_lowercase().unique().to_list())
    if distinct <= 2 and lowered <= (BOOL_TRUE | BOOL_FALSE):
        full = s.str.to_lowercase().is_in(list(BOOL_TRUE))
        full = pl.when(s.is_null()).then(None).otherwise(full).alias(name)
        return TypedColumn(name, position, "boolean", full, null_count, distinct)

    for formats, dtype in ((DATE_FORMATS, "date"), (DATETIME_FORMATS, "datetime")):
        parsed = _dates(non_null, formats, dtype)
        if parsed is not None and parsed.drop_nulls().len() / n >= rules.TYPE_MATCH_SHARE:
            full = _dates(s, formats, dtype)
            if full is None:  # pragma: no cover - same formats parsed the non-null subset
                continue
            return TypedColumn(
                name,
                position,
                dtype,
                full,
                null_count,
                distinct,
                parse_failures=n - parsed.drop_nulls().len(),
            )

    # Category: few distinct values that actually repeat (or a handful of values in a tiny sample).
    repeats = distinct <= rules.CATEGORY_MIN_DISTINCT_NO_REPEAT or distinct * 2 <= n
    if (distinct <= rules.CATEGORY_MAX_DISTINCT and repeats) or (
        distinct / n <= rules.CATEGORY_MAX_RATIO and distinct <= rules.CATEGORY_HARD_CAP
    ):
        return TypedColumn(name, position, "category", s, null_count, distinct)
    if n >= rules.IDENTIFIER_MIN_ROWS and distinct / n >= rules.IDENTIFIER_UNIQUE_RATIO:
        return TypedColumn(name, position, "identifier", s, null_count, distinct)
    return TypedColumn(name, position, "text", s, null_count, distinct)


def infer_frame(df: pl.DataFrame) -> Dict[str, TypedColumn]:
    return {name: infer_column(name, i, df[name]) for i, name in enumerate(df.columns)}
