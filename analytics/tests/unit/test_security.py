"""Internal token dependency: /health open, everything else requires X-Internal-Token."""

from app.core.errors import install_error_handlers
from app.core.security import require_internal_token
from fastapi import APIRouter, Depends, FastAPI
from fastapi.testclient import TestClient


def _app() -> TestClient:
    app = FastAPI()
    install_error_handlers(app)
    router = APIRouter(dependencies=[Depends(require_internal_token)])

    @router.get("/v1/ping")
    def ping() -> dict:
        return {"ok": True}

    app.include_router(router)
    return TestClient(app)


def test_missing_token_is_rejected_with_structured_error() -> None:
    response = _app().get("/v1/ping")
    assert response.status_code == 401
    assert response.json()["error"]["code"] == "ANALYTICS_UNAUTHORIZED"


def test_wrong_token_is_rejected() -> None:
    assert _app().get("/v1/ping", headers={"X-Internal-Token": "nope"}).status_code == 401


def test_correct_token_is_accepted(auth_headers) -> None:
    assert _app().get("/v1/ping", headers=auth_headers).status_code == 200
