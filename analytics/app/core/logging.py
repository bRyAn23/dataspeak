"""Structured, privacy-safe logging (NFR-OBS-001, FEAT-SEC-002).

Rule: raw CSV rows and cell values are never logged. Only IDs, method names, durations,
counts and safe diagnostics may appear. See docs/conventions/logging-and-privacy.md.
"""

from __future__ import annotations

import json
import logging
import sys
from datetime import datetime, timezone
from typing import Any

from app.core.config import SERVICE_NAME

# Context keys that must never reach a log sink, whatever a caller passes.
FORBIDDEN_KEYS = frozenset({"file", "csv", "rows", "row", "cells", "cell", "content", "payload_rows", "sample"})
SAFE_EXTRA_KEYS = frozenset(
    {
        "analysis_id",
        "request_id",
        "method",
        "duration_ms",
        "row_count",
        "column_count",
        "code",
        "path",
        "status_code",
    }
)


class RedactFilter(logging.Filter):
    """Drops forbidden keys from record extras so a mistaken caller cannot leak data."""

    def filter(self, record: logging.LogRecord) -> bool:
        for key in list(record.__dict__.keys()):
            if key in FORBIDDEN_KEYS:
                record.__dict__[key] = "[redacted]"
        return True


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, Any] = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "service": SERVICE_NAME,
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
        }
        for key in SAFE_EXTRA_KEYS:
            if key in record.__dict__:
                payload[key] = record.__dict__[key]
        if record.exc_info:
            payload["exception"] = record.exc_info[0].__name__ if record.exc_info[0] else "Exception"
        return json.dumps(payload, ensure_ascii=False)


def configure_logging(level: str = "INFO") -> None:
    root = logging.getLogger()
    root.handlers.clear()
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    handler.addFilter(RedactFilter())
    root.addHandler(handler)
    root.setLevel(level.upper())
    # uvicorn access logs would echo query strings; keep them at WARNING.
    logging.getLogger("uvicorn.access").setLevel(logging.WARNING)


def get_logger(name: str) -> logging.Logger:
    return logging.getLogger(name)
