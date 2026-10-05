"""PY-API-003 POST /v1/validate"""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, Form, UploadFile

from app.api.v1.deps import Timer, log, parse_metadata, settings_dep, spool_upload
from app.core.config import Settings
from app.core.security import require_internal_token
from app.schemas.responses import ValidateResponse
from app.services.validation.csv_reader import read_csv_path
from app.services.validation.validator import validate_dataset

router = APIRouter(prefix="/v1", tags=["validation"], dependencies=[Depends(require_internal_token)])


@router.post("/validate", response_model=ValidateResponse)
def validate(
    file: UploadFile = File(...), metadata: str = Form(...), settings: Settings = Depends(settings_dep)
) -> ValidateResponse:
    meta = parse_metadata(metadata)
    timer = Timer()
    for _tmp, path in spool_upload(file, settings):
        parsed = read_csv_path(path, settings.max_upload_bytes)
        result = validate_dataset(parsed)
    log.info(
        "validate",
        extra={
            "analysis_id": meta.analysis_id,
            "method": "validate",
            "duration_ms": timer.ms,
            "row_count": result.row_count,
            "column_count": result.column_count,
        },
    )
    return result
