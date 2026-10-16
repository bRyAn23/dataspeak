# Unit 7 Defect Retesting Results

Executed 2026-10-16 on the local development stack (never production) after the corrections in `defect-resolution-report.md` were applied and committed (0b33df0, 4db9db6, 9bc1e3f, 0a3137f, 457148b, 524842f). Each defect was retested in two ways: the original reproduction condition from the 2026-10-15 baseline, and a new check of the corrected behaviour. Raw results: `evidence/post_fix_retests.json`; logs in `logs/`; screenshots in `evidence/`.

## 1. Retest matrix

| Retest | Defect | Reproduction condition (baseline result, 2026-10-15) | Post-fix observation (2026-10-16) | Result |
| --- | --- | --- | --- | --- |
| Unit tests DEF-01 | DEF-01 | Production claim template with `{{ev_….label}}` was SUPPORTED and rendered "{Sales Amount (THB)}" | `test_def01_production_template_shape_is_normalised`: validates, renders with no brace; `test_def01_stray_braces_are_rejected`: `{Sales Amount (THB)}` and a lone brace rejected with `STRAY_BRACES`; `test_def01_doubled_braces_are_normalised_and_render_cleanly`: `BRACES_NORMALISED`, clean render | PASS |
| RT-01 | DEF-01 | Rendered report text checked for brace fragments (local baseline 0, production 2) | New local report (13 claims, 10 supported, validator `rule_based_v2`): 0 statements with braces; no brace-related reasons needed in this run | PASS |
| Unit tests DEF-02 | DEF-02 | Second chart sentence repeated the headline (production, 2 charts) | `ReportAssemblerTrimTest`: repeated lead removed, unrelated and identical texts untouched, partial-word overlap not trimmed | PASS |
| RT-02 | DEF-02 | Chart explanations compared pairwise for a repeated leading sentence | 0 repeated leads across the report's chart summaries | PASS |
| RT-03 | DEF-03 | "rho=" counted in rendered statements (production 5, local 0) | 0 occurrences; analytics evidence now carries `display_value "+0.71"`, `value 0.7054`, `method spearman_rank_correlation` | PASS |
| RT-04 | DEF-04 | Profiling started right after a report (translations queued) took 73.3 s to PROFILED; worker log showed the job waiting behind TranslateReportJob | 12.2 s to PROFILED during the same condition. However the log check found no translation jobs: the new translations worker container had failed to boot (stale vendor volume, see section 2), so the queue was not being consumed. Recorded as FAIL and investigated. | FAIL (environment) |
| RT-04b | DEF-04 | Same scenario after the worker was repaired | Profiling of the second dataset started 2 s after the report completed (default worker log 03:20:21 report DONE, 03:20:23 ProfileAnalysisJob RUNNING) while three TranslateReportJob runs executed on `backend-worker-translations` (03:20:21 to 03:23:56). Wall time to PROFILED 37.0 s, of which 35 s was the job itself: the local Ollama model was serving a translation at the same time, so the suggestion call ran slower than the idle-model 11 s. Queue waiting, the defect's mechanism, was eliminated (baseline 73.3 s wall with the job waiting behind translations). Translations queue drained to 0 afterwards. Log `evidence/rt04b_worker_logs.log`. | PASS |
| RT-05 | DEF-05 | "Run again" disabled with no explanation on a completed analysis (figure 08) | Panel reads "…The uploaded file was deleted after the run to protect your data, so upload it again if you want to recalculate."; button "Upload the file again" present and navigates to `/analyses/new?id=…`; no disabled control | PASS |
| CI reproduction | DEF-06 | python:3.12-slim with unpinned requirements: 48 passed, 1 failed (frozen contract) | Same container with pinned requirements: fastapi 0.128.8, pydantic 2.13.5, starlette 0.49.3 resolved; Ruff clean; 49 passed | PASS |
| RT-07 | DEF-07 | axe colour-contrast nodes: new analysis 4, profile 10, report 1, panel 1; claim link 21 × 16 px | After first pass: 0, 0, 1 (the "calculated" badge), link 27 × 24 px | FAIL (one node left) |
| RT-07b | DEF-07 | Re-scan after the badge and technical-details labels were corrected and the panel's `aside role=dialog` became a `div` | Report 0 violations; evidence panel: 0 colour-contrast (one `aria-allowed-role` minor reported before the role change, cleared by it); link 27 × 24 px | PASS |
| RT-08 | DEF-08 | Warning box top edge at 1,187 px (below a 1080 px viewport) | Top edge at 401 px, visible without scrolling, directly under the overview cards | PASS |

## 2. Issue found during retesting: translations worker failed to start

The first RT-04 run showed the user-facing improvement (12.2 s instead of 73.3 s) but no translation activity. Inspection showed the new `backend-worker-translations` container crash-looping with `Class "Laravel\Pail\PailServiceProvider" not found`: the development Dockerfile installed Composer packages with `--no-dev`, while the package manifest in the bind-mounted `bootstrap/cache` (shared by all containers) lists dev-only providers that the older containers had obtained from a hand-updated vendor directory. Three translation jobs were waiting in Redis (`LLEN laravel-database-queues:translations = 3`).

Correction (included in commit 0a3137f): the development image installs dev dependencies; the container was recreated with a renewed anonymous volume (`docker compose up -d --force-recreate -V`). Log `logs/def04_translations_worker_repair.log` shows the worker starting, the first `TranslateReportJob` completing in 14 s and the queue length falling from 3 to 1 while the default worker stayed free. RT-04b then repeated the full scenario.

Lesson recorded: a configuration fix that adds a container must be verified by observing the container do work, not only by validating the compose file.

## 2b. Issue found during the clean baseline run: DEF-09

In the corrected-harness run the report for the workflow analysis came back READY with 0 claims after 304 s: `ai_generations` shows `FAILED / AI_TIMEOUT` at 10:41:37, and the translations worker log shows a `TranslateReportJob` that took 4 min 34 s over the same interval. With the queues now separated, a translation and a narrative request reach the single local Ollama model at the same time and the narrative call hits the 300 s timeout. The system degraded truthfully (no fabricated narrative, data notes shown). SYS-08 and INT-08 depend on narrative claims and therefore failed in that attempt; all three were re-run with an idle model (SYS-09b, SYS-08b, INT-08b, results below). Registered as DEF-09, DEFERRED with justification in `../defect-register.md`.

## 3. Retest screenshots

| File | Shows |
| --- | --- |
| `evidence/rt05_completion_panel_after_fix.png` | Completion panel with the explanation and "Upload the file again" (compare baseline figure 08) |
| `evidence/rt05_upload_again_destination.png` | The re-upload page reached from that button |
| `evidence/rt08_profile_warning_position.png` | Profile page with the Data quality warning directly below the overview cards |
| `evidence/rt07_report_after_fix.png`, `evidence/rt07_traceability_after_fix.png` | Report and evidence panel after the contrast and link changes |
| `evidence/figure-09-regression-after-fix.png` | Terminal summary of the post-fix suites |

## 4. Summary

| Defect | Severity | Retest outcome | Status |
| --- | --- | --- | --- |
| DEF-01 | Major | unit tests and RT-01 pass | VERIFIED |
| DEF-02 | Minor | unit tests and RT-02 pass | VERIFIED |
| DEF-03 | Minor | RT-03 pass, suites unchanged | VERIFIED |
| DEF-04 | Medium | RT-04 showed the timing gain but exposed a boot failure in the new worker; repaired; RT-04b pass (queue wait 2 s; job slower only because the model was shared) | VERIFIED |
| DEF-05 | Minor | RT-05 pass | VERIFIED |
| DEF-06 | Medium | CI job reproduced green with pins | VERIFIED (local); hosted run pending next push |
| DEF-07 | Minor | RT-07b pass | VERIFIED |
| DEF-08 | Minor | RT-08 pass | VERIFIED |
