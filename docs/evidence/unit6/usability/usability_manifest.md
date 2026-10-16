# Usability Evidence Manifest

Prepared 2026-10-14. Classifies each item of the participant usability study as **observed** (recorded by the researcher during a session), **measured** (counted or averaged from recorded data), **interpreted** (researcher's reading of observed and measured data), or **prepared** (template or plan, contains no results).

| File | Kind | Content | State on 2026-10-14 |
| --- | --- | --- | --- |
| test_plan.md | prepared | Study design, tasks, success criteria, procedure, data handling, pre-session checklist | complete, no results |
| participant_instructions.md | prepared | Consent text and task scripts read to participants | complete |
| observation_form.md | prepared | Researcher's per-participant log: ID, category, date, per-task status, help given, difficulty, verbatim comments, optional duration | complete, blank |
| participant_feedback_form.md | prepared | Five 1-5 ratings and an open suggestion field | complete, blank |
| usability_results.csv | observed (when filled) | One row per participant per task | **header only, no data** |
| usability_feedback.csv | observed (when filled) | One row per participant | **header only, no data** |
| usability_summary.md | measured + interpreted (when produced) | Counts, rates, Table 2, rating means, themes | **placeholder, no data** |
| participant_usability_section.md | interpreted (when produced) | 130-180 word Part B subsection | **not created; requires data** |
| setup_check_production_report.png, setup_check_production_traceability.png | observed (set-up) | Production report of the reference analysis and its "How we know" panel as seen by the test account on 2026-10-14; proves the environment for T3 and T4 exists. Not participant data. | complete |
| cognitive_walkthrough.md | interpreted (expert inspection) | AI-assisted cognitive walkthrough of T1-T4 on production, 10 findings with severity, strengths, predictions for a future participant study | complete, 2026-10-14 |
| instrumented_measurements.md, .csv, .json | measured | Shortest paths, time to feedback, visibility and target size, axe-core results, readability, content defects; raw JSON | complete, 2026-10-14 |
| walkthrough/01-12 *.png | observed (interface state) | Unedited Playwright screenshots of each step on production | complete |
| B05_walkthrough_steps.png | observed (composite of 4 screenshots) | Figure 13 panel image | complete |
| inspection_section.md | interpreted | Part B insert, Table 2, Table 3, Figure 13 caption, clearly labelled as inspection not participant testing | complete |
| B05_task_completion_chart.png | measured (when produced) | Independent completion rate per task | **not created; requires participant data** |
| ../scripts/analyze_usability.py | prepared | Produces the summary, chart and figures from the CSVs; exits with an error while they are empty | complete |

Rules applied: no participant, rating, comment, timestamp or outcome is written by anyone other than the researcher transcribing a real session; participant ratings, observed behaviour and interpretation are kept in separate sections; the expert interface inspection already in the Unit 6 draft is a different method and is not counted as participant testing; production credentials appear in no file in this folder.
