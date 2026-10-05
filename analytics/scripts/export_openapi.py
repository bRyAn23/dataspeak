"""Exports the OpenAPI schema to docs/contracts/fastapi-openapi.json (contract tests freeze it)."""

from __future__ import annotations

import json
import sys
from pathlib import Path

from app.main import create_app

OUT = Path(__file__).resolve().parents[2] / "docs" / "contracts" / "fastapi-openapi.json"


def export(path: Path = OUT) -> dict:
    schema = create_app().openapi()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(schema, indent=2, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8")
    return schema


if __name__ == "__main__":
    export()
    sys.stdout.write(f"wrote {OUT}\n")
