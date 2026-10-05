"""Structured error contract (FastAPI API Documentation, Section 12).

Error messages must never echo cell values.
"""

from __future__ import annotations

from typing import Any, Optional

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

ERROR_CODES = {
    "ANALYTICS_INVALID_DATASET": 422,
    "ANALYTICS_INSUFFICIENT_DATA": 422,
    "ANALYTICS_UNSUPPORTED_TYPE": 422,
    "ANALYTICS_METHOD_FAILED": 500,
    "ANALYTICS_INTERNAL_ERROR": 500,
    "ANALYTICS_UNAUTHORIZED": 401,
    "ANALYTICS_PAYLOAD_TOO_LARGE": 413,
}


class AnalyticsError(Exception):
    def __init__(
        self,
        code: str,
        message: str,
        status_code: Optional[int] = None,
        details: Optional[list[dict[str, Any]]] = None,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.status_code = status_code or ERROR_CODES.get(code, 500)
        self.details = details or []


def error_body(code: str, message: str, details: Optional[list[dict[str, Any]]] = None) -> dict[str, Any]:
    body: dict[str, Any] = {"error": {"code": code, "message": message}}
    if details:
        body["error"]["details"] = details
    return body


def install_error_handlers(app: FastAPI) -> None:
    @app.exception_handler(AnalyticsError)
    async def _analytics_error(_: Request, exc: AnalyticsError) -> JSONResponse:
        return JSONResponse(status_code=exc.status_code, content=error_body(exc.code, exc.message, exc.details))

    @app.exception_handler(RequestValidationError)
    async def _validation_error(_: Request, exc: RequestValidationError) -> JSONResponse:
        # Only field locations and messages are returned; never the submitted values.
        details = [
            {"field": ".".join(str(p) for p in e.get("loc", [])), "issue": e.get("msg", "")} for e in exc.errors()
        ]
        return JSONResponse(
            status_code=422,
            content=error_body("ANALYTICS_INVALID_DATASET", "The request could not be validated.", details),
        )

    @app.exception_handler(Exception)
    async def _unhandled(_: Request, exc: Exception) -> JSONResponse:  # noqa: ARG001
        return JSONResponse(
            status_code=500,
            content=error_body("ANALYTICS_INTERNAL_ERROR", "Unexpected analytics service failure."),
        )
