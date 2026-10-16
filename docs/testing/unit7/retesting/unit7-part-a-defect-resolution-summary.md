# Defect Resolution and Retesting Summary (Unit 7, Part A)

University of the People, MSIT 5910-01 Capstone Project. Data Speak. Prepared 2026-10-16. All figures below come from the execution records in `docs/testing/unit7/` (baseline, 2026-10-15, commit e5ecc93) and `docs/testing/unit7/retesting/` (post-fix baseline, 2026-10-16, commits 0b33df0 to 524842f). The original failing evidence was preserved unchanged.

## 1. Initial test findings

System testing on 15 October 2026 executed 8 integration cases, 15 system cases, 4 regression suites and 3 performance scenarios against the local development stack. After triage of five harness errors, every functional case passed; the analytics suite reported 49 tests and 93% line coverage, the backend 148 tests, the frontend 28 tests, and all 33 golden-reference assertions reproduced. The testing nevertheless surfaced eight product defects, none of which caused a functional test to fail: they were found through rendered-text inspection, a production report read, worker logs, a queue-timing anomaly, accessibility scanning and a review of the interface during the workflow.

## 2. Defects identified

| ID | Title | Severity |
| --- | --- | --- |
| DEF-01 | A claim containing doubled braces passed validation and rendered literal braces in a production report | Major |
| DEF-04 | A single queue worker let background translation jobs delay user-facing profiling and analysis by minutes | Medium |
| DEF-06 | The CI analytics job failed on the frozen OpenAPI contract because of unpinned dependency versions | Medium |
| DEF-02 | A chart explanation repeated the chart headline sentence | Minor |
| DEF-03 | The statistic label "rho" appeared in business-facing sentences | Minor |
| DEF-05 | "Run again" was disabled without explanation after the transient file was purged | Minor |
| DEF-07 | Low-contrast secondary text and 21 × 16 px evidence links failed accessibility criteria | Minor |
| DEF-08 | The missing-value warning sat below the fold on the profile page | Minor |

## 3. Root causes and corrective actions

Each defect was traced to a specific cause before any change was made. DEF-01 arose because the rule-based validator inspected only well-formed `{ev_….field}` tokens; a doubled brace still contained a valid token, so the claim was SUPPORTED, and the renderer left the outer braces. The validator now collapses doubled braces before applying its rules and rejects any claim in which a brace survives after all valid placeholders are removed, so no unsupported or malformed statement can be presented as verified. DEF-04 arose from one `queue:work` process serving both queues; a dedicated translations worker was added in the development and production compose files. DEF-06 arose from open version ranges; the six schema-generating libraries are pinned to the versions that produced the contract. The minor defects were corrected in the report assembler (display-only removal of a repeated lead sentence), the analytics display formatting and four sentence templates, the completion panel, the profile page layout and the component styles. Deterministic calculations, API contracts and the database schema were not changed.

## 4. Retesting procedures

Retesting combined four levels. (a) New automated tests encode each corrected behaviour: three validator tests including the exact production template shape, and three assembler tests. (b) The complete suites were re-executed: analytics 49 tests with coverage and the 33 golden assertions, backend 154 tests, frontend 28 tests with lint, type and build checks. (c) The CI analytics job was reproduced in a clean Python 3.12 container with the pinned requirements. (d) Scripted retests against the running stack reproduced each original condition and asserted the corrected behaviour: queue contention timing with both worker logs, rendered-text checks on a freshly generated report, browser checks of the completion panel and warning position, and axe-core scans with a bounding-box measurement of the claim links. Finally the complete Unit 7 integration and system harness (INT-01 to INT-08, SYS-01 to SYS-12) was re-executed against the corrected build with its known harness issues corrected, writing to a separate evidence directory; it started at 10:34 and produced 23 recorded cases.

## 5. Before-and-after results

| Measure | Before (2026-10-15) | After (2026-10-16) |
| --- | --- | --- |
| Claims with literal braces reaching the report | 1 SUPPORTED claim on production; validator accepted `{{…}}` | 0; `{{…}}` normalised, stray braces rejected (unit tests) |
| Profiling wall time when translation jobs are queued | 73.3 s (job itself 11 s) | 37.0 s wall, queue wait 2 s (job 35 s because the single local model was simultaneously serving a translation; 11 s when the model is idle) |
| CI analytics job (clean Python 3.12) | 48 passed, 1 failed | 49 passed, 0 failed |
| axe colour-contrast nodes (new analysis / profile / report / panel) | 4 / 10 / 1 / 1 | 0 / 0 / 0 / 0 |
| Claim link target | 21 × 16 px | 27 × 24 px |
| Missing-value warning position at 1080 px | 1,187 px (below the fold) | 401 px (visible) |
| Repeated lead sentences under charts | 2 on production | 0 |
| "rho=" in rendered statements | 5 on production | 0 |
| Backend tests | 148 passed | 154 passed |
| Analytics tests, coverage, golden assertions | 49, 93%, 33/33 | 49, 93%, 33/33 |
| Frontend tests | 28 passed | 28 passed |

## 6. Regression testing outcomes

No regression was observed. The deterministic layer produced identical evidence values (the Spearman statistic 0.7054 unchanged; only its display string changed), the frozen contract still matched, and the frontend build succeeded. The full harness re-run recorded 23 cases: 20 passed and 3 failed (SYS-09, SYS-08, INT-08); the report generated during the run (report status=READY claims_total=0 supported=0 generation 304s) and the claim-validation check (claims_total=0 supported=0; sampled statuses=[]; db=) confirm the corrected validator in an end-to-end context. The retesting revealed two further findings. First, an environment problem: the new translations worker container failed to boot because the development image omitted dev dependencies expected by the shared package manifest. It was corrected in the same commit series, the queue drained, and the scenario was re-run (RT-04b). Second, a new defect: once translations and narratives could run concurrently, the single local model served a 4½-minute translation while a narrative request waited and timed out at 300 s; the report was assembled without a narrative (no fabrication), and the three dependent cases passed when re-run with an idle model. This was registered as DEF-09 and deferred, because the production provider handles concurrent requests and the remedy is an operational choice that needs its own test cycle.

## 7. Remaining limitations

The hosted GitHub Actions run can only confirm DEF-06 after the next push; the local reproduction is the evidence available now. The DEF-01 guard prevents malformed placeholders but cannot make the model's prose better; narrative quality remains model-dependent. The translation worker adds a process to a small production host and should be observed after deployment. All retests ran on the development stack with the local Qwen model, so production timings and the Claude provider were not re-measured. Accessibility was verified with an automated scanner, not with assistive-technology users.

## 8. How defect resolution improves reliability

The most consequential change restores the product's central guarantee: statements shown as "checked against the calculated results" can no longer contain unresolved template fragments, because the validator now rejects them before storage and the renderer can no longer be handed a brace to leave behind. Separating the translation queue removes a scheduling path by which background work could make the interface appear stalled, directly addressing the responsiveness requirement. Pinning the schema libraries makes the CI pipeline a trustworthy signal again, so future regressions will be caught automatically rather than by manual testing. The interface changes reduce the chance of user error and misunderstanding on the paths that Unit 6 identified as weakest. Together with the added tests, these changes convert one-off findings into permanently enforced behaviour, which is the practical meaning of improved reliability.
