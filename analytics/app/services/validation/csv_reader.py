"""CSV ingestion: encoding/BOM handling, delimiter sniffing, string-typed parse with Polars.

Everything is read as text first so that type inference is explicit and testable
(app/services/profiling/inference.py). No cell values are logged anywhere in this module.
"""

from __future__ import annotations

import csv
import io
from dataclasses import dataclass, field
from pathlib import Path
from typing import List, Optional, Tuple

import polars as pl

from app.core.errors import AnalyticsError

DELIMITERS = [",", ";", "\t", "|"]
SNIFF_BYTES = 65536
NULL_TOKENS = ["", "na", "n/a", "null", "none", "nan", "-", "--"]


@dataclass
class ParsedDataset:
    df: pl.DataFrame
    delimiter: str
    encoding: str
    had_bom: bool
    duplicate_headers: List[str] = field(default_factory=list)
    empty_headers: int = 0
    raw_row_count: int = 0

    @property
    def row_count(self) -> int:
        return self.df.height

    @property
    def column_count(self) -> int:
        return self.df.width


def _decode(raw: bytes) -> Tuple[str, str, bool]:
    if raw.startswith(b"\xef\xbb\xbf"):
        return raw[3:].decode("utf-8", errors="strict"), "utf-8", True
    if raw.startswith(b"\xff\xfe") or raw.startswith(b"\xfe\xff"):
        return raw.decode("utf-16"), "utf-16", True
    try:
        return raw.decode("utf-8"), "utf-8", False
    except UnicodeDecodeError:
        # Last resort for legacy exports; Thai/Myanmar text would be mangled, so flag it.
        return raw.decode("latin-1"), "latin-1", False


def sniff_delimiter(sample: str) -> str:
    try:
        dialect = csv.Sniffer().sniff(sample[:SNIFF_BYTES], delimiters="".join(DELIMITERS))
        return dialect.delimiter
    except csv.Error:
        header = sample.splitlines()[0] if sample else ""
        counts = {d: header.count(d) for d in DELIMITERS}
        best = max(counts, key=lambda d: counts[d])
        return best if counts[best] > 0 else ","


def read_csv_bytes(raw: bytes) -> ParsedDataset:
    if len(raw.strip()) == 0:
        raise AnalyticsError("ANALYTICS_INVALID_DATASET", "The file is empty.")

    text, encoding, had_bom = _decode(raw)
    delimiter = sniff_delimiter(text)

    header_line = text.splitlines()[0] if text.splitlines() else ""
    header_cells = next(csv.reader([header_line], delimiter=delimiter), [])
    cleaned = [h.strip() for h in header_cells]
    empty_headers = sum(1 for h in cleaned if h == "")
    seen: dict = {}
    duplicates: List[str] = []
    for h in cleaned:
        if h and h in seen:
            duplicates.append(h)
        seen[h] = True

    try:
        df = pl.read_csv(
            io.BytesIO(text.encode("utf-8")),
            separator=delimiter,
            has_header=True,
            infer_schema=False,  # everything as text; inference is ours
            null_values=NULL_TOKENS,
            truncate_ragged_lines=True,
            quote_char='"',
            encoding="utf8",
            try_parse_dates=False,
        )
    except Exception as exc:  # noqa: BLE001 - polars raises several exception types
        raise AnalyticsError("ANALYTICS_INVALID_DATASET", "The CSV could not be parsed.") from exc

    # Normalise header whitespace; keep Unicode intact.
    df = df.rename({c: c.strip() for c in df.columns if c != c.strip()})
    df = df.with_columns([pl.col(c).str.strip_chars() for c in df.columns])
    df = df.with_columns(
        [
            pl.when(pl.col(c).str.to_lowercase().is_in(NULL_TOKENS)).then(None).otherwise(pl.col(c)).alias(c)
            for c in df.columns
        ]
    )

    return ParsedDataset(
        df=df,
        delimiter=delimiter,
        encoding=encoding,
        had_bom=had_bom,
        duplicate_headers=sorted(set(duplicates)),
        empty_headers=empty_headers,
        raw_row_count=max(0, len([ln for ln in text.splitlines() if ln.strip()]) - 1),
    )


def read_csv_path(path: Path, max_bytes: Optional[int] = None) -> ParsedDataset:
    size = path.stat().st_size
    if max_bytes is not None and size > max_bytes:
        raise AnalyticsError("ANALYTICS_PAYLOAD_TOO_LARGE", "The dataset exceeds the configured size limit.")
    return read_csv_bytes(path.read_bytes())
