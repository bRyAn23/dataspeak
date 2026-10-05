import io
import json
import logging
from pathlib import Path

from app.utils.tempfiles import tempdir_entries

GOLDEN = Path(__file__).resolve().parents[1] / "golden"
SENTINEL = "SENTINEL-CELL-ค่า-တန်ဖိုး-9471"


def multipart(content: bytes, analysis_id="a-1", definitions=None, name="data.csv"):
    meta = {"analysis_id": analysis_id, "definitions": definitions or []}
    return {"files": {"file": (name, io.BytesIO(content), "text/csv")}, "data": {"metadata": json.dumps(meta)}}


def test_v1_routes_require_token(client):
    r = client.post("/v1/validate", **multipart(b"a,b\n1,2\n"))
    assert r.status_code == 401 and r.json()["error"]["code"] == "ANALYTICS_UNAUTHORIZED"
    assert client.post("/v1/insights", json={"analysis_id": "a", "evidence": []}).status_code == 401


def test_validate_profile_analyze_roundtrip(client, auth_headers, tmp_path, monkeypatch):
    monkeypatch.setenv("DATASPEAK_TMP_DIR", str(tmp_path))
    from app.core.config import get_settings

    get_settings.cache_clear()
    raw = (GOLDEN / "retail_sales.csv").read_bytes()

    v = client.post("/v1/validate", headers=auth_headers, **multipart(raw))
    assert v.status_code == 200 and v.json()["valid"] and v.json()["row_count"] == 240

    p = client.post("/v1/profile", headers=auth_headers, **multipart(raw))
    assert p.status_code == 200
    names = [c["name"] for c in p.json()["columns"]]
    assert "ยอดขาย_thb" in names and "ရောင်းအား_units" in names

    defs = [
        {"column": "ยอดขาย_thb", "semantic_type": "CURRENCY", "unit": "THB", "aggregation": "SUM"},
        {"column": "region", "semantic_type": "CATEGORY"},
        {"column": "order_date", "semantic_type": "DATE"},
    ]
    a = client.post("/v1/analyze", headers=auth_headers, **multipart(raw, definitions=defs))
    assert a.status_code == 200
    body = a.json()
    assert body["analysis_id"] == "a-1" and body["summary"]["evidence_count"] == len(body["evidence"]) > 10

    i = client.post(
        "/v1/insights",
        headers=auth_headers,
        json={"analysis_id": "a-1", "evidence": body["evidence"], "definitions": defs},
    )
    assert i.status_code == 200 and i.json()["insights"]

    z = client.post(
        "/v1/visualizations/recommend",
        headers=auth_headers,
        json={
            "analysis_id": "a-1",
            "evidence": body["evidence"],
            "insights": i.json()["insights"],
            "definitions": defs,
        },
    )
    assert z.status_code == 200 and z.json()["visualizations"]
    assert tempdir_entries(str(tmp_path)) == []  # cleanup on success


def test_temp_files_are_removed_on_failure(client, auth_headers, tmp_path, monkeypatch):
    monkeypatch.setenv("DATASPEAK_TMP_DIR", str(tmp_path))
    from app.core.config import get_settings

    get_settings.cache_clear()
    r = client.post("/v1/analyze", headers=auth_headers, **multipart(b"a,a\n1,2\n"))
    assert r.status_code == 422 and r.json()["error"]["code"] == "ANALYTICS_INVALID_DATASET"
    assert tempdir_entries(str(tmp_path)) == []

    monkeypatch.setattr("app.api.v1.analyze.analyze_frame", lambda *a, **k: (_ for _ in ()).throw(RuntimeError("boom")))
    r = client.post("/v1/analyze", headers=auth_headers, **multipart(b"a,b\n1,2\n3,4\n"))
    assert r.status_code == 500 and r.json()["error"]["code"] == "ANALYTICS_METHOD_FAILED"
    assert tempdir_entries(str(tmp_path)) == []


def test_errors_never_echo_cell_values_and_edge_cases(client, auth_headers):
    empty = client.post("/v1/validate", headers=auth_headers, **multipart(b""))
    assert empty.status_code == 422 and SENTINEL not in empty.text
    header_only = client.post("/v1/validate", headers=auth_headers, **multipart(b"a,b\n"))
    assert header_only.status_code == 200 and not header_only.json()["valid"]
    bad_meta = client.post(
        "/v1/validate",
        headers=auth_headers,
        files={"file": ("d.csv", io.BytesIO(b"a\n1\n"), "text/csv")},
        data={"metadata": "{not json"},
    )
    assert bad_meta.status_code == 422
    rows = client.post("/v1/analyze", headers=auth_headers, **multipart(f"a,b\n{SENTINEL},2\n".encode()))
    assert SENTINEL not in rows.text or rows.status_code == 200


def test_payload_limit_is_enforced(client, auth_headers, monkeypatch):
    monkeypatch.setenv("MAX_UPLOAD_MB", "1")
    from app.core.config import get_settings

    get_settings.cache_clear()
    big = b"a,b\n" + b"1,2\n" * 400_000  # ~1.6 MB
    r = client.post("/v1/validate", headers=auth_headers, **multipart(big))
    assert r.status_code == 413 and r.json()["error"]["code"] == "ANALYTICS_PAYLOAD_TOO_LARGE"
    monkeypatch.delenv("MAX_UPLOAD_MB")
    get_settings.cache_clear()


def test_log_leak_guard_through_the_api(client, auth_headers, caplog):
    caplog.set_level(logging.DEBUG)
    raw = f"region,amount,note\nN,10,{SENTINEL}\nS,20,{SENTINEL}\nN,30,{SENTINEL}\n".encode()
    for path in ("/v1/validate", "/v1/profile", "/v1/analyze"):
        r = client.post(path, headers=auth_headers, **multipart(raw))
        assert r.status_code == 200, (path, r.text)
    assert SENTINEL not in caplog.text
    assert "analysis_id" in caplog.text or any("analysis_id" in str(r.__dict__) for r in caplog.records)


def test_insights_endpoint_rejects_unknown_fields(client, auth_headers):
    r = client.post("/v1/insights", headers=auth_headers, json={"analysis_id": "a", "evidence": [], "rows": [[1, 2]]})
    assert r.status_code == 422
