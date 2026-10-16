# Unit 7 Defect Resolution Report

Data Speak, University of the People MSIT 5910-01. Resolution work performed on 2026-10-16 against the Unit 7 baseline (`docs/testing/unit7/`, tested at commit `e5ecc93`). The original defect register, test results, logs and screenshots are preserved unchanged; this folder (`docs/testing/unit7/retesting/`) holds the post-fix baseline. Commit references are listed in `final-test-summary.md` once the changes were committed.

## 1. Analysis and prioritisation

| Rank | Defect | Severity (register) | Why this priority | Decision |
| --- | --- | --- | --- | --- |
| 1 | DEF-01 Claim with doubled braces passes validation and renders literal braces | Major | Analytical-correctness guarantee of the product ("validation verifies") is breached: a malformed statement reached readers labelled as checked. Observed on production. | **Fix** |
| 2 | DEF-04 Single worker lets translation jobs delay user jobs | Medium | Direct user impact: minutes of waiting for profiling and analysis after any report; cheap configuration fix. | **Fix** |
| 3 | DEF-06 CI fails on frozen OpenAPI contract (dependency drift) | Medium | Red pipeline hides regressions; blocks continuous verification. | **Fix** |
| 4 | DEF-05 "Run again" silently disabled after the file is purged | Minor | Misleading copy on a frequent path; small frontend change. | **Fix** |
| 5 | DEF-08 Missing-value warning below the fold | Minor | Affects the profiling task that Unit 6 identified as hardest; one-line layout change. | **Fix** |
| 6 | DEF-03 "rho=" in business sentences | Minor | Unit 6 usability concern; wording-only change in the deterministic layer's display value and four sentence templates. | **Fix** |
| 7 | DEF-07 Contrast and target-size accessibility failures | Minor | WCAG AA conformance; CSS-only change plus a padded link variant. | **Fix** |
| 8 | DEF-02 Repeated headline sentence under a chart | Minor | Cosmetic; display-only de-duplication in the assembler. | **Fix** |

All eight were fixable within scope without API or database changes, so none was deferred at this stage. Each change was made after the root cause had been established from code and stored evidence, and each has an automated test or a scripted retest.

## 2. Root causes and corrective actions

### DEF-01 Claim validation accepts malformed placeholders

- **Root cause.** `RuleBasedClaimValidator` inspected only tokens matching `\{ev_[a-f0-9]{12}\.[a-z_]+\}`. A model output such as `{{ev_d7f6a70c9c81.label}}` still contains a valid inner token, has no digits, and was therefore SUPPORTED. `NarrativeRenderer` substituted the inner token and left the outer braces, producing "{Sales Amount (THB)}" in the production report (stored claim `80861062…`, see `docs/testing/unit7/evidence/system/def01_production_claim_with_literal_braces.json`).
- **Change.** `backend/app/Services/Validation/RuleBasedClaimValidator.php`: (1) *Normalisation 0* collapses repeated braces around a valid token before any rule runs and records `BRACES_NORMALISED`; the normalised text is returned in the validated claim so the stored statement is clean. (2) *Rule 9* rejects any claim whose text still contains `{` or `}` after all valid placeholders are removed, with reason `STRAY_BRACES`. (3) Method name bumped to `rule_based_v2` because the rule set changed. `backend/app/Jobs/GenerateReportJob.php`: `STRAY_BRACES` added to the repairable reasons so the model gets its single rewrite round. Existing feature test updated for the version string.
- **Tests added.** `tests/Unit/RuleBasedClaimValidatorTest.php`: doubled braces normalised and rendered without braces; invented placeholder `{Sales Amount (THB)}` rejected; a lone brace rejected; the exact production template shape validates and renders clean.
- **Guarantee.** No claim containing a brace that is not part of a resolvable placeholder can be SUPPORTED; no SUPPORTED claim can render a brace.

### DEF-02 Repeated headline sentence

- **Root cause.** The assembler lists up to two statements under each chart. The model's second statement often begins by restating the first; exact-duplicate filtering existed, prefix repetition did not.
- **Change.** `ReportAssembler::trimRepeatedLead()` removes a leading sentence that was already shown under the same report, only when the remainder starts a new sentence; display-only, stored claims unchanged. Unit test `tests/Unit/ReportAssemblerTrimTest.php` (three cases).

### DEF-03 "rho" shown to business readers

- **Root cause.** The analytics service formatted the Spearman display value as `rho=+0.71`, and the sentence templates inserted it verbatim.
- **Change.** `analytics/app/services/analysis/association.py`: display value is the signed strength (`+0.71`); the statistic name remains in `method` and `payload` (numeric `value` unchanged, so no golden assertion moved). `backend/lang/{en,th,zh,my}/sentences.php`: "(strength :value)" and its equivalents. Covered by the existing analytics suite (49 tests, 33 golden assertions) and a scripted check that no rendered statement contains "rho=".

### DEF-04 Queue contention

- **Root cause.** One `queue:work` process served `default,translations`; priority ordering only applies between jobs, so a running translation (1 to 2 minutes with the local model) blocked the next user job. Worker log in `docs/testing/unit7/evidence/performance/worker_queue_log_15-38_to_15-46.log`.
- **Change.** `docker-compose.yml`: `backend-worker` now serves `default` only; new `backend-worker-translations` (extends the worker) serves `translations`. `deploy/aws/docker-compose.prod.yml`: `worker` serves `default`; new `worker-translations` service; `deploy/aws/deploy.sh` starts it. Both compose files validate (`docker compose config --quiet`). No code change; queue names were already separate (`TranslateReportJob::QUEUE = 'translations'`).
- **Retest.** Scripted: generate a report (three translation jobs queued), immediately profile another dataset, measure wall time and confirm from both worker logs that translations ran on the dedicated worker (`RT-04`).

### DEF-05 Disabled "Run again" without explanation

- **Root cause.** The completion panel disabled the button when `dataset.transient_file_present` was false but kept the sentence inviting a re-run and gave no reason.
- **Change.** `frontend/src/pages/AnalysisRunPage.tsx`: when the file has been purged the panel explains that the upload was deleted after the run to protect the data and offers "Upload the file again", which navigates to the re-upload route; otherwise "Run again" is shown enabled. New strings added to the Thai, Chinese and Burmese dictionaries. Retest `RT-05` by browser.

### DEF-06 CI dependency drift

- **Root cause.** `analytics/requirements.txt` used open ranges; CI resolved FastAPI 0.143 / Pydantic 2.14, whose OpenAPI output differs from the frozen contract generated with FastAPI 0.128.8 / Pydantic 2.13.5 (`contentMediaType` versus `format: binary`).
- **Change.** Exact pins for the schema-generating libraries (fastapi, starlette, pydantic, pydantic-core, pydantic-settings, python-multipart) with a comment describing the bump procedure; numerical libraries keep their ranges. The development analytics image was rebuilt and now reports the pinned versions.
- **Verification.** The CI analytics job was reproduced in a clean `python:3.12-slim` container with the pinned requirements: Ruff clean, 49 passed including the contract test (`logs/def06_ci_reproduction_after_fix.log`). The hosted workflow itself runs only on push; its result is recorded in the final summary when available.

### DEF-07 Contrast and target size

- **Change.** Inactive stepper labels and circles, grey "(0)" counts and the verification footnote moved from `slate-400` to `slate-600` (contrast above 4.5:1 on the page backgrounds). `ClaimLink` gained a `compact` variant used for the numbered executive-summary links: minimum 24 × 24 px target, padding, hover background and an accessible name ("How we know, statement n"). Retest `RT-07` with axe-core and a bounding-box measurement.

### DEF-08 Warning below the fold

- **Change.** `frontend/src/pages/DataProfilePage.tsx`: the "Data quality" section now renders before the columns table, so a missing-value warning sits directly under the overview cards. Retest `RT-08` measures the warning's position at a 1080 px viewport.

## 3. Change log (file, defect)

| File | Defect | Nature |
| --- | --- | --- |
| backend/app/Services/Validation/RuleBasedClaimValidator.php | DEF-01 | Normalisation 0, Rule 9, method v2 |
| backend/app/Jobs/GenerateReportJob.php | DEF-01 | STRAY_BRACES repairable |
| backend/tests/Unit/RuleBasedClaimValidatorTest.php | DEF-01 | +3 tests |
| backend/tests/Feature/Api/GenerateReportJobTest.php | DEF-01 | version string expectation |
| backend/app/Services/Reports/ReportAssembler.php | DEF-02 | trimRepeatedLead |
| backend/tests/Unit/ReportAssemblerTrimTest.php | DEF-02 | new test |
| analytics/app/services/analysis/association.py | DEF-03 | display value |
| backend/lang/en,th,zh,my/sentences.php | DEF-03 | wording |
| docker-compose.yml, deploy/aws/docker-compose.prod.yml, deploy/aws/deploy.sh | DEF-04 | worker split |
| analytics/requirements.txt | DEF-06 | pins |
| frontend/src/pages/AnalysisRunPage.tsx, src/i18n/{th,zh,my}.ts | DEF-05 | panel and strings |
| frontend/src/pages/DataProfilePage.tsx | DEF-08 | section order |
| frontend/src/components/layout/AnalysisStepper.tsx, components/profile/ColumnProfileTable.tsx, components/report/ExecutiveSummary.tsx, components/report/ClaimLink.tsx | DEF-07 | contrast, link target |

No API contract, database schema or deterministic calculation was changed. Evidence values (means, medians, correlations) are identical before and after; only the Spearman *display string* changed.
