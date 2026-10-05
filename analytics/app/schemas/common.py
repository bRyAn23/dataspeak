"""Shared contract objects (docs/contracts/analytics-contract.md)."""

from __future__ import annotations

from typing import Any, Dict, List, Optional

from pydantic import BaseModel, ConfigDict, Field, field_validator

SEMANTIC_TYPES = (
    "NUMBER",
    "CURRENCY",
    "PERCENTAGE",
    "DATE",
    "CATEGORY",
    "TEXT",
    "IDENTIFIER",
    "BOOLEAN",
    "IGNORE",
)
AGGREGATIONS = ("SUM", "AVERAGE", "COUNT", "MIN", "MAX", "NONE")
INFERRED_TYPES = (
    "numeric",
    "integer",
    "date",
    "datetime",
    "boolean",
    "category",
    "text",
    "identifier",
    "unknown",
)
ANALYSIS_FAMILIES = (
    "descriptive",
    "trend",
    "group_comparison",
    "association",
    "statistical_test",
    "quality",
)
QUALITY_STATUSES = ("acceptable", "warning", "insufficient")
INSIGHT_TYPES = (
    "trend_change",
    "group_difference",
    "concentration",
    "association",
    "distribution",
    "data_quality",
)
CHART_TYPES = ("line", "bar", "scatter", "histogram", "table")


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class FieldDefinition(StrictModel):
    column: str = Field(min_length=1, max_length=255)
    business_name: Optional[str] = Field(default=None, max_length=255)
    semantic_type: str = Field(pattern="^(" + "|".join(SEMANTIC_TYPES) + ")$")
    unit: Optional[str] = Field(default=None, max_length=32)
    aggregation: Optional[str] = Field(default=None, pattern="^(" + "|".join(AGGREGATIONS) + ")$")


class Quality(StrictModel):
    status: str = Field(pattern="^(" + "|".join(QUALITY_STATUSES) + ")$")
    notes: List[str] = Field(default_factory=list)


class Evidence(StrictModel):
    """Evidence model, FastAPI API Documentation Section 9."""

    evidence_id: str = Field(pattern=r"^ev_[a-f0-9]{12}$")
    analysis_family: str = Field(pattern="^(" + "|".join(ANALYSIS_FAMILIES) + ")$")
    metric: str = Field(max_length=64)
    value: Optional[float] = None
    display_value: Optional[str] = Field(default=None, max_length=64)
    method: str = Field(max_length=64)
    source_columns: List[str]
    metric_column: Optional[str] = None
    dimension_column: Optional[str] = None
    parameters: Dict[str, Any] = Field(default_factory=dict)
    quality: Quality
    payload: Optional[Dict[str, Any]] = None

    @field_validator("parameters", "payload", mode="before")
    @classmethod
    def _empty_array_is_empty_object(cls, v: Any) -> Any:
        # PHP round-trips an empty JSON object as [] (json_decode assoc). Treat it as {}.
        if isinstance(v, list) and len(v) == 0:
            return {}
        return v


class Finding(StrictModel):
    code: str
    message: str
    column: Optional[str] = None


class QualityFinding(StrictModel):
    code: str
    severity: str = Field(pattern="^(warning|insufficient)$")
    message: str
    column: Optional[str] = None


class ErrorDetail(StrictModel):
    field: str
    issue: str


class ErrorBody(StrictModel):
    code: str
    message: str
    details: Optional[List[ErrorDetail]] = None


class ErrorResponse(StrictModel):
    error: ErrorBody
