# Usability Evaluation Summary

> **Update 2026-10-14 (evening):** participants were not available, so two inspection methods were run instead and are reported separately: [cognitive_walkthrough.md](cognitive_walkthrough.md) (AI-assisted expert inspection) and [instrumented_measurements.md](instrumented_measurements.md) (machine measurements), with the Part B insert in [inspection_section.md](inspection_section.md). They do not replace the participant study described below, which remains not started.

**Status: NOT STARTED. No participant sessions have been run and both data files contain headers only.** This file will hold Table 2 (task-based results), the optional survey-rating table, the qualitative themes and the completion-rate calculations once real observations have been entered in `usability_results.csv` and `usability_feedback.csv`. Nothing here may be filled in from assumptions.

Planned contents after data collection:

1. Participants: number, categories (technical / nontechnical), dates, environment and production commit.
2. Counts: tasks attempted, independent completions, assisted completions, failures; independent completion rate with the actual attempted count as denominator; per-task rates.
3. Table 2, *Task-Based Usability Evaluation Results* (Task, Participants attempting, Independent completions, Assisted completions, Failures, Observed usability issues).
4. Optional table of mean survey ratings (n, mean, min, max per question).
5. Qualitative themes drawn only from recorded participant comments, each theme linked to the participant IDs and quotes that support it.
6. Separation of participant ratings, observed behaviour and researcher interpretation.
7. Limitations (five participants, formative design, single dataset, researcher present).

Generation: `python3 ../scripts/analyze_usability.py` reads the two CSV files, refuses to run while they are empty, and writes this summary, `B05_task_completion_chart.png` and the numbers used in `participant_usability_section.md`.
