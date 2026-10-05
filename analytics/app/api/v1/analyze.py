"""PY-API-004 POST /v1/analyze"""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, Form, UploadFile

from app.api.v1.deps import Timer, log, parse_metadata, settings_dep, spool_upload
from app.core.config import Settings
from app.core.errors import AnalyticsError
from app.core.security import require_internal_token
from app.schemas.responses import AnalyzeResponse
from app.services.analysis.engine import analyze_frame
from app.services.validation.csv_reader import read_csv_path
from app.services.validation.validator import validate_dataset

router = APIRouter(prefix="/v1", tags=["analysis"], dependencies=[Depends(require_internal_token)])


@router.post("/analyze", response_model=AnalyzeResponse)
def analyze(
    file: UploadFile = File(...), metadata: str = Form(...), settings: Settings = Depends(settings_dep)
) -> AnalyzeResponse:
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
        if parsed.df.height == 0:
            raise AnalyticsError("ANALYTICS_INSUFFICIENT_DATA", "The dataset has no data rows.")
        try:
            evidence, summary = analyze_frame(parsed.df, meta.definitions, meta.options, meta.analysis_id)
        except AnalyticsError:
            raise
        except Exception as exc:  # noqa: BLE001
            log.error("analyze.method_failed", extra={"analysis_id": meta.analysis_id, "method": type(exc).__name__})
            raise AnalyticsError(
                "ANALYTICS_METHOD_FAILED", "An approved statistical method could not complete."
            ) from exc
    log.info(
        "analyze",
        extra={
            "analysis_id": meta.analysis_id,
            "method": "analyze",
            "duration_ms": timer.ms,
            "row_count": parsed.df.height,
            "column_count": parsed.df.width,
        },
    )
    return AnalyzeResponse(analysis_id=meta.analysis_id, evidence=evidence, summary=summary)
