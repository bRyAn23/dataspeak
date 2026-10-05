"""PY-API-002 POST /v1/profile"""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, Form, UploadFile

from app.api.v1.deps import Timer, log, parse_metadata, settings_dep, spool_upload
from app.core.config import Settings
from app.core.errors import AnalyticsError
from app.core.security import require_internal_token
from app.schemas.responses import ProfileResponse
from app.services.profiling.profiler import profile_frame
from app.services.validation.csv_reader import read_csv_path
from app.services.validation.validator import validate_dataset

router = APIRouter(prefix="/v1", tags=["profiling"], dependencies=[Depends(require_internal_token)])


@router.post("/profile", response_model=ProfileResponse)
def profile(
    file: UploadFile = File(...), metadata: str = Form(...), settings: Settings = Depends(settings_dep)
) -> ProfileResponse:
    meta = parse_metadata(metadata)
    timer = Timer()
    for _tmp, path in spool_upload(file, settings):
        parsed = read_csv_path(path, settings.max_upload_bytes)
        check = validate_dataset(parsed)
        if not check.valid:
            raise AnalyticsError(
                "ANALYTICS_INVALID_DATASET",
                "The dataset failed structural validation.",
                details=[{"field": e.column or "file", "issue": e.message} for e in check.errors],
            )
        result = profile_frame(parsed.df)
    log.info(
        "profile",
        extra={
            "analysis_id": meta.analysis_id,
            "method": "profile",
            "duration_ms": timer.ms,
            "row_count": result.dataset.rows,
            "column_count": result.dataset.columns,
        },
    )
    return result
