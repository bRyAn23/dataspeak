import os

import pytest
from fastapi.testclient import TestClient

TEST_TOKEN = "test-internal-token"


@pytest.fixture(scope="session", autouse=True)
def _env() -> None:
    os.environ["ANALYTICS_INTERNAL_TOKEN"] = TEST_TOKEN
    os.environ["LOG_LEVEL"] = "DEBUG"
    from app.core.config import get_settings

    get_settings.cache_clear()


@pytest.fixture()
def client() -> TestClient:
    from app.main import create_app

    return TestClient(create_app())


@pytest.fixture()
def auth_headers() -> dict:
    return {"X-Internal-Token": TEST_TOKEN}
