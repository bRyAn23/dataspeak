# Unit 7 Final Test Summary (post-fix baseline)

Data Speak, 2026-10-16. This document is the verified post-fix baseline. It does not replace the 2026-10-15 baseline in `docs/testing/unit7/`, which is kept unchanged for comparison.

## 1. Scope of this baseline

Same scope as the Unit 7 test plan: integration, system, regression, performance context and investigator-led acceptance on the local development stack; production excluded. The system under test is commit 524842f (`main`), i.e. the Unit 7 baseline commit e5ecc93 plus six defect-fix commits.

## 2. Commit references

| Commit | Defect(s) | Files |
| --- | --- | --- |
| 0b33df0 | DEF-01 | RuleBasedClaimValidator, GenerateReportJob, two test files |
| 4db9db6 | DEF-02 | ReportAssembler, ReportAssemblerTrimTest |
| 9bc1e3f | DEF-03 | association.py, lang/{en,th,zh,my}/sentences.php |
| 0a3137f | DEF-04 | docker-compose.yml, deploy/aws/docker-compose.prod.yml, deploy/aws/deploy.sh, docker/backend/Dockerfile |
| 457148b | DEF-06 | analytics/requirements.txt |
| 524842f | DEF-05, DEF-07, DEF-08 | AnalysisRunPage, DataProfilePage, AnalysisStepper, ColumnProfileTable, ClaimLink, ExecutiveSummary, ChartSummaryCard, TraceabilityPanel, i18n th/zh/my |

## 3. Execution results

| Testing type | Executed | Passed | Failed | Blocked | Skipped | Source |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Defect retests (RT-01..08 incl. RT-04b, RT-07b) | 9 | 7 | 2 (RT-04, RT-07; both superseded by passing re-runs RT-04b, RT-07b) | 0 | 0 | `evidence/post_fix_retests.json` |
| New and updated unit tests for fixes | 7 | 7 | 0 | 0 | 0 | `logs/phpunit_after_fix.log` |
| Analytics suite (pytest) | 49 | 49 | 0 | 0 | 0 | `logs/analytics_after_fix.log` |
| Golden-reference assertions | 33 | 33 | 0 | 0 | 0 | same |
| Backend suite (PHPUnit) | 154 | 154 | 0 | 0 | 0 | `logs/phpunit_after_fix.log` |
| Frontend suite (Vitest) | 28 | 28 | 0 | 0 | 0 | `logs/frontend_after_fix.log` |
| CI analytics job reproduced (pinned, py3.12) | 49 | 49 | 0 | 0 | 0 | `logs/def06_ci_reproduction_after_fix.log` |
| Full integration + system harness re-run (corrected harness) | 23 | 20 | 3 in the first attempt (SYS-09, SYS-08, INT-08: narrative timed out while the local model served a translation, DEF-09); re-runs SYS-09b, SYS-08b, INT-08b with an idle model passed | 0 | 0 | `evidence/system-run/system_test_results.json` |

Two retests failed on first execution and were resolved within the session: RT-04 (new worker container not booting; fixed, RT-04b) and RT-07 (one remaining low-contrast badge; fixed, RT-07b). In the harness re-run the narrative chain failed once because the local model was busy (DEF-09, deferred) and passed on re-run. An earlier attempt of the harness with the uncorrected script (harness issues H-00 to H-04) is archived under `evidence/system-run-attempt1-old-harness/` and is not counted. All first-run records are preserved.

## 4. Defect status

See `../defect-register.md` (current-status table) and `defect-retesting-results.md`. Summary: DEF-01, 02, 03, 04, 05, 07, 08 VERIFIED; DEF-06 VERIFIED by local CI reproduction (hosted run follows the next push); new DEF-09 (narrative timeout under concurrent local-model load) DEFERRED with justification.

## 5. Unavailable environments and blocked scenarios

- Hosted GitHub Actions: runs only on push, which the project owner performs; not executed in this session.
- Production: deliberately not used; the production reference report was read once (read-only) to obtain the DEF-01 claim template.
- Assistive-technology testing and participant usability testing: not available; accessibility verified with axe-core only.
- Terminal figure 09 was captured once the screen was unlocked; if the file is black, re-run `scripts/` capture after unlocking (see `logs/recapture_figure09.log`).

## 6. Overall assessment

With the corrections verified, the tested implementation again meets every planned criterion on the development stack, and the one Major defect in the validation layer is closed with regression tests. The system remains not demonstrated under production load or with the production model after these changes; those steps follow deployment of commits 0b33df0 to 524842f.
