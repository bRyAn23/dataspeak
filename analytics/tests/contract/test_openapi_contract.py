"""Freezes the JSON shapes Laravel depends on. Regenerate with: python -m scripts.export_openapi"""

import json
import os
from pathlib import Path

from scripts.export_openapi import OUT, export

REQUIRED_PATHS = {
    "/health",
    "/v1/validate",
    "/v1/profile",
    "/v1/analyze",
    "/v1/insights",
    "/v1/visualizations/recommend",
}


def test_openapi_file_matches_application():
    current = export(
        Path(os.environ.get("CONTRACT_OUT", str(OUT)))
        if os.environ.get("UPDATE_CONTRACT")
        else OUT.with_suffix(".tmp.json")
    )
    try:
        assert REQUIRED_PATHS <= set(current["paths"])
        assert OUT.exists(), "run: python -m scripts.export_openapi"
        frozen = json.loads(OUT.read_text(encoding="utf-8"))
        assert frozen == current, "OpenAPI changed; regenerate docs/contracts/fastapi-openapi.json and review the diff"
    finally:
        tmp = OUT.with_suffix(".tmp.json")
        if tmp.exists():
            tmp.unlink()


def test_evidence_schema_has_required_fields():
    schema = export(OUT.with_suffix(".tmp2.json"))
    OUT.with_suffix(".tmp2.json").unlink()
    evidence = schema["components"]["schemas"]["Evidence"]
    for field in (
        "evidence_id",
        "metric",
        "value",
        "display_value",
        "method",
        "source_columns",
        "parameters",
        "quality",
    ):
        assert field in evidence["properties"]
    assert set(evidence["required"]) >= {
        "evidence_id",
        "analysis_family",
        "metric",
        "method",
        "source_columns",
        "quality",
    }
