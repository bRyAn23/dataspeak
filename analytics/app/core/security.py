"""Service-to-service authentication placeholder (Laravel -> FastAPI).

The shared token comes from ANALYTICS_INTERNAL_TOKEN in both services. /health is exempt.
"""

from __future__ import annotations

import hmac
from typing import Optional

from fastapi import Depends, Header

from app.core.config import Settings, get_settings
from app.core.errors import AnalyticsError

INTERNAL_TOKEN_HEADER = "X-Internal-Token"


def require_internal_token(
    x_internal_token: Optional[str] = Header(default=None, alias=INTERNAL_TOKEN_HEADER),
    settings: Settings = Depends(get_settings),
) -> None:
    expected = settings.analytics_internal_token
    if not expected:
        raise AnalyticsError(
            code="ANALYTICS_INTERNAL_ERROR",
            message="Internal service token is not configured.",
            status_code=500,
        )
    if not x_internal_token or not hmac.compare_digest(x_internal_token, expected):
        raise AnalyticsError(
            code="ANALYTICS_UNAUTHORIZED",
            message="Missing or invalid internal service token.",
            status_code=401,
        )
