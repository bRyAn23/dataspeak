"""PY-API-001 health contract (FastAPI API Documentation, Section 4)."""


def test_py_api_001_health_contract(client) -> None:
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "dataspeak-analytics", "version": "1.0"}


def test_root_route_is_not_served(client) -> None:
    assert client.get("/").status_code == 404
