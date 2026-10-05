"""Data Speak internal deterministic analytics service.

Boundary (Specification Section 5): validation, profiling, deterministic statistics, evidence,
insight and visualization logic. It does not own user auth, workflow state, or call any LLM.
"""

from fastapi import FastAPI

from app.api.health import router as health_router
from app.api.v1.analyze import router as analyze_router
from app.api.v1.insights import router as insights_router
from app.api.v1.profile import router as profile_router
from app.api.v1.validate import router as validate_router
from app.api.v1.visualizations import router as visualizations_router
from app.core.config import SERVICE_NAME, SERVICE_VERSION, get_settings
from app.core.errors import install_error_handlers
from app.core.logging import configure_logging
from app.schemas.common import ErrorResponse

ERROR_RESPONSES = {
    401: {"model": ErrorResponse},
    413: {"model": ErrorResponse},
    422: {"model": ErrorResponse},
    500: {"model": ErrorResponse},
}


def create_app() -> FastAPI:
    settings = get_settings()
    configure_logging(settings.log_level)
    app = FastAPI(
        title="Data Speak Analytics API",
        version=SERVICE_VERSION,
        description=f"{SERVICE_NAME}: internal deterministic analytics service, called by Laravel only.",
        docs_url="/docs",
        redoc_url=None,
        responses=ERROR_RESPONSES,
    )
    install_error_handlers(app)
    app.include_router(health_router)
    for router in (validate_router, profile_router, analyze_router, insights_router, visualizations_router):
        app.include_router(router)
    return app


app = create_app()
