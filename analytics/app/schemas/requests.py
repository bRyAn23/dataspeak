"""Request metadata models. The CSV arrives as a multipart `file`; `metadata` is a JSON string."""

from __future__ import annotations

from typing import List, Optional

from pydantic import Field

from app.schemas.common import Evidence, FieldDefinition, StrictModel


class AnalysisOptions(StrictModel):
    seed: int = 42
    max_insights: int = Field(default=10, ge=1, le=25)


class DatasetMetadata(StrictModel):
    analysis_id: str = Field(min_length=1, max_length=64)
    definitions: List[FieldDefinition] = Field(default_factory=list)
    options: AnalysisOptions = Field(default_factory=AnalysisOptions)


class InsightIn(StrictModel):
    insight_type: str
    headline: str
    description: Optional[str] = None
    score: float
    rank: int
    primary_evidence_id: str
    evidence_ids: List[str]


class InsightsRequest(StrictModel):
    analysis_id: str = Field(min_length=1, max_length=64)
    evidence: List[Evidence]
    definitions: List[FieldDefinition] = Field(default_factory=list)
    options: AnalysisOptions = Field(default_factory=AnalysisOptions)


class VisualizationRequest(StrictModel):
    analysis_id: str = Field(min_length=1, max_length=64)
    evidence: List[Evidence]
    insights: List[InsightIn] = Field(default_factory=list)
    definitions: List[FieldDefinition] = Field(default_factory=list)
