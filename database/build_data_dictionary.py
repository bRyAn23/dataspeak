"""Builds docs/Data_Speak_Data_Dictionary.xlsx and database/data_dictionary.md.

The dictionary is the authoritative schema source for Data Speak. It was drafted on 2026-10-03 from
data_speak_erd_v2 (bigint keys, eleven entities), the Laravel and FastAPI API documents and the
Program Specification, because no dictionary existed. Edit THIS file, re-run it, then regenerate
migrations. Never edit the spreadsheet by hand.

Run:  python database/build_data_dictionary.py
"""

from __future__ import annotations

import datetime as dt
from pathlib import Path

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

ROOT = Path(__file__).resolve().parent.parent
XLSX = ROOT / "docs" / "Data_Speak_Data_Dictionary.xlsx"
MD = ROOT / "database" / "data_dictionary.md"
VERSION = "1.0-draft"
DATE = "2026-10-03"

# ---------------------------------------------------------------------------------------------
# Enumerations. Values are stored as VARCHAR with a CHECK constraint (Section 11 strategy).
# ---------------------------------------------------------------------------------------------
ENUMS: dict[str, tuple[str, list[tuple[str, str]]]] = {
    "user_role": ("users.role", [("USER", "Standard business user"), ("ADMIN", "Platform administrator")]),
    "analysis_status": (
        "analyses.status - Specification Section 11 state model",
        [
            ("CREATED", "Analysis shell exists, no dataset"),
            ("UPLOADED", "Transient CSV received"),
            ("VALIDATED", "Structure validated by analytics service"),
            ("PROFILED", "Derived column metadata stored"),
            ("DEFINED", "Business definitions saved"),
            ("ANALYZING", "Deterministic analysis job running"),
            ("ANALYZED", "Evidence, insights, visualizations stored"),
            ("REPORTING", "Report assembly running"),
            ("COMPLETED", "Executive report ready"),
            ("FAILED", "A processing stage failed; retry may be allowed"),
        ],
    ),
    "analysis_stage": (
        "analyses.current_stage, analysis_stages.stage - workflow steps (Specification Section 6)",
        [
            ("UPLOAD", "CSV upload"),
            ("VALIDATION", "CSV validation"),
            ("PROFILING", "Dataset profiling"),
            ("DEFINITION", "Business field definition"),
            ("STATISTICAL_ANALYSIS", "Deterministic statistical analysis"),
            ("INSIGHT_DETECTION", "Insight detection and ranking"),
            ("VISUALIZATION", "Visualization recommendation"),
            ("AI_SYNTHESIS", "AI insight synthesis and claim generation (planned)"),
            ("CLAIM_VALIDATION", "Claim validation (planned)"),
            ("REPORTING", "Narrative rendering and report assembly"),
            ("COMPLETE", "Terminal stage"),
        ],
    ),
    "stage_status": (
        "analysis_stages.status",
        [("PENDING", ""), ("RUNNING", ""), ("SUCCEEDED", ""), ("FAILED", ""), ("SKIPPED", "")],
    ),
    "job_status": (
        "RUN-API-001/002 response field only (Redis job metadata, not a table column)",
        [("QUEUED", ""), ("RUNNING", ""), ("SUCCEEDED", ""), ("FAILED", "")],
    ),
    "column_inferred_type": (
        "dataset_columns.inferred_type - produced by PY-API-002 profiling",
        [
            ("NUMERIC", "Decimal numbers"),
            ("INTEGER", "Whole numbers"),
            ("DATE", "Calendar dates"),
            ("DATETIME", "Timestamps"),
            ("BOOLEAN", "Two-valued"),
            ("CATEGORY", "Low-cardinality text"),
            ("TEXT", "Free text"),
            ("IDENTIFIER", "High-cardinality keys"),
            ("UNKNOWN", "Could not infer"),
        ],
    ),
    "semantic_type": (
        "field_definitions.semantic_type - DEF-API-001, FE-DEF-002",
        [
            ("NUMBER", "Plain measure"),
            ("CURRENCY", "Monetary measure; unit holds ISO code e.g. THB"),
            ("PERCENTAGE", "Ratio expressed in percent"),
            ("DATE", "Time axis"),
            ("CATEGORY", "Grouping dimension"),
            ("TEXT", "Descriptive, excluded from statistics"),
            ("IDENTIFIER", "Key, excluded from statistics"),
            ("BOOLEAN", "Flag"),
            ("IGNORE", "User excluded the column"),
        ],
    ),
    "aggregation": (
        "field_definitions.aggregation",
        [("SUM", ""), ("AVERAGE", ""), ("COUNT", ""), ("MIN", ""), ("MAX", ""), ("NONE", "")],
    ),
    "analysis_family": (
        "evidence.analysis_family - FastAPI doc Section 8",
        [
            ("DESCRIPTIVE", ""),
            ("TREND", ""),
            ("GROUP_COMPARISON", ""),
            ("ASSOCIATION", ""),
            ("STATISTICAL_TEST", ""),
            ("QUALITY", ""),
        ],
    ),
    "quality_status": (
        "evidence.quality_status - Feature List rule 5 (warnings vs insufficient vs findings)",
        [("ACCEPTABLE", "Finding may be used"), ("WARNING", "Usable with caveat"), ("INSUFFICIENT", "Not enough data")],
    ),
    "insight_type": (
        "insights.insight_type",
        [
            ("TREND_CHANGE", ""),
            ("GROUP_DIFFERENCE", ""),
            ("CONCENTRATION", ""),
            ("ASSOCIATION", ""),
            ("DISTRIBUTION", ""),
            ("DATA_QUALITY", ""),
        ],
    ),
    "chart_type": (
        "visualizations.chart_type - FE-RPT-004 renders exactly these",
        [("LINE", ""), ("BAR", ""), ("SCATTER", ""), ("HISTOGRAM", ""), ("TABLE", ""), ("PIE", "Share of a total by category; user-added at chart review (FEAT-VIS-005)")],
    ),
    "visualization_source": (
        "visualizations.source - who put the chart in the report",
        [("RECOMMENDED", "Recommended by the analytics service"), ("USER", "Added by the user at chart review")],
    ),
    "ai_generation_type": (
        "ai_generations.generation_type",
        [("INSIGHT_SYNTHESIS", ""), ("CLAIM_GENERATION", ""), ("NARRATIVE", "")],
    ),
    "ai_generation_status": (
        "ai_generations.status",
        [("PENDING", ""), ("RUNNING", ""), ("SUCCEEDED", ""), ("FAILED", ""), ("SKIPPED", "")],
    ),
    "claim_kind": (
        "claims.claim_kind",
        [("EXECUTIVE_SUMMARY", ""), ("FINDING", ""), ("METRIC", ""), ("CAVEAT", "")],
    ),
    "claim_status": (
        "claims.status - validation state (FEAT-VAL-001)",
        [
            ("PENDING", "Not yet validated"),
            ("SUPPORTED", "Matches evidence within tolerance"),
            ("UNSUPPORTED", "No evidence relationship found"),
            ("REJECTED", "Contradicts evidence"),
            ("NEEDS_REVIEW", "Ambiguous; human review"),
        ],
    ),
    "report_status": (
        "reports.status",
        [("DRAFT", ""), ("GENERATING", ""), ("READY", ""), ("FAILED", "")],
    ),
}

# ---------------------------------------------------------------------------------------------
# Tables. Column tuple: (name, pg_type, nullable, default, description)
# FK tuple: (column, ref_table, ref_column, on_delete, on_update)
# ---------------------------------------------------------------------------------------------
TS = "timestamptz"
TABLES: list[dict] = [
    {
        "name": "users",
        "owner": "Laravel (framework table extended)",
        "purpose": "Account identity. Framework migration 0001_01_01_000000 creates it; Data Speak adds role.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", "Primary key"),
            ("name", "varchar(255)", False, "", ""),
            ("email", "varchar(255)", False, "", "Unique login"),
            ("email_verified_at", "timestamp", True, "", "Framework"),
            ("password", "varchar(255)", False, "", "Hashed"),
            ("role", "varchar(32)", False, "'USER'", "enum user_role (ADDED by Data Speak migration)"),
            ("remember_token", "varchar(100)", True, "", "Framework"),
            ("created_at", "timestamp", True, "", ""),
            ("updated_at", "timestamp", True, "", ""),
        ],
        "fks": [],
        "unique": [["email"]],
        "indexes": [],
        "enums": {"role": "user_role"},
    },
    {
        "name": "analyses",
        "owner": "Laravel",
        "purpose": "Central analysis aggregate (MOD-02). Holds lifecycle state and dataset metadata, never rows.",
        "pk": "id bigint (identity); public_id uuid is the externally visible identifier (Laravel doc Section 3)",
        "columns": [
            ("id", "bigint", False, "identity", "Internal primary key"),
            ("public_id", "uuid", False, "", "External identifier used in /api/v1/analyses/{id}"),
            ("user_id", "bigint", False, "", "Owner"),
            ("name", "varchar(255)", False, "", "User-given analysis name"),
            ("status", "varchar(32)", False, "'CREATED'", "enum analysis_status"),
            ("current_stage", "varchar(32)", False, "'UPLOAD'", "enum analysis_stage"),
            ("source_file_name", "varchar(255)", True, "", "Original upload filename (metadata only)"),
            ("source_file_size_bytes", "bigint", True, "", ""),
            ("file_sha256", "char(64)", True, "", "Fingerprint of the transient upload"),
            ("row_count", "integer", True, "", "From validation/profiling"),
            ("column_count", "integer", True, "", ""),
            ("completeness_pct", "numeric(5,2)", True, "", "0.00-100.00 share of non-null cells"),
            ("report_language", "varchar(8)", False, "'en'", "Language the report is produced in (en, th, zh, my); chosen at run time (FEAT-I18N-003)"),
            ("chart_theme", "varchar(32)", False, "'emerald'", "Colour theme for every chart in the report and PDF (emerald, ocean, sunset, plum, slate, vivid); chosen at chart review (FEAT-VIS-004)"),
            ("label_translations", "jsonb", True, "", "Per-language display labels for non-Latin headers/category values (FEAT-I18N-002): {<locale>: {map, ai_status, model, prompt_version, count, generated_at}}. Presentation only; evidence keeps originals"),
            ("csv_uploaded_at", TS, True, "", "When the transient file was received"),
            ("csv_purged_at", TS, True, "", "When the transient file was deleted (FEAT-UPL-003)"),
            ("last_error_code", "varchar(64)", True, "", "Safe error code of the last failure"),
            ("last_error_message", "varchar(500)", True, "", "Safe, value-free message"),
            ("created_at", TS, True, "", ""),
            ("updated_at", TS, True, "", ""),
        ],
        "fks": [("user_id", "users", "id", "RESTRICT", "CASCADE")],
        "unique": [["public_id"]],
        "indexes": [["user_id", "created_at"], ["status"]],
        "enums": {"status": "analysis_status", "current_stage": "analysis_stage"},
    },
    {
        "name": "analysis_stages",
        "owner": "Laravel",
        "purpose": "Lifecycle/stage history (FEAT-OPS-001/002). One row per stage attempt.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("analysis_id", "bigint", False, "", ""),
            ("stage", "varchar(32)", False, "", "enum analysis_stage"),
            ("status", "varchar(32)", False, "'PENDING'", "enum stage_status"),
            ("attempt_no", "smallint", False, "1", "Increments on retry"),
            ("progress", "smallint", False, "0", "0-100 for RUN-API-002 polling"),
            ("started_at", TS, True, "", ""),
            ("finished_at", TS, True, "", ""),
            ("duration_ms", "integer", True, "", ""),
            ("error_code", "varchar(64)", True, "", "Safe error code"),
            ("result_summary", "jsonb", True, "", "Counts and method names only; never rows"),
            ("created_at", TS, True, "", ""),
            ("updated_at", TS, True, "", ""),
        ],
        "fks": [("analysis_id", "analyses", "id", "RESTRICT", "CASCADE")],
        "unique": [],
        "indexes": [["analysis_id", "created_at"]],
        "enums": {"stage": "analysis_stage", "status": "stage_status"},
    },
    {
        "name": "dataset_columns",
        "owner": "Laravel (data from PY-API-002)",
        "purpose": "Derived column metadata (MOD-04). Header names and profile statistics; not raw rows.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("analysis_id", "bigint", False, "", ""),
            ("position", "smallint", False, "", "0-based column order in the CSV"),
            ("source_name", "varchar(255)", False, "", "CSV header; Unicode incl. Thai/Myanmar"),
            ("inferred_type", "varchar(32)", False, "'UNKNOWN'", "enum column_inferred_type"),
            ("null_count", "integer", False, "0", ""),
            ("null_rate", "numeric(7,6)", False, "0", "0-1"),
            ("distinct_count", "integer", True, "", ""),
            ("profile_stats", "jsonb", True, "", "Derived summaries (min/max/mean/percentiles/top-share counts). No values of TEXT/IDENTIFIER columns."),
            ("created_at", TS, True, "", ""),
            ("updated_at", TS, True, "", ""),
        ],
        "fks": [("analysis_id", "analyses", "id", "RESTRICT", "CASCADE")],
        "unique": [["analysis_id", "source_name"], ["analysis_id", "position"]],
        "indexes": [],
        "enums": {"inferred_type": "column_inferred_type"},
    },
    {
        "name": "field_definitions",
        "owner": "Laravel",
        "purpose": "Business meaning, semantic type and unit per column (MOD-05). One per dataset column.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("dataset_column_id", "bigint", False, "", "1:1 with dataset_columns"),
            ("business_name", "varchar(255)", False, "", ""),
            ("description", "varchar(500)", True, "", ""),
            ("semantic_type", "varchar(32)", False, "", "enum semantic_type"),
            ("unit", "varchar(32)", True, "", "Free text / ISO currency code. Never defaulted."),
            ("aggregation", "varchar(16)", True, "", "enum aggregation"),
            ("is_suggested", "boolean", False, "false", "True when system-suggested and not yet edited"),
            ("created_at", TS, True, "", ""),
            ("updated_at", TS, True, "", ""),
        ],
        "fks": [("dataset_column_id", "dataset_columns", "id", "RESTRICT", "CASCADE")],
        "unique": [["dataset_column_id"]],
        "indexes": [],
        "enums": {"semantic_type": "semantic_type", "aggregation": "aggregation"},
    },
    {
        "name": "evidence",
        "owner": "Laravel (data from PY-API-004)",
        "purpose": "Deterministic metrics with method metadata (MOD-06). Source of every number in a report.",
        "pk": "id bigint (identity); evidence_key is the stable key produced by FastAPI (ev_...)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("analysis_id", "bigint", False, "", ""),
            ("evidence_key", "varchar(64)", False, "", "Deterministic key from FastAPI, e.g. ev_3f9a..."),
            ("analysis_family", "varchar(32)", False, "", "enum analysis_family"),
            ("metric", "varchar(64)", False, "", "e.g. monthly_growth_rate"),
            ("method", "varchar(64)", False, "", "e.g. period_over_period_growth"),
            ("value", "numeric(24,8)", True, "", "Full-precision scalar; null when the result is non-scalar"),
            ("display_value", "varchar(64)", True, "", "Formatted, e.g. +3.1%"),
            ("metric_column_id", "bigint", True, "", "Measure column"),
            ("dimension_column_id", "bigint", True, "", "Grouping/time column"),
            ("source_columns", "jsonb", False, "'[]'", "Array of source column names"),
            ("parameters", "jsonb", False, "'{}'", "Method parameters for reproducibility"),
            ("quality_status", "varchar(16)", False, "'ACCEPTABLE'", "enum quality_status"),
            ("payload", "jsonb", True, "", "Structured detail: group aggregates, period series, test stats. Aggregates only."),
            ("created_at", TS, True, "", ""),
        ],
        "fks": [
            ("analysis_id", "analyses", "id", "RESTRICT", "CASCADE"),
            ("metric_column_id", "dataset_columns", "id", "RESTRICT", "CASCADE"),
            ("dimension_column_id", "dataset_columns", "id", "RESTRICT", "CASCADE"),
        ],
        "unique": [["analysis_id", "evidence_key"]],
        "indexes": [["analysis_id", "analysis_family"]],
        "enums": {"analysis_family": "analysis_family", "quality_status": "quality_status"},
    },
    {
        "name": "insights",
        "owner": "Laravel (data from PY-API-005)",
        "purpose": "Ranked findings linked to evidence (MOD-07).",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("analysis_id", "bigint", False, "", ""),
            ("evidence_id", "bigint", False, "", "Primary supporting evidence"),
            ("insight_type", "varchar(32)", False, "", "enum insight_type"),
            ("headline", "varchar(255)", False, "", "Neutral, non-causal wording"),
            ("description", "varchar(1000)", True, "", ""),
            ("score", "numeric(6,4)", False, "", "Ranking score 0-1"),
            ("rank", "smallint", False, "", "1 = strongest"),
            ("is_selected", "boolean", False, "true", "Included in report"),
            ("supporting_evidence_keys", "jsonb", False, "'[]'", "All evidence keys referenced"),
            ("created_at", TS, True, "", ""),
        ],
        "fks": [
            ("analysis_id", "analyses", "id", "RESTRICT", "CASCADE"),
            ("evidence_id", "evidence", "id", "RESTRICT", "CASCADE"),
        ],
        "unique": [["analysis_id", "rank"]],
        "indexes": [],
        "enums": {"insight_type": "insight_type"},
    },
    {
        "name": "visualizations",
        "owner": "Laravel (data from PY-API-006)",
        "purpose": "Chart specifications linked to evidence/insights (MOD-08). Spec only; the frontend renders.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("analysis_id", "bigint", False, "", ""),
            ("insight_id", "bigint", True, "", "Insight the chart illustrates, if any"),
            ("chart_type", "varchar(16)", False, "", "enum chart_type"),
            ("source", "varchar(16)", False, "'RECOMMENDED'", "enum visualization_source: recommended by PY-API-006 or added by the user (FEAT-VIS-003)"),
            ("title", "varchar(255)", False, "", ""),
            ("spec", "jsonb", False, "", "x/y fields, labels, unit, series (aggregated points)"),
            ("evidence_keys", "jsonb", False, "'[]'", ""),
            ("position", "smallint", False, "0", "Display order"),
            ("is_selected", "boolean", False, "true", "Kept for the report after chart review (FEAT-VIS-003)"),
            ("created_at", TS, True, "", ""),
        ],
        "fks": [
            ("analysis_id", "analyses", "id", "RESTRICT", "CASCADE"),
            ("insight_id", "insights", "id", "SET NULL", "CASCADE"),
        ],
        "unique": [],
        "indexes": [["analysis_id", "position"]],
        "enums": {"chart_type": "chart_type"},
    },
    {
        "name": "ai_generations",
        "owner": "Laravel (MOD-09, planned)",
        "purpose": "Metadata and outputs of AI interpretation requests. Requests contain evidence, never rows.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("analysis_id", "bigint", False, "", ""),
            ("generation_type", "varchar(32)", False, "", "enum ai_generation_type"),
            ("status", "varchar(16)", False, "'PENDING'", "enum ai_generation_status"),
            ("attempt_no", "smallint", False, "1", ""),
            ("model", "varchar(128)", True, "", "e.g. Qwen2.5-7B-Instruct"),
            ("prompt_version", "varchar(32)", True, "", ""),
            ("request_payload", "jsonb", True, "", "Evidence keys and derived values sent to the gateway"),
            ("response_payload", "jsonb", True, "", "Structured output"),
            ("input_tokens", "integer", True, "", ""),
            ("output_tokens", "integer", True, "", ""),
            ("duration_ms", "integer", True, "", ""),
            ("error_code", "varchar(64)", True, "", ""),
            ("created_at", TS, True, "", ""),
            ("updated_at", TS, True, "", ""),
        ],
        "fks": [("analysis_id", "analyses", "id", "RESTRICT", "CASCADE")],
        "unique": [],
        "indexes": [["analysis_id", "created_at"]],
        "enums": {"generation_type": "ai_generation_type", "status": "ai_generation_status"},
    },
    {
        "name": "claims",
        "owner": "Laravel (MOD-10/11)",
        "purpose": "Structured claims with validation state (FEAT-AI-002, FEAT-VAL-001). Evidence links via claim_evidence.",
        "pk": "id bigint (identity); public_id uuid is used in TRC-API-001 URLs",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("public_id", "uuid", False, "", "External identifier {claimId}"),
            ("analysis_id", "bigint", False, "", ""),
            ("ai_generation_id", "bigint", True, "", "Null for deterministic (non-AI) claims"),
            ("claim_kind", "varchar(32)", False, "", "enum claim_kind"),
            ("statement", "text", False, "", "Narrative sentence"),
            ("statement_translations", "jsonb", True, "", "Verified translations of the statement template per language {locale: template}; placeholders identical to statement (FEAT-I18N-003)"),
            ("claimed_value", "varchar(128)", True, "", "Value as stated in the sentence"),
            ("computed_value", "varchar(128)", True, "", "Value from evidence used to validate"),
            ("status", "varchar(16)", False, "'PENDING'", "enum claim_status"),
            ("validation_method", "varchar(64)", True, "", "e.g. numeric_tolerance, nli"),
            ("validation_notes", "varchar(500)", True, "", ""),
            ("in_report", "boolean", False, "false", "Only SUPPORTED claims may be true"),
            ("position", "smallint", True, "", "Order within report section"),
            ("created_at", TS, True, "", ""),
            ("updated_at", TS, True, "", ""),
        ],
        "fks": [
            ("analysis_id", "analyses", "id", "RESTRICT", "CASCADE"),
            ("ai_generation_id", "ai_generations", "id", "SET NULL", "CASCADE"),
        ],
        "unique": [["public_id"]],
        "indexes": [["analysis_id", "status"]],
        "enums": {"claim_kind": "claim_kind", "status": "claim_status"},
    },
    {
        "name": "claim_evidence",
        "owner": "Laravel (MOD-12)",
        "purpose": "Bridge: a claim is supported by one or more evidence records (Laravel doc Section 10). Deviation from ERD v2 single FK.",
        "pk": "composite (claim_id, evidence_id)",
        "columns": [
            ("claim_id", "bigint", False, "", ""),
            ("evidence_id", "bigint", False, "", ""),
            ("is_primary", "boolean", False, "false", "Exactly one primary per claim (application rule)"),
            ("created_at", TS, True, "", ""),
        ],
        "fks": [
            ("claim_id", "claims", "id", "RESTRICT", "CASCADE"),
            ("evidence_id", "evidence", "id", "RESTRICT", "CASCADE"),
        ],
        "unique": [],
        "indexes": [["evidence_id"]],
        "enums": {},
    },
    {
        "name": "reports",
        "owner": "Laravel (MOD-11)",
        "purpose": "Final executive report metadata/content. One per analysis.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("analysis_id", "bigint", False, "", "1:1 with analyses"),
            ("status", "varchar(16)", False, "'DRAFT'", "enum report_status"),
            ("language", "varchar(8)", False, "'en'", "Language of the narrative, labels and PDF (en, th, zh, my) (FEAT-I18N-003)"),
            ("title", "varchar(255)", False, "", ""),
            ("generated_at", TS, True, "", ""),
            ("claims_total", "integer", False, "0", ""),
            ("claims_supported", "integer", False, "0", ""),
            ("summary_metrics", "jsonb", True, "", "KPI cards: evidence_key, label, display_value, unit"),
            ("sections", "jsonb", True, "", "Narrative sections referencing claim public_ids"),
            ("data_notes", "jsonb", True, "", "Warnings / insufficient-data notes"),
            ("created_at", TS, True, "", ""),
            ("updated_at", TS, True, "", ""),
        ],
        "fks": [("analysis_id", "analyses", "id", "RESTRICT", "CASCADE")],
        "unique": [["analysis_id"]],
        "indexes": [],
        "enums": {"status": "report_status"},
    },
    {
        "name": "personal_access_tokens",
        "owner": "Laravel Sanctum (framework table)",
        "purpose": "API bearer tokens (AUTH-API-002). Created by the Sanctum package migration; listed for completeness.",
        "pk": "id bigint (identity)",
        "columns": [
            ("id", "bigint", False, "identity", ""),
            ("tokenable_type", "varchar(255)", False, "", "Polymorphic"),
            ("tokenable_id", "bigint", False, "", ""),
            ("name", "text", False, "", ""),
            ("token", "varchar(64)", False, "", "Hashed"),
            ("abilities", "text", True, "", ""),
            ("last_used_at", "timestamp", True, "", ""),
            ("expires_at", "timestamp", True, "", ""),
            ("created_at", "timestamp", True, "", ""),
            ("updated_at", "timestamp", True, "", ""),
        ],
        "fks": [],
        "unique": [["token"]],
        "indexes": [["tokenable_type", "tokenable_id"], ["expires_at"]],
        "enums": {},
    },
]

FRAMEWORK_TABLES = [
    ("password_reset_tokens", "0001_01_01_000000_create_users_table"),
    ("sessions", "0001_01_01_000000_create_users_table"),
    ("cache", "0001_01_01_000001_create_cache_table"),
    ("cache_locks", "0001_01_01_000001_create_cache_table"),
    ("jobs", "0001_01_01_000002_create_jobs_table"),
    ("job_batches", "0001_01_01_000002_create_jobs_table"),
    ("failed_jobs", "0001_01_01_000002_create_jobs_table"),
]

DECISIONS = [
    ("Primary keys", "bigint identity everywhere (ERD v2). Externally visible resources (analyses, claims) carry an additional public_id uuid, satisfying the Laravel API 'UUID preferred' convention without changing FK types."),
    ("Evidence identifiers", "FastAPI emits a deterministic evidence_key (ev_ + hash). Laravel stores it in evidence.evidence_key, unique per analysis. API payloads use evidence_key; the bigint id never leaves Laravel."),
    ("Enum strategy", "VARCHAR columns with named CHECK constraints (chk_<table>_<column>). Adding a value is one migration that drops and recreates the constraint; rollback is symmetric. No PostgreSQL native ENUM types."),
    ("Foreign keys", "ON DELETE RESTRICT on every relationship that carries evidence or traceability. ON DELETE SET NULL only for optional illustrative links (visualizations.insight_id, claims.ai_generation_id). ANL-API-004 deletes explicitly in a transaction in dependency order."),
    ("Timestamps", "Data Speak tables use timestamptz created_at/updated_at (Laravel timestampsTz). Framework tables keep their shipped definitions. Tables that are append-only history (analysis_stages has updated_at because progress is updated in place; evidence/insights/visualizations/claim_evidence have created_at only)."),
    ("Soft deletes", "None. Deleting an analysis removes derived records; raw data never existed in the database."),
    ("Raw data", "No table, column or JSONB document may contain CSV rows, cell values of TEXT/IDENTIFIER columns, or row samples. JSONB columns hold aggregates, method metadata and specifications only. Category labels of CATEGORY-typed columns may appear in evidence payloads (group names)."),
    ("Indexes", "DATA-DICTIONARY INDEX = listed on each table sheet. Migrations must not add others without recording them here as IMPLEMENTATION-RECOMMENDED."),
    ("Deviation from ERD v2", "claim_evidence bridge replaces claims.evidence_id to support one-to-many claim traceability (Laravel doc Section 10). users.role added. analyses gains public_id, source_file_name, source_file_size_bytes, row_count, column_count, csv_uploaded_at, last_error_*. insights gains analysis_id, insight_type, headline, description, supporting_evidence_keys. Status of this document: DRAFT pending review."),
]

MIGRATION_ORDER = [
    ("users (alter: add role)", "after framework users"),
    ("personal_access_tokens", "Sanctum package migration, no dependencies"),
    ("analyses", "users"),
    ("analysis_stages", "analyses"),
    ("dataset_columns", "analyses"),
    ("field_definitions", "dataset_columns"),
    ("evidence", "analyses, dataset_columns"),
    ("insights", "analyses, evidence"),
    ("visualizations", "analyses, insights"),
    ("ai_generations", "analyses"),
    ("claims", "analyses, ai_generations"),
    ("claim_evidence", "claims, evidence"),
    ("reports", "analyses"),
]

HEADER_FILL = PatternFill("solid", fgColor="1F4E3D")
HEADER_FONT = Font(bold=True, color="FFFFFF")


def _sheet(wb: Workbook, title: str, header: list[str], rows: list[list], widths: list[int] | None = None):
    ws = wb.create_sheet(title[:31])
    ws.append(header)
    for c in range(1, len(header) + 1):
        cell = ws.cell(row=1, column=c)
        cell.fill, cell.font = HEADER_FILL, HEADER_FONT
        cell.alignment = Alignment(vertical="top", wrap_text=True)
    for r in rows:
        ws.append(r)
    for i, w in enumerate(widths or [18] * len(header), start=1):
        ws.column_dimensions[get_column_letter(i)].width = w
    ws.freeze_panes = "A2"
    return ws


def build_xlsx() -> None:
    wb = Workbook()
    wb.remove(wb.active)
    _sheet(wb, "README", ["Item", "Value"], [
        ["Document", "Data Speak Data Dictionary"],
        ["Version", VERSION],
        ["Date", DATE],
        ["Status", "DRAFT generated from database/build_data_dictionary.py - review required"],
        ["Authority", "This workbook governs tables, columns, types, keys, constraints and enumerations."],
        ["Edit rule", "Edit database/build_data_dictionary.py and re-run; do not edit cells by hand."],
        ["Sheets", "Tables, Columns, Relationships, Constraints, Enumerations, Decisions, MigrationOrder, FrameworkTables"],
    ], [22, 110])
    _sheet(wb, "Tables", ["Table", "Owner", "Purpose", "Primary key"],
           [[t["name"], t["owner"], t["purpose"], t["pk"]] for t in TABLES], [24, 34, 90, 60])
    col_rows = []
    for t in TABLES:
        for (name, typ, nullable, default, desc) in t["columns"]:
            col_rows.append([t["name"], name, typ, "YES" if nullable else "NO", default, t["enums"].get(name, ""), desc])
    _sheet(wb, "Columns", ["Table", "Column", "PostgreSQL type", "Nullable", "Default", "Enumeration", "Description"],
           col_rows, [22, 26, 18, 10, 14, 22, 80])
    rel_rows = [[t["name"], c, rt, rc, od, ou] for t in TABLES for (c, rt, rc, od, ou) in t["fks"]]
    _sheet(wb, "Relationships", ["Table", "Column", "References table", "References column", "ON DELETE", "ON UPDATE"],
           rel_rows, [22, 24, 22, 18, 12, 12])
    con_rows = []
    for t in TABLES:
        for u in t["unique"]:
            con_rows.append([t["name"], "UNIQUE", ", ".join(u), "DATA-DICTIONARY"])
        for ix in t["indexes"]:
            con_rows.append([t["name"], "INDEX", ", ".join(ix), "DATA-DICTIONARY"])
        for col, en in t["enums"].items():
            con_rows.append([t["name"], "CHECK", f"{col} IN (enum {en})", "DATA-DICTIONARY"])
    con_rows.append(["analysis_stages", "CHECK", "progress BETWEEN 0 AND 100", "DATA-DICTIONARY"])
    con_rows.append(["analyses", "CHECK", "completeness_pct BETWEEN 0 AND 100", "DATA-DICTIONARY"])
    _sheet(wb, "Constraints", ["Table", "Kind", "Definition", "Source"], con_rows, [22, 10, 60, 20])
    enum_rows = [[k, used, v, d] for k, (used, vals) in ENUMS.items() for (v, d) in vals]
    _sheet(wb, "Enumerations", ["Enumeration", "Used by", "Value", "Meaning"], enum_rows, [24, 60, 24, 50])
    _sheet(wb, "Decisions", ["Topic", "Decision"], [list(d) for d in DECISIONS], [22, 140])
    _sheet(wb, "MigrationOrder", ["#", "Table", "Depends on"],
           [[i + 1, t, d] for i, (t, d) in enumerate(MIGRATION_ORDER)], [5, 30, 40])
    _sheet(wb, "FrameworkTables", ["Table", "Created by migration"], [list(f) for f in FRAMEWORK_TABLES], [26, 46])
    XLSX.parent.mkdir(parents=True, exist_ok=True)
    wb.save(XLSX)


def build_md() -> None:
    out = [f"# Data Speak Data Dictionary ({VERSION}, {DATE})", "",
           "Generated by `database/build_data_dictionary.py`. The spreadsheet `docs/Data_Speak_Data_Dictionary.xlsx` is the authority; this file is a readable copy.", ""]
    out += ["## Decisions", ""] + [f"- **{k}.** {v}" for k, v in DECISIONS] + [""]
    out += ["## Enumerations", ""]
    for k, (used, vals) in ENUMS.items():
        out += [f"### {k}", "", f"Used by: {used}", "", "| Value | Meaning |", "| --- | --- |"]
        out += [f"| `{v}` | {d} |" for v, d in vals] + [""]
    out += ["## Tables", ""]
    for t in TABLES:
        out += [f"### {t['name']}", "", f"{t['purpose']}  ", f"Owner: {t['owner']}. Primary key: {t['pk']}.", "",
                "| Column | Type | Null | Default | Enum | Description |", "| --- | --- | --- | --- | --- | --- |"]
        out += [f"| {n} | {ty} | {'yes' if nu else 'no'} | {de} | {t['enums'].get(n, '')} | {ds} |" for (n, ty, nu, de, ds) in t["columns"]]
        if t["fks"]:
            out += ["", "Foreign keys: " + "; ".join(f"{c} -> {rt}.{rc} (ON DELETE {od})" for c, rt, rc, od, _ in t["fks"])]
        if t["unique"]:
            out += ["", "Unique: " + "; ".join("(" + ", ".join(u) + ")" for u in t["unique"])]
        if t["indexes"]:
            out += ["", "Indexes: " + "; ".join("(" + ", ".join(i) + ")" for i in t["indexes"])]
        out.append("")
    out += ["## Migration order", ""] + [f"{i + 1}. {t} (depends on: {d})" for i, (t, d) in enumerate(MIGRATION_ORDER)] + [""]
    out += ["## Framework tables (unchanged)", ""] + [f"- `{t}` from `{m}`" for t, m in FRAMEWORK_TABLES] + [""]
    MD.write_text("\n".join(out), encoding="utf-8")


if __name__ == "__main__":
    build_xlsx()
    build_md()
    print(f"wrote {XLSX.relative_to(ROOT)} and {MD.relative_to(ROOT)} at {dt.datetime.now():%H:%M:%S}")
