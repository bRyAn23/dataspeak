"""End-to-end smoke test against the running compose stack (no third-party dependencies).

Flow: register -> confirm email (link fetched from Mailpit) -> create analysis -> upload golden CSV -> validate -> profile -> definitions -> run
-> poll status -> insights -> visualizations -> report -> delete. Exercises Laravel, Redis queue,
the worker, FastAPI and PostgreSQL together. Uses the committed synthetic golden dataset only.

Usage: python tests/e2e/smoke_workflow.py [base-url] [--keep]
Environment: MAILPIT_URL (default http://127.0.0.1:8025) for the verification email.
"""

from __future__ import annotations

import html
import json
import mimetypes
import os
import re
import sys
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

ARGS = [a for a in sys.argv[1:] if not a.startswith("--")]
BASE = ARGS[0] if ARGS else "http://localhost:8000/api/v1"
GOLDEN = Path(__file__).resolve().parents[1].parent / "analytics" / "tests" / "golden" / "retail_sales.csv"
SENTINEL_HEADERS = ("ยอดขาย_thb", "ရောင်းအား_units")
MAILPIT = os.environ.get("MAILPIT_URL", "http://127.0.0.1:8025").rstrip("/")


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401 - stdlib signature
        return None


def confirm_email(email: str) -> str:
    """Finds the verification email in Mailpit, follows its signed link once, returns the redirect target."""
    link = None
    for _ in range(40):
        try:
            with urllib.request.urlopen(f"{MAILPIT}/api/v1/search?query=to:{email}", timeout=10) as resp:
                found = json.loads(resp.read().decode())
        except (urllib.error.URLError, OSError) as exc:
            print(f"FAIL confirm email: Mailpit not reachable at {MAILPIT} ({exc}). Is the mailpit service up?")
            sys.exit(1)
        if found.get("messages"):
            mid = found["messages"][0]["ID"]
            with urllib.request.urlopen(f"{MAILPIT}/api/v1/message/{mid}", timeout=10) as resp:
                msg = json.loads(resp.read().decode())
            body = html.unescape(msg.get("HTML") or "") + "\n" + (msg.get("Text") or "")
            m = re.search(r"https?://[^\s\"'<>\]]+/auth/verify-email/[^\s\"'<>\]]+", body)
            if m:
                link = m.group(0)
                break
        time.sleep(0.5)
    if link is None:
        print("FAIL confirm email: no verification email arrived in Mailpit within 20 s")
        sys.exit(1)
    opener = urllib.request.build_opener(_NoRedirect)
    try:
        resp = opener.open(urllib.request.Request(link, headers={"Accept": "text/html"}), timeout=30)
        status, location = resp.status, resp.headers.get("Location", "")
    except urllib.error.HTTPError as exc:
        status, location = exc.code, exc.headers.get("Location", "")
    if status not in (301, 302) or "verified=1" not in location:
        print(f"FAIL confirm email: expected a redirect with verified=1, got {status} {location}")
        sys.exit(1)
    return location


def call(method: str, path: str, token: str | None = None, body: dict | None = None, file: Path | None = None, headers: dict | None = None):
    url = BASE + path
    hdrs = {"Accept": "application/json"}
    if token:
        hdrs["Authorization"] = f"Bearer {token}"
    hdrs.update(headers or {})
    data = None
    if file is not None:
        boundary = "----dataspeak" + uuid.uuid4().hex
        content = file.read_bytes()
        ctype = mimetypes.guess_type(file.name)[0] or "text/csv"
        data = (
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{file.name}\"\r\n"
            f"Content-Type: {ctype}\r\n\r\n"
        ).encode() + content + f"\r\n--{boundary}--\r\n".encode()
        hdrs["Content-Type"] = f"multipart/form-data; boundary={boundary}"
    elif body is not None:
        data = json.dumps(body).encode()
        hdrs["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, method=method, headers=hdrs)
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            raw = resp.read().decode()
            return resp.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode()
        return exc.code, (json.loads(raw) if raw else {})


def expect(status: int, wanted: int, step: str, payload) -> None:
    if status != wanted:
        print(f"FAIL {step}: expected {wanted}, got {status}: {json.dumps(payload)[:400]}")
        sys.exit(1)
    print(f"ok   {step} -> {status}")


def main() -> None:
    email = f"smoke+{uuid.uuid4().hex[:8]}@example.com"
    s, r = call("POST", "/auth/register", body={"name": "Smoke", "email": email, "password": "smoke-pass-123", "password_confirmation": "smoke-pass-123"})
    expect(s, 201, "register", r)
    token = r["data"]["token"]
    assert r["data"]["user"]["email_verified"] is False, r

    s, r = call("POST", "/analyses", token, {"name": "Too early"})
    expect(s, 403, "create analysis before confirming email", r)
    assert r["error"]["code"] == "EMAIL_NOT_VERIFIED", r
    location = confirm_email(email)
    print(f"ok   confirm email (link from Mailpit) -> redirect {location}")

    s, r = call("POST", "/analyses", token, {"name": "Smoke Retail 2025"})
    expect(s, 201, "create analysis", r)
    aid = r["data"]["id"]

    s, r = call("POST", f"/analyses/{aid}/dataset", token, file=GOLDEN)
    expect(s, 201, "upload", r)
    assert r["data"]["status"] == "UPLOADED", r

    s, r = call("POST", f"/analyses/{aid}/validate", token)
    expect(s, 200, "validate", r)
    assert r["data"]["valid"] and r["data"]["row_count"] == 240, r

    s, r = call("POST", f"/analyses/{aid}/profile", token)
    expect(s, 202, "profile queued", r)
    for _ in range(90):  # PROFILING -> DEFINITION (AI suggestions) -> PROFILED; usually 10-60 s
        time.sleep(2)
        s, r = call("GET", f"/analyses/{aid}/status", token)
        expect(s, 200, f"status ({r['data']['status']} {r['data']['job']['stage'] if r['data'].get('job') else ''} {r['data']['progress']}%)", r)
        job = r["data"].get("job") or {}
        if r["data"]["status"] == "PROFILED":
            break
        if job.get("status") == "FAILED":
            print(f"FAIL profile: {job.get('error')}")
            sys.exit(1)
    else:
        print("FAIL profile: still not PROFILED after 180 s")
        sys.exit(1)
    s, r = call("GET", f"/analyses/{aid}/profile", token)
    expect(s, 200, "profile read", r)
    print(f"     suggestions: {r['data'].get('suggestions')}")
    names = [c["name"] for c in r["data"]["columns"]]
    assert all(h in names for h in SENTINEL_HEADERS), names

    defs = {"definitions": [
        {"column": "order_date", "business_name": "Order Date", "semantic_type": "DATE"},
        {"column": "region", "business_name": "Sales Region", "semantic_type": "CATEGORY"},
        {"column": "product_category", "business_name": "Product Category", "semantic_type": "CATEGORY"},
        {"column": "ยอดขาย_thb", "business_name": "Revenue", "semantic_type": "CURRENCY", "unit": "THB", "aggregation": "SUM"},
        {"column": "ရောင်းအား_units", "business_name": "Units", "semantic_type": "NUMBER", "aggregation": "SUM"},
        {"column": "discount_pct", "business_name": "Discount", "semantic_type": "PERCENTAGE", "aggregation": "AVERAGE"},
        {"column": "customer_id", "business_name": "Customer", "semantic_type": "IDENTIFIER"},
    ]}
    s, r = call("PUT", f"/analyses/{aid}/definitions", token, defs)
    expect(s, 200, "definitions", r)

    s, r = call("POST", f"/analyses/{aid}/run", token, {}, headers={"Idempotency-Key": uuid.uuid4().hex})
    expect(s, 202, "run", r)

    def wait_for(stop_states):
        deadline = time.time() + 900  # includes local AI inference
        while time.time() < deadline:
            s, r = call("GET", f"/analyses/{aid}/status", token)
            expect(s, 200, f"status ({r['data']['status']} {r['data']['progress']}%)", r)
            if r["data"]["is_terminal"] or r["data"]["status"] in stop_states:
                return r["data"]["status"], r
            time.sleep(2)
        return None, r

    # The run stops at ANALYZED for chart review (FEAT-VIS-003) unless AUTO_REPORT is on.
    status, r = wait_for({"ANALYZED"})
    if status not in ("ANALYZED", "COMPLETED"):
        print("FAIL run did not reach ANALYZED:", json.dumps(r)[:600])
        sys.exit(1)
    if status == "ANALYZED":
        s, r = call("GET", f"/analyses/{aid}/visualizations", token)
        expect(s, 200, f"recommended charts ({len(r['data'])})", r)
        keep = [v["id"] for v in r["data"]][:-1] or [v["id"] for v in r["data"]]  # drop the last recommended chart
        s, o = call("GET", f"/analyses/{aid}/visualizations/options", token)
        expect(s, 200, f"chart options ({len(o['data'])})", o)
        add = [o["data"][0]["key"]] if o["data"] else []
        s, r = call("PUT", f"/analyses/{aid}/visualizations/selection", token, {"selected": keep, "add": add})
        expect(s, 200, f"chart selection (kept {len(keep)}, added {len(add)})", r)
        assert any(v["source"] == "USER" for v in r["data"]) == bool(add), r["data"]
        s, r = call("POST", f"/analyses/{aid}/report", token, {}, headers={"Idempotency-Key": uuid.uuid4().hex})
        expect(s, 202, "report queued", r)
        status, r = wait_for(set())
    if status != "COMPLETED":
        print("FAIL pipeline did not reach COMPLETED:", json.dumps(r)[:600])
        sys.exit(1)

    s, r = call("GET", f"/analyses/{aid}", token)
    expect(s, 200, "show", r)
    assert r["data"]["dataset"]["transient_file_present"] is False, "transient file should be purged after the run"

    s, r = call("GET", f"/analyses/{aid}/insights", token)
    expect(s, 200, f"insights ({len(r['data'])})", r)
    assert r["data"] and all(i["evidence_id"] for i in r["data"])
    s, r = call("GET", f"/analyses/{aid}/visualizations", token)
    expect(s, 200, f"visualizations ({len(r['data'])})", r)
    assert any(v["y"].get("unit") == "THB" for v in r["data"]), "unit from definitions missing"

    s, r = call("GET", f"/analyses/{aid}/report", token)
    expect(s, 200, "report read", r)
    assert r["data"]["status"] == "READY" and r["data"]["summary_metrics"], r["data"]
    ai = r["data"]["ai"]
    print(f"     ai status={ai['status']} model={ai.get('model')} prompt={ai.get('prompt_version')} "
          f"claims_total={r['data']['claims_total']} supported={r['data']['claims_supported']}")
    for c in r["data"]["claims"][:5]:
        print(f"     [{c['kind']}] {c['statement']}")
    if ai["status"] == "ready":
        assert r["data"]["claims"], "ready narrative must contain supported claims"
        s, c = call("GET", f"/analyses/{aid}/claims/{r['data']['claims'][0]['id']}", token)
        expect(s, 200, "claim traceability", c)
        assert c["data"]["evidence"] and c["data"]["generation"]["model"], c["data"]

    if "--keep" in sys.argv:
        print(f"KEEP: analysis {aid} left in place for evaluation (php artisan dataspeak:ai-eval {aid})")
        print("E2E SMOKE PASSED")
        return

    s, r = call("DELETE", f"/analyses/{aid}", token)
    expect(s, 204, "delete", r)
    s, r = call("GET", f"/analyses/{aid}", token)
    expect(s, 404, "deleted is gone", r)
    print("E2E SMOKE PASSED")


if __name__ == "__main__":
    main()
