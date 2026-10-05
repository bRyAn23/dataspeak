"""PY-API-003 structural and basic data-quality validation (FEAT-UPL-002)."""

from __future__ import annotations

from typing import List

from app.schemas.common import Finding
from app.schemas.responses import ValidateResponse
from app.services.analysis import rules
from app.services.validation.csv_reader import ParsedDataset


def validate_dataset(parsed: ParsedDataset) -> ValidateResponse:
    errors: List[Finding] = []
    warnings: List[Finding] = []
    df = parsed.df

    if df.width == 0:
        errors.append(Finding(code="NO_HEADER", message="Header row is missing."))
    if parsed.empty_headers:
        errors.append(
            Finding(
                code="EMPTY_COLUMN_NAME",
                message=f"{parsed.empty_headers} column header(s) are empty.",
            )
        )
    for name in parsed.duplicate_headers:
        errors.append(Finding(code="DUPLICATE_COLUMN", message="Duplicate column header.", column=name))
    if df.width > 0 and df.height == 0:
        errors.append(Finding(code="NO_DATA_ROWS", message="The file contains a header but no data rows."))

    if parsed.encoding == "latin-1":
        warnings.append(
            Finding(
                code="ENCODING_FALLBACK",
                message="The file is not valid UTF-8; non-ASCII text may be mangled.",
            )
        )
    if df.height > 0:
        dupes = df.height - df.unique().height
        if dupes > 0:
            warnings.append(Finding(code="DUPLICATE_ROWS", message=f"{dupes} duplicate row(s) found."))
        if parsed.raw_row_count > df.height:
            warnings.append(
                Finding(
                    code="RAGGED_ROWS",
                    message=f"{parsed.raw_row_count - df.height} row(s) could not be aligned to the header; dropped.",
                )
            )
        for col in df.columns:
            null_rate = df[col].null_count() / df.height
            if null_rate >= rules.NULL_INSUFFICIENT:
                warnings.append(
                    Finding(
                        code="MOSTLY_EMPTY_COLUMN",
                        message=f"{null_rate:.0%} of values are missing.",
                        column=col,
                    )
                )
            elif null_rate >= 0.5:
                warnings.append(
                    Finding(
                        code="HIGH_NULL_RATE",
                        message=f"{null_rate:.0%} of values are missing.",
                        column=col,
                    )
                )
            if df.height > 1 and df[col].n_unique() <= 1:
                warnings.append(Finding(code="CONSTANT_COLUMN", message="The column has a single value.", column=col))

    return ValidateResponse(
        valid=len(errors) == 0,
        row_count=df.height,
        column_count=df.width,
        columns=list(df.columns),
        warnings=warnings,
        errors=errors,
    )
