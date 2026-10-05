from pydantic import BaseModel, ConfigDict


class HealthResponse(BaseModel):
    """PY-API-001 contract: {"status":"ok","service":"dataspeak-analytics","version":"1.0"}"""

    model_config = ConfigDict(extra="forbid")

    status: str
    service: str
    version: str
