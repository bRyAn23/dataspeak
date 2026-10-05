"""PY-API-005 POST /v1/insights - consumes derived evidence, never a CSV."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.api.v1.deps import Timer, log
from app.core.security import require_internal_token
from app.schemas.requests import InsightsRequest
from app.schemas.responses import InsightsResponse
from app.services.insights.detector import detect

router = APIRouter(prefix="/v1", tags=["insights"], dependencies=[Depends(require_internal_token)])


@router.post("/insights", response_model=InsightsResponse)
def insights(body: InsightsRequest) -> InsightsResponse:
    timer = Timer()
    found = detect(body.evidence, body.definitions, body.options.max_insights)
    log.info(
        "insights",
        extra={"analysis_id": body.analysis_id, "method": "insights", "duration_ms": timer.ms, "row_count": len(found)},
    )
    return InsightsResponse(insights=found)
