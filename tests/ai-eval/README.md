# AI evaluation harness

Produces the numbers for the capstone evaluation chapter: how often the live model returns
schema-valid output, how many of its claims pass rule-based validation, which rules reject claims,
and how long inference takes. Nothing is persisted and no raw rows are involved (the model only ever
receives evidence records).

Prerequisites: a running Ollama with the configured model (`ollama pull qwen3:8b`) and an analysis
that has been run through the deterministic pipeline (status ANALYZED or COMPLETED).

```bash
# 1. Produce an analysed dataset (prints the analysis id at the end)
python3 tests/e2e/smoke_workflow.py --keep

# 2. Evaluate N runs
cd backend && php artisan dataspeak:ai-eval <analysis-public-id> --runs=5 --json
```

Output fields: `schema_valid_rate`, `failures` (by error code), `claims_total`,
`claim_acceptance_rate`, `claims_by_status`, `rejection_reasons_by_rule`, `avg_latency_ms`,
`avg_input_tokens`, `avg_output_tokens`, plus the model tag and prompt version evaluated.

With Docker: `docker compose exec backend php artisan dataspeak:ai-eval <id> --runs=5`.

## Recorded results (4 October 2026, qwen3:8b on an Apple Silicon laptop, 64 GB, native Ollama)

Dataset: the committed synthetic `retail_sales.csv` after the deterministic pipeline (analysis
`2c9d55f3-...`). Three runs per prompt version, temperature 0.1, seed 42, num_ctx 8192.

| Metric | exec-interp-v1 | exec-interp-v2 |
| --- | --- | --- |
| Schema-valid responses | 3 / 3 | 3 / 3 |
| Claims returned (total over 3 runs) | 24 | 33 |
| Supported | 9 (37.5%) | 21 (63.6%) |
| Rejected | 15 | 3 |
| Needs review | 0 | 9 |
| Rejection reasons | DIGITS_OUTSIDE_PLACEHOLDER 15 | PLACEHOLDER_FIELD_NOT_ALLOWED 3, LOW_QUALITY_EVIDENCE_AS_FINDING 3, CAUSAL_LANGUAGE 6 |
| Average latency | 24.4 s | 32.3 s |
| Average tokens in / out | 2563 / 1100 | 3924 / 1387 |

Reading: v2 (ready-made placeholder strings in the payload plus a correct example) removed the
literal-digit failure entirely. The remaining review flags are causal phrasing and warning-quality
evidence stated as findings; both are intended to reach a human reviewer rather than the report.
The cost is about 1,400 more input tokens and 8 seconds per generation. The live test group
(`php artisan test --group=ollama`) passed on the fixture scenario with 7 of 7 claims supported.

### Repair round (added 4 October 2026)

Claims rejected only for literal digits or bad placeholders are sent back to the model once with the
exact placeholders available for their evidence ids, then re-validated (`AiGateway::repair`). The
harness applies the same round and reports `claims_repaired`; `claim_acceptance_rate` is measured
after it. Dataset figures (row count, completeness, duplicates) are now passed to the model only as
evidence ids, which removes the most common source of literal digits in executive summaries.

### Results after the payload and validator changes (4 October 2026, user dataset "Retail Sales 2025", 48,215 rows)

| Metric | Before (v2, raw insight text, no repair) | After (placeholderised insight text, context evidence, normalisation, repair) |
| --- | --- | --- |
| Schema-valid responses | 3 / 3 | 3 / 3 |
| Claims supported | 0 of 15 | 15 of 15 |
| Rejected for literal digits | 12 | 0 |
| Normalisations applied | n/a | 12 citations completed |
| Average latency | 15.9 s | 22.9 s |
| Average tokens in / out | 2542 / 736 | 4551 / 1003 |

The regenerated report for that analysis shows a four-sentence executive summary, two findings and one
caveat, all SUPPORTED, with every value rendered from evidence placeholders.

### Prompt v3 (summary from charted insights, business wording), user dataset, 3 runs

Schema-valid 3 / 3, claims supported 33 of 33, no rejections, 18 citations completed, average latency
33.6 s, tokens 4716 in / 1455 out. The live report showed one multi-sentence summary claim that ended
with the record count; v4 asks for exactly one sentence per summary claim so that a sentence about
dataset mechanics is reclassified into the data notes on its own.

### Prompt iterations v4 to v6 (user dataset, 3 runs each)

| Version | Change | Supported | Rejected | Review | Notes |
| --- | --- | --- | --- | --- | --- |
| v4 | one sentence per summary claim | 36 / 36 | 0 | 0 | summary now chart-based; but "measure name where group belongs" sentences passed unnoticed |
| v5 | phrasing patterns + semantic guards (REPEATED_PLACEHOLDER, GROUP_NOT_NAMED) | 18 / 36 | 6 | 12 | guards caught the misuse; the model copied the patterns with wrong slots and invented period placeholders for evidence without periods |
| v6 | software-authored sentence per evidence item; model composes from them | 69 / 72 | 0 | 3 | 95.8% acceptance; the three review flags were group names typed instead of the group placeholder. Latency 74 s per run at about 6,100 input tokens |

Lesson: an 8B model is reliable at copying and ordering given sentences, and unreliable at filling
placeholder slots itself. Keeping the slot-filling in software keeps the facts right while the model
still decides emphasis, order, connecting words and caveats.

After v6 the remaining problem was placement, not facts: the model filed plain totals as the executive
summary and the chart-grounded statements as findings. Placement is now decided by software after
validation (`GenerateReportJob::place()`), so the executive summary is always the chart story in chart
order, whatever the model labelled.
