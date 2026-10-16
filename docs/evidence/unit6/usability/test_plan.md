# Data Speak Formative Usability Study: Test Plan

Data Speak, UoPeople MSIT 5910, Unit 6, Part B. Prepared 2026-10-14. **Status: materials prepared; no sessions run yet. No participant data exists.**

## 1. Purpose

A formative, task-based usability evaluation of the deployed Data Speak application with five voluntary participants. It complements, and is reported separately from, the expert interface inspection already in the Unit 6 draft. The aim is to observe whether business users can upload a dataset, read its profile, interpret a report and trace a claim to its evidence without help, and to collect their ratings and comments.

## 2. Participants

| Item | Plan |
| --- | --- |
| Number | 5 |
| Target profile | Business users, administrative or office staff, spreadsheet users, managers; at least two without a software-development background if available |
| Recruitment | Voluntary; no compensation; participants may stop at any time |
| Identification | Anonymous IDs P01 to P05 assigned in order of session; no names, emails, employers or other identifying data are recorded anywhere |
| Category recorded | `technical` (works in software development or IT) or `nontechnical` |

## 3. Environment

| Item | Plan |
| --- | --- |
| Application | Production deployment: https://dataspeaknow.com/ (API at https://api.dataspeaknow.com). The local development stack may be used as an equivalent fallback if production is unavailable; record which one was used. |
| Account | A restricted test account created for evaluation only. The researcher signs in before the session; participants never see or type credentials, and credentials are not written in any evaluation document. |
| Dataset | The synthetic file `analytics/tests/golden/retail_sales.csv` (240 rows, 7 columns, Thai and Myanmar headers, 14.6% of `discount_pct` missing). Give the participant a copy named `retail_sales.csv` on the desktop or a USB stick. No real customer data is used. |
| Reference analysis for T3 and T4 | Before the first session, the researcher completes one analysis of the same file on the test account (upload, profile, accept definitions, analyze, report) so that a finished executive report exists. Participants use their own upload for T1 and T2 and this completed analysis for T3 and T4, so that sessions do not depend on report-generation time. |
| Device | Researcher's laptop with an external mouse, or the participant's own computer in a current Chrome, Edge, Safari or Firefox. Record browser and device type on the observation form. |
| Code freeze | No application code, configuration or deployment change is made between the first and last session. The commit in production at the time of the sessions is recorded in the summary. |

## 4. Tasks

| ID | Task | Participant is asked to | Success criterion (independent completion) |
| --- | --- | --- | --- |
| T1 | CSV upload | Open Data Speak, create a new analysis, upload the supplied CSV, say whether validation succeeded | Uploads the file and states the validation outcome (success, 240 rows and 7 columns detected) without assistance |
| T2 | Data profiling | Open the profiling results, state the number of rows and columns, find a missing-value warning, explain what it means | States 240 rows and 7 columns, points to the `discount_pct` missing-value warning, and explains in their own words that some discount values are empty and statistics use the remaining rows |
| T3 | Report interpretation | Open the completed executive report, name one important KPI, explain one chart in their own words | Names a KPI card (for example total revenue or dataset records) and correctly describes the direction or comparison a chart shows (for example which region is highest, or whether a line rises or falls) without coaching |
| T4 | Claim traceability | Find an executive-summary sentence, open "How we know", identify the supporting calculation, explain what the evidence means | Opens the panel from a claim link, points to the evidence card, and gives a basic correct explanation (for example "revenue and units sold rise and fall together") |

Completion status definitions, applied by the researcher after the task:

- **independent**: completed within the success criterion with no hint or help.
- **assisted**: completed only after a hint or help; the help given is written in the comment.
- **failed**: not completed, abandoned, or completed with an incorrect answer to the criterion question.

Timing is optional. If recorded, start when the task instruction has been read aloud and stop when the participant gives the final answer; record whole seconds.

## 5. Session procedure (about 30 minutes)

1. **Welcome and consent (3 min).** Read the consent text in `participant_instructions.md`. Confirm the participant agrees to continue. Assign the next ID.
2. **Set-up (2 min).** Researcher signs in to the test account, opens the Analyses page, confirms the reference analysis is COMPLETED, and places `retail_sales.csv` where the participant can find it. Note date, device, browser and category on the observation form.
3. **Tasks T1 to T4 (15 to 20 min).** Read each task from `participant_instructions.md` exactly as written. Ask the participant to think aloud. Do not coach. If the participant is stuck for about two minutes or asks for help, give the smallest useful hint, then mark the task **assisted** and write down the hint. If the participant gives up or answers incorrectly, mark **failed**. Record observed difficulties and quotes verbatim.
4. **Feedback form (5 min).** Hand over `participant_feedback_form.md` (paper or a copy of the file). The participant fills in the five ratings and suggestions alone.
5. **Close (1 min).** Thank the participant. Transfer the observation and feedback data to the two CSV files the same day, one row per task and one row per participant. Keep the original paper or file as the primary record.

## 6. Data handling

- `usability_results.csv`: one row per participant per task (up to 20 rows).
- `usability_feedback.csv`: one row per participant (up to 5 rows).
- Comments are copied verbatim; translate to English only if needed and mark `[translated]`.
- Nothing identifying is recorded. Screenshots of the participant's screen are not taken; the application screenshots already in the evidence folder show the interface.
- The analysis in `usability_summary.md`, the chart and the written subsection are produced only after at least one complete session exists, using the actual number of attempted tasks as the denominator.

## 7. Researcher rules

- Do not change the application during the study period.
- Do not mark a task successful because the application worked; the criterion is what the participant did and said.
- Record failures and negative feedback exactly as observed.
- Keep ratings (participant), observed behaviour (researcher log) and interpretation (summary) in separate sections of the report.

## 8. Pre-session checklist

- [x] Production reachable on 2026-10-14: https://dataspeaknow.com/ loads and https://api.dataspeaknow.com/api/v1/health returns `status: ok` (re-check on each session day).
- [x] Test account signs in (verified 2026-10-14 on production).
- [x] Reference analysis of `retail_sales.csv` is COMPLETED with an executive report and "How we know" links. Created on production 2026-10-14 19:37 (+07) through the API on the test account; status COMPLETED, 13 claims, all 13 supported; the report page shows 17 claim links and the "How we know" panel opens with the "Checked against the calculated results" badge (set-up screenshots `setup_check_production_report.png`, `setup_check_production_traceability.png`).
- [ ] Copy of `retail_sales.csv` available to the participant.
- [ ] Printed or on-screen copies of `participant_instructions.md`, `observation_form.md`, `participant_feedback_form.md`.
- [ ] Production commit hash noted on the session day (deployment at preparation time corresponds to repository commit c4a1951 plus the working tree described in ../01_test_environment.md).
