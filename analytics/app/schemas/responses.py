"""Response models, one per endpoint (docs/contracts/analytics-contract.md)."""

from __future__ import annotations

from typing import Any, Dict, List, Optional

from pydantic import Field

from app.schemas.common import (
    CHART_TYPES,
    INFERRED_TYPES,
    INSIGHT_TYPES,
    Evidence,
    Finding,
    QualityFinding,
    StrictModel,
)


class ValidateResponse(StrictModel):
    valid: bool
    row_count: int
    column_count: int
    columns: List[str]
    warnings: List[Finding] = Field(default_factory=list)
    errors: List[Finding] = Field(default_factory=list)


class DatasetSummary(StrictModel):
    rows: int
    columns: int
    completeness: float
    duplicate_rows: int


class ColumnProfile(StrictModel):
    name: str
    position: int
    inferred_type: str = Field(pattern="^(" + "|".join(INFERRED_TYPES) + ")$")
    null_count: int
    null_rate: float
    distinct_count: int
    stats: Dict[str, Any] = Field(default_factory=dict)


class ProfileResponse(StrictModel):
    dataset: DatasetSummary
    columns: List[ColumnProfile]
    quality_findings: List[QualityFinding] = Field(default_factory=list)


class SkippedFamily(StrictModel):
    family: str
    reason: str


class AnalyzeSummary(StrictModel):
    evidence_count: int
    families_run: List[str]
    skipped: List[SkippedFamily] = Field(default_factory=list)


class AnalyzeResponse(StrictModel):
    analysis_id: str
    evidence: List[Evidence]
    summary: AnalyzeSummary


class Insight(StrictModel):
    insight_type: str = Field(pattern="^(" + "|".join(INSIGHT_TYPES) + ")$")
    headline: str = Field(max_length=255)
    description: Optional[str] = Field(default=None, max_length=1000)
    score: float = Field(ge=0, le=1)
    rank: int = Field(ge=1)
    primary_evidence_id: str
    evidence_ids: List[str] = Field(min_length=1)


class InsightsResponse(StrictModel):
    insights: List[Insight]


class Axis(StrictModel):
    field: str
    label: str
    unit: Optional[str] = None


class ChartSpec(StrictModel):
    type: str = Field(pattern="^(" + "|".join(CHART_TYPES) + ")$")
    title: str = Field(max_length=255)
    x: Axis
    y: Axis
    unit_missing: bool
    series: List[Dict[str, Any]]
    evidence_ids: List[str] = Field(min_length=1)
    insight_rank: Optional[int] = None


class VisualizationResponse(StrictModel):
    visualizations: List[ChartSpec]
