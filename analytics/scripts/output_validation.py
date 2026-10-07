# ruff: noqa: T201  (CLI script: printing the evidence is its job)
"""Output-validation evidence: run the real CSV validator and profiler on sample files.

Usage:
    python scripts/output_validation.py                # uses the built-in valid + invalid samples
    python scripts/output_validation.py a.csv b.csv    # validate your own files

For every file it prints the fields required by the assignment's Output Validation section.
A file that fails validation stops before profiling, exactly as the API does (PY-API-003 -> PY-API-004).
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from app.services.profiling.profiler import profile_frame  # noqa: E402
from app.services.validation.csv_reader import read_csv_path  # noqa: E402
from app.services.validation.validator import validate_dataset  # noqa: E402

NUMERIC_TYPES = {"integer", "numeric"}

VALID_SAMPLE = """order_id,order_date,region,product,quantity,unit_price,revenue
1001,2026-01-05,North,Widget,4,12.50,50.00
1002,2026-01-06,South,Gadget,2,30.00,60.00
1003,2026-01-07,East,Widget,,12.50,
1004,2026-01-08,West,Gizmo,1,99.00,99.00
1005,2026-01-09,North,Gadget,3,30.00,90.00
1002,2026-01-06,South,Gadget,2,30.00,60.00
1006,2026-01-10,East,Gizmo,5,99.00,495.00
1007,2026-01-11,South,Widget,2,12.50,25.00
"""

INVALID_SAMPLE = """order_id,region,,region
1001,North,4,North
1002,South,2,South
"""


def report(path: Path) -> None:
    print(f"File: {path.name}")
    try:
        parsed = read_csv_path(path)
    except Exception as exc:  # unreadable or empty file
        print("Validation Status: Failed")
        print(f"Validation Message: {exc}")
        print("Processing stopped before statistical analysis.\n")
        return

    result = validate_dataset(parsed)
    if not result.valid:
        print("Validation Status: Failed")
        for err in result.errors:
            where = f" (column: {err.column})" if err.column else ""
            print(f"Validation Message: {err.code} - {err.message}{where}")
        print("Processing stopped before statistical analysis.\n")
        return

    profile = profile_frame(parsed.df)
    missing = sum(c.null_count for c in profile.columns)
    numeric = [c.name for c in profile.columns if c.inferred_type in NUMERIC_TYPES]

    print("Validation Status: Passed")
    print(f"Total Rows: {profile.dataset.rows}")
    print(f"Total Columns: {profile.dataset.columns}")
    print(f"Missing Values: {missing}")
    print(f"Duplicate Rows: {profile.dataset.duplicate_rows}")
    print(f"Numeric Columns: {', '.join(numeric) if numeric else 'none'}")
    for warn in result.warnings:
        where = f" (column: {warn.column})" if warn.column else ""
        print(f"Warning: {warn.code} - {warn.message}{where}")
    print()


def main(argv: list[str]) -> int:
    if argv:
        paths = [Path(a) for a in argv]
    else:
        out = ROOT / "tmp" / "output_validation"
        out.mkdir(parents=True, exist_ok=True)
        valid, invalid = out / "sample_valid.csv", out / "sample_invalid.csv"
        valid.write_text(VALID_SAMPLE, encoding="utf-8")
        invalid.write_text(INVALID_SAMPLE, encoding="utf-8")
        paths = [valid, invalid]
    for p in paths:
        report(p)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
