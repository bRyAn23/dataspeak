# Laravel -> FastAPI Analytics Contract (v1)

Source: FastAPI API Documentation v1.0, Sections 3-12, and Laravel API Documentation Section 13.
Both services implement exactly this. Laravel is the only caller. Authentication: header
`X-Internal-Token: <ANALYTICS_INTERNAL_TOKEN>` on every `/v1/*` call; `/health` is open.

Case convention: FastAPI emits lowercase enum-like strings as shown in its document
(`"type":"line"`, `"status":"acceptable"`). Laravel upper-cases them into the data dictionary
enumerations (`LINE`, `ACCEPTABLE`) when persisting.

Empty objects: PHP round-trips `{}` as `[]`. FastAPI accepts `[]` for `parameters` and `payload` and treats it as `{}`; Laravel also restores objects before re-sending evidence.

## Shared objects

```jsonc
// FieldDefinition (Laravel -> FastAPI)
{ "column": "revenue_thb", "business_name": "Revenue", "semantic_type": "CURRENCY",
  "unit": "THB", "aggregation": "SUM" }

// Evidence (FastAPI -> Laravel, Section 9)
{ "evidence_id": "ev_3f9a1c2b7d",            // deterministic: sha256(metric|method|columns|params)[:12]
  "analysis_family": "trend",                 // descriptive|trend|group_comparison|association|statistical_test|quality
  "metric": "monthly_growth_rate",
  "value": 0.031,                             // full precision or null when non-scalar
  "display_value": "+3.1%",
  "method": "period_over_period_growth",
  "source_columns": ["order_date", "revenue_thb"],
  "metric_column": "revenue_thb",             // optional
  "dimension_column": "order_date",           // optional
  "parameters": { "period": "month" },
  "quality": { "status": "acceptable", "notes": [] },   // acceptable|warning|insufficient
  "payload": null }                           // aggregates only (groups, period series, test stats)

// Error (Section 12)
{ "error": { "code": "ANALYTICS_INVALID_DATASET", "message": "...", "details": [ { "field": "...", "issue": "..." } ] } }
```

Error codes: `ANALYTICS_INVALID_DATASET` 422, `ANALYTICS_INSUFFICIENT_DATA` 422,
`ANALYTICS_UNSUPPORTED_TYPE` 422, `ANALYTICS_METHOD_FAILED` 500, `ANALYTICS_INTERNAL_ERROR` 500,
`ANALYTICS_UNAUTHORIZED` 401, `ANALYTICS_PAYLOAD_TOO_LARGE` 413.

## PY-API-001 GET /health
`{"status":"ok","service":"dataspeak-analytics","version":"1.0"}`

## PY-API-003 POST /v1/validate  (multipart: `file`, `metadata` JSON string)
metadata: `{"analysis_id":"<uuid>"}`
```json
{ "valid": true, "row_count": 12450, "column_count": 8, "columns": ["order_date", "..."],
  "warnings": [ { "code": "DUPLICATE_ROWS", "message": "37 duplicate rows", "column": null } ],
  "errors": [] }
```
Structural failure returns 200 with `valid:false` and populated `errors`; unreadable file returns 422 `ANALYTICS_INVALID_DATASET`.

## PY-API-002 POST /v1/profile  (multipart: `file`, `metadata`)
metadata: `{"analysis_id":"<uuid>","definitions":[FieldDefinition...]}` (definitions optional)
```json
{ "dataset": { "rows": 12450, "columns": 8, "completeness": 0.981, "duplicate_rows": 37 },
  "columns": [ { "name": "revenue_thb", "position": 3, "inferred_type": "numeric",
                 "null_count": 50, "null_rate": 0.004, "distinct_count": 8120,
                 "stats": { "min": 120.0, "max": 89400.0, "mean": 292.4, "median": 210.0, "p25": 150.0, "p75": 380.0, "std": 310.2 } } ],
  "quality_findings": [ { "code": "HIGH_NULL_RATE", "severity": "warning", "message": "12.7% missing", "column": "discount_pct" } ] }
```
`stats` for category columns: `{ "top_share": 0.34, "top_count": 4215 }` (counts only, no labels).
Date columns: `{ "min": "2025-01-01", "max": "2025-12-31", "span_days": 364 }`. Text/identifier columns: `{}`.
No raw row samples anywhere.

## PY-API-004 POST /v1/analyze  (multipart: `file`, `metadata`)
metadata: `{"analysis_id":"<uuid>","definitions":[...],"options":{"seed":42}}`
```json
{ "analysis_id": "<uuid>", "evidence": [ Evidence... ],
  "summary": { "evidence_count": 18, "families_run": ["descriptive","trend"], "skipped": [ { "family": "association", "reason": "ANALYTICS_INSUFFICIENT_DATA" } ] } }
```

## PY-API-005 POST /v1/insights  (JSON)
```json
{ "analysis_id": "<uuid>", "evidence": [ Evidence... ], "definitions": [...], "options": { "max_insights": 10 } }
```
```json
{ "insights": [ { "insight_type": "group_difference", "headline": "Revenue differs between regions",
                  "description": "...neutral wording...", "score": 0.82, "rank": 1,
                  "primary_evidence_id": "ev_...", "evidence_ids": ["ev_...", "ev_..."] } ] }
```

## PY-API-006 POST /v1/visualizations/recommend  (JSON)
```json
{ "analysis_id": "<uuid>", "evidence": [ Evidence... ], "insights": [ Insight... ], "definitions": [...] }
```
```json
{ "visualizations": [ { "type": "line", "title": "Monthly Revenue",
    "x": { "field": "order_month", "label": "Month" },
    "y": { "field": "revenue", "label": "Revenue", "unit": "THB" },
    "unit_missing": false,
    "series": [ { "order_month": "2025-01", "revenue": 980000.0 } ],
    "evidence_ids": ["ev_..."], "insight_rank": 1 } ] }
```
`series` holds aggregated points taken from evidence payloads, never raw rows.
