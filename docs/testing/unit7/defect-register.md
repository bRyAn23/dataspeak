# Unit 7 Defect and Limitation Register

Data Speak, commit `e5ecc93`, local development stack unless stated. Severity scale: Critical, Major, Medium, Minor. Status values: OPEN, FIXED, VERIFIED, DEFERRED, BLOCKED. **No application code was changed during the Unit 7 test execution (2026-10-15); the statuses recorded on that date are kept in section "Original status" below.** Resolution work followed on 2026-10-16 and is documented in `retesting/`; the current status of each defect is in the table at the end of this file. Baseline evidence is preserved under `evidence/`.

## Product defects (status column shows the current status after the 2026-10-16 retests; the original 2026-10-15 status was OPEN for every defect)

| ID | Test case | Title | Severity | Status |
| --- | --- | --- | --- | --- |
| DEF-01 | INT-08, DEF-CHK-01, Unit 6 walkthrough W1 | Claim with doubled braces passes validation and renders literal braces in the report | Major | VERIFIED |
| DEF-02 | DEF-CHK-02, Unit 6 W4 | Chart headline sentence repeated inside the second explanatory sentence | Minor | VERIFIED |
| DEF-03 | DEF-CHK-02, Unit 6 concern 2 | Statistical term "rho=+0.71" in business-facing sentences without explanation | Minor | VERIFIED |
| DEF-04 | PERF-3 | Single queue worker lets background translation jobs delay user-facing profiling and analysis by minutes | Medium | VERIFIED |
| DEF-05 | LIM-01R | "Run again" is silently disabled after the transient file is purged while the panel text invites running again | Minor | VERIFIED |
| DEF-06 | Unit 6 C04 §5b | CI analytics job fails on the frozen OpenAPI contract because dependency versions are unpinned | Medium | VERIFIED (local CI reproduction) |
| DEF-07 | Unit 6 instrumented measures | Low-contrast secondary text (2.51:1, 2.63:1) and 21 × 16 px claim links | Minor | VERIFIED |
| DEF-08 | SYS-03, Unit 6 W3 | Missing-value warning box is below the fold at 1080 px; only "See below" and a red percentage signal it | Minor | VERIFIED |

### DEF-01 Claim with doubled braces passes validation and renders literal braces

- **Reproduction:** Generate a report whose narrative model emits a placeholder wrapped twice, e.g. `{{ev_d7f6a70c9c81.label}}`. Observed on the production reference report (Claude driver) on 2026-10-14 and re-read on 2026-10-15: claim `80861062…`, FINDING, status SUPPORTED, in_report true, statement rendered as "The difference in {Sales Amount (THB)} between {Region} groups is statistically meaningful, not a coincidence."
- **Expected:** A claim containing malformed placeholder tokens is rejected or normalised before it can be SUPPORTED; the rendered text contains no braces.
- **Actual:** `RuleBasedClaimValidator` only inspects tokens matching `\{ev_[a-f0-9]{12}\.[a-z_]+\}`; the inner token matches, no digits are present, so the claim is SUPPORTED. `NarrativeRenderer` replaces the inner token and leaves the outer braces.
- **Root cause (established from code and the stored claim):** no normalisation of `{{…}}` and no check for stray `{` or `}` after rendering (`backend/app/Services/Validation/RuleBasedClaimValidator.php`, `backend/app/Services/Reporting/NarrativeRenderer.php`).
- **Evidence:** `evidence/system/def01_production_claim_with_literal_braces.json` (statement, statement_template and validation of the affected claim), `docs/evidence/unit6/usability/walkthrough/10_T3_first_chart.png`.
- **Local retest (DEF-CHK-01, Qwen driver):** 0 literal brace fragments in the rendered report on 2026-10-15; the defect depends on the model's output, so absence in one run is not a fix.
- **Proposed resolution (not applied):** collapse repeated braces around a valid token before validation and reject any claim whose rendered text still contains `{` or `}`; add a unit test with a doubled-brace template.
- **Retest result:** not retested (no fix). **Status: OPEN.**

### DEF-02 Repeated headline sentence in chart explanation

- Observed in the production report on 2026-10-14 (Findings #1 and #2 start the second sentence by repeating the first). On 2026-10-15 the local Qwen report showed 0 repeats (DEF-CHK-02). Cause not established; likely the model copying the chart headline into a second claim and placement keeping both. Evidence: Unit 6 walkthrough screenshot 10; `evidence/system/def-report-text-check.png`. OPEN, not reproduced locally.

### DEF-03 "rho" shown to business readers

- Unit 6 concern 2 re-verified: the production executive summary (Claude) contained "(rho=+0.71)" five times across the report; the local Qwen report on 2026-10-15 contained none. The prompt forbids method names but not the symbol; `EvidenceSentences` for Spearman passes the display value through. Expected: plain-language phrasing ("moved together strongly"). Evidence: `docs/evidence/unit6/usability/instrumented_measurements.json` (exec_summary), `evidence/system/def-report-text-check.png`. OPEN.

### DEF-04 Queue contention from background translation jobs

- **Reproduction:** Complete a report with `PRETRANSLATE_REPORTS=true` (three `TranslateReportJob` runs are queued), then start profiling or analysis for another dataset.
- **Expected:** User-facing jobs are not delayed by minutes behind background translation work.
- **Actual:** `backend-worker` processes `default,translations` serially. Worker log 15:38 to 15:44: TranslateReportJob 1 m 11 s, 1 m 2 s, 2 m 8 s; the 100-row profiling job waited and completed only at 15:41:17 (job itself 11 s), the run job at 15:43:26 (job itself 0.16 s). The harness measured 73 s and 128 s for stages that take 11 s and 0.2 s when the queue is idle.
- **Root cause:** one worker process for both queues; translation jobs are long because of the local model.
- **Evidence:** `evidence/performance/worker_queue_log_15-38_to_15-46.log`, `performance-results.csv` (P3 small).
- **Proposed resolution (not applied):** run a second worker dedicated to `translations`, or lower its priority with a separate `queue:work translations` process. **Status: OPEN.**

### DEF-05 Disabled "Run again" without explanation

- After a run, the transient CSV is purged (`purged_at` set, `transient_file_present=false`, by design under the privacy rule). The Analyze page then shows "This analysis is complete … or run again to recalculate from the current definitions" while the "Run again" button is disabled with no title, tooltip or `aria-describedby`; the API would answer 409 `UPLOAD_REQUIRED` ("Upload the CSV again to run the analysis"). Expected: the control explains why it is disabled or offers "Upload the file again". Evidence: `evidence/system/figure-08-defect-or-limitation.png`, LIM-01R record. OPEN.

### DEF-06 CI failure from dependency drift

- `.github/workflows/ci.yml` analytics job fails on `test_openapi_file_matches_application` because newer FastAPI/Pydantic emit `contentMediaType` for upload fields instead of `format: binary`; `requirements.txt` has open ranges. Reproduced in a clean Python 3.12 container on 2026-10-14 (48 passed, 1 failed). Evidence: `docs/evidence/unit6/logs/C07_pytest_python312_ci_reproduction.log`. Proposed: pin the schema-generating libraries or regenerate the contract under pinned versions. OPEN.

### DEF-07 Accessibility: contrast and target size

- axe-core 4.13 on 2026-10-14: inactive stepper labels 2.51:1, grey counts and the verification footnote 2.63:1 (AA requires 4.5:1); executive-summary claim links 21 × 16 px (WCAG 2.2 minimum 24 × 24). Not re-measured on 2026-10-15. Evidence: `docs/evidence/unit6/usability/instrumented_measurements.md`. OPEN.

### DEF-08 Missing-value warning below the fold

- SYS-03 required scrolling to reach the yellow Data quality box (top edge 1,187 px at a 1080 px viewport on 2026-10-14; the same layout observed today). The "Quality notes 1, See below" card and the red "14.6% (35)" cell are the only above-the-fold cues. Evidence: `evidence/system/sys05-profile-overview.png`, `sys03-missing-values-warning.png`. OPEN.

## Limitations (by design or out of scope, not defects)

| ID | Limitation | Evidence |
| --- | --- | --- |
| LIM-01 | Re-running a completed analysis requires re-uploading the file because the raw CSV is purged after the run (privacy rule NFR-PRI-001); the API returns 409 `UPLOAD_REQUIRED` and the UI disables the control (see DEF-05 for the messaging gap) | LIM-01R, `figure-08-defect-or-limitation.png`, API probe |
| LIM-02 | Narrative stages depend on an LLM and are non-deterministic in content and duration (94 s report generation with the local 8B model; production uses a different provider) | SYS-09 |
| LIM-03 | Local environment differs from production (PHP built-in server, local model, laptop hardware); timings are not production figures | `evidence/environment/` |
| LIM-04 | Failure injection covered the analytics service only; database, Redis and model outages were not injected (backend unit tests cover model outage with fakes) | INT-06, REG-03 |
| LIM-05 | No concurrency or load testing; all measurements are sequential single-client | `performance-results.csv` |
| LIM-06 | Acceptance verification was investigator-led; no external users participated | `acceptance-checklist.md` |

## Unit 6 usability concerns re-examined

| Concern | Finding on 2026-10-15 | Status |
| --- | --- | --- |
| Dense data-profiling table may be hard to interpret | The table has 7 rows and 5 columns, each row carrying a name, a type line and a one-sentence reason; it is legible at 1080 px but information-dense. No participant data exists to confirm or refute difficulty. | OPEN as an unverified observation |
| "rho" needs a plain-language explanation | Present in the production (Claude) report, absent in the local (Qwen) report | OPEN, see DEF-03 |

## Test-harness issues (not product defects, corrected during triage)

| ID | First-run case | Cause | Correction |
| --- | --- | --- | --- |
| H-01 | INT-07 | Harness looked for `evidence_keys`; the resource field is `evidence_ids` | INT-07R |
| H-02 | SYS-05 | Case-sensitive match against CSS-uppercased card labels | SYS-05R |
| H-03 | SYS-04 | Harness waited for a server round trip; the client rejects `.txt` and empty files before upload with a message and a disabled button | SYS-04R |
| H-04 | SYS-12 | Re-run attempted on a completed analysis whose CSV had been purged (409 UPLOAD_REQUIRED by design) | SYS-12R on a fresh analysis |
| H-05 | LIM-01 | Expected an error message; the UI disables the control instead | LIM-01R (and DEF-05 for the messaging gap) |


## Current status after resolution and retesting (2026-10-16)

| ID | Fix commit | Correction | Retest | Result | Status |
| --- | --- | --- | --- | --- | --- |
| DEF-01 | 0b33df0 | Validator normalises doubled braces and rejects stray braces (`STRAY_BRACES`); method `rule_based_v2` | 3 new unit tests incl. the production template shape; RT-01 rendered report has 0 brace fragments; full backend suite 154 passed | PASS | **VERIFIED** |
| DEF-02 | 4db9db6 | `ReportAssembler::trimRepeatedLead` (display only) | 3 unit tests; RT-02 no repeated lead sentence under any chart | PASS | **VERIFIED** |
| DEF-03 | 9bc1e3f | Spearman display value `+0.71`, sentences say "strength" in 4 languages | Analytics 49 tests and 33 golden assertions unchanged; RT-03 no `rho=` in rendered statements | PASS | **VERIFIED** |
| DEF-04 | 0a3137f | Dedicated translations worker in dev and prod compose; dev image installs dev dependencies | RT-04: 12.2 s to PROFILED during the translation window (73.3 s before) but the new worker had not booted (stale vendor volume) and was repaired; RT-04b with the repaired worker: profiling started 2 s after the report while three translations ran on the dedicated worker; 37.0 s wall (model shared), queue wait eliminated | PASS | **VERIFIED** |
| DEF-05 | 524842f | Completion panel explains the purged file and offers "Upload the file again" | RT-05 by browser: explanation shown, button present, navigates to re-upload; no disabled control | PASS | **VERIFIED** |
| DEF-06 | 457148b | Exact pins for fastapi, starlette, pydantic, pydantic-core, pydantic-settings, python-multipart | CI analytics job reproduced in python:3.12-slim: 49 passed incl. the contract test (was 48/1); dev image rebuilt with pinned versions | PASS locally; hosted workflow runs on the next push | **VERIFIED (local reproduction)** |
| DEF-07 | 524842f | slate-600 secondary text; padded 24 px numbered claim links with accessible names; div for role=dialog | RT-07/07b axe: 0 colour-contrast nodes on new analysis, profile, report and evidence panel (was 4/10/1/1); link 27 × 24 px (was 21 × 16); remaining axe item on the panel cleared by the role change | PASS | **VERIFIED** |
| DEF-08 | 524842f | Data quality section rendered above the columns table | RT-08 warning top edge at 401 px of 1080 (was 1,187), visible without scrolling | PASS | **VERIFIED** |

Limitations LIM-01 to LIM-06 are unchanged; LIM-01 (re-upload after purge) is now explained in the interface. Test-harness issues H-01 to H-05 were corrected in the script itself on 2026-10-16 (plus H-00: invalid-input files were read from the output directory instead of the fixed inputs folder) before the clean post-fix baseline run.

### New defect found during retesting

| ID | Test case | Title | Severity | Status |
| --- | --- | --- | --- | --- |
| DEF-09 | SYS-09 (post-fix baseline run, first attempt) | Narrative generation times out when the single local model is busy with a translation job | Medium (development stack); not applicable to the production provider | DEFERRED |

- **Observed:** 2026-10-16 10:36 to 10:41. `GenerateReportJob` for the run's analysis called Ollama while `backend-worker-translations` was executing a 4 min 34 s `TranslateReportJob` from an earlier report. The narrative call exceeded `OLLAMA_TIMEOUT` (300 s); `ai_generations` recorded `FAILED / AI_TIMEOUT`; the report was assembled deterministically (READY, 0 claims, data notes present). SYS-08 and INT-08 could not execute in that attempt and were re-run with an idle model (SYS-09b, SYS-08b, INT-08b).
- **Root cause:** the DEF-04 worker split allows translation and narrative requests to reach the local model concurrently; Ollama serves them serially per model, so a long translation starves the user-facing narrative call. Before DEF-04 the same work was serialised by the queue (and delayed user jobs instead).
- **Behaviour was correct in one respect:** no narrative was fabricated and the failure was logged with a specific code; the interface shows the report without an executive summary.
- **Why deferred:** production uses the Claude API, which handles concurrent requests, so the condition is specific to the single local model; the remedy is an operational choice (raise `OLLAMA_NUM_PARALLEL`, pause pre-translation while a narrative is generating, or retry the narrative once) that needs its own test cycle. Recorded, not fixed.
- **Evidence:** `retesting/logs/system_tests_post_fix.log` (SYS-09 first attempt), worker logs quoted in `retesting/defect-retesting-results.md`, `ai_generations` row `AI_TIMEOUT`.

