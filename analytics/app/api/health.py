"""PY-API-001 GET /health - unauthenticated readiness endpoint."""

from fastapi import APIRouter

from app.core.config import SERVICE_NAME, SERVICE_VERSION
from app.schemas.health import HealthResponse

router = APIRouter(tags=["health"])


@router.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    return HealthResponse(status="ok", service=SERVICE_NAME, version=SERVICE_VERSION)
