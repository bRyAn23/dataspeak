"""PY-API-006 POST /v1/visualizations/recommend - specifications only."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.api.v1.deps import Timer, log
from app.core.security import require_internal_token
from app.schemas.requests import VisualizationRequest
from app.schemas.responses import VisualizationResponse
from app.services.visualization.recommender import recommend

router = APIRouter(prefix="/v1", tags=["visualizations"], dependencies=[Depends(require_internal_token)])


@router.post("/visualizations/recommend", response_model=VisualizationResponse)
def recommend_visualizations(body: VisualizationRequest) -> VisualizationResponse:
    timer = Timer()
    charts = recommend(body.evidence, body.insights, body.definitions)
    log.info(
        "visualizations",
        extra={
            "analysis_id": body.analysis_id,
            "method": "visualizations",
            "duration_ms": timer.ms,
            "row_count": len(charts),
        },
    )
    return VisualizationResponse(visualizations=charts)
