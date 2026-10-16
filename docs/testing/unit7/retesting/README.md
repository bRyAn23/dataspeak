# Unit 7 retesting folder: index

Post-fix baseline for Data Speak created on 2026-10-16. The pre-fix baseline (2026-10-15) lives one level up in `docs/testing/unit7/` and was not modified except for the status table appended to `defect-register.md`.

| File | Purpose |
| --- | --- |
| defect-resolution-report.md | Analysis, prioritisation, root causes, corrective actions, change log per defect |
| defect-retesting-results.md | Reproduction of each original condition and the corrected behaviour, incl. the worker boot issue found during retesting |
| regression-test-results.md | Full suites before and after, numerical-correctness check |
| final-test-summary.md | Post-fix execution totals, commit references, unavailable environments, overall assessment |
| unit7-part-a-defect-resolution-summary.md | Academic summary for Unit 7 Part A |
| evidence/post_fix_retests.json | Raw results of RT-01..08, RT-04b, RT-07b |
| evidence/rt*.png, evidence/figure-09-regression-after-fix.png | Screenshots (browser) and the terminal capture of the suite summaries |
| evidence/system-run/ | Full re-execution of the Unit 7 integration and system harness against the fixed build (results JSON/CSV, screenshots, integration JSON) |
| logs/ | analytics_after_fix.log, phpunit_after_fix.log, frontend_after_fix.log, def06_ci_reproduction_after_fix.log, dev_stack_apply_def04_def06.log, def04_translations_worker_repair.log, post_fix_retests.log, rt04b_rerun.log, system_tests_post_fix.log |
| scripts/ | post_fix_retests.py, rt04_rerun.py (the harness itself is `../scripts/system_tests.py`, run with `U7_EVIDENCE_DIR` pointing here) |

Reproduce: apply commits 0b33df0..524842f, start the local stack (`docker compose up -d`), run the three suites, then `U7_SCRATCH=<dir with unit7_accounts.txt> python docs/testing/unit7/retesting/scripts/post_fix_retests.py` and `U7_EVIDENCE_DIR=docs/testing/unit7/retesting/evidence/system-run python docs/testing/unit7/scripts/system_tests.py`.
