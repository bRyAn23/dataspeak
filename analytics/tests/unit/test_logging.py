"""Log-leak guard: a sentinel cell value must never reach the log stream."""

import io
import json
import logging

from app.core.logging import JsonFormatter, RedactFilter

SENTINEL = "SENTINEL-CELL-ค่า-တန်ဖိုး-9471"


def _capture(record_fn) -> str:
    stream = io.StringIO()
    handler = logging.StreamHandler(stream)
    handler.setFormatter(JsonFormatter())
    handler.addFilter(RedactFilter())
    logger = logging.getLogger("leak-test")
    logger.handlers = [handler]
    logger.setLevel(logging.DEBUG)
    logger.propagate = False
    record_fn(logger)
    return stream.getvalue()


def test_forbidden_keys_are_redacted() -> None:
    out = _capture(lambda log: log.info("profiled", extra={"analysis_id": "a1", "rows": [SENTINEL], "file": SENTINEL}))
    assert SENTINEL not in out
    line = json.loads(out.strip())
    assert line["analysis_id"] == "a1"
    assert "rows" not in line and "file" not in line


def test_unknown_extra_keys_are_not_emitted() -> None:
    out = _capture(lambda log: log.info("x", extra={"something_else": SENTINEL}))
    assert SENTINEL not in out


def test_output_is_json_lines_with_service_field() -> None:
    out = _capture(lambda log: log.warning("hello"))
    line = json.loads(out.strip())
    assert line["service"] == "dataspeak-analytics"
    assert line["level"] == "WARNING"
