# AI-Assisted Cognitive Walkthrough of Data Speak (expert inspection, no participants)

Data Speak, UoPeople MSIT 5910, Part B. Performed on **2026-10-14** against the production application (https://dataspeaknow.com) with the restricted test account and the synthetic `retail_sales.csv`. **Evaluator: Claude (an AI assistant) reading real screenshots of each step while a Playwright script executed the tasks; findings were checked against the machine measurements in [instrumented_measurements.md](instrumented_measurements.md).** This is an inspection method. It predicts where a first-time business user is likely to have trouble; it does not observe real users and must not be reported as participant testing.

## Method

A cognitive walkthrough (Wharton et al., 1994) takes a task, breaks it into the actions a user must perform, and asks four questions at each action:

- **Q1** Will the user try to achieve the right effect?
- **Q2** Will the user notice that the correct action is available?
- **Q3** Will the user associate the correct action with the effect they want?
- **Q4** If the correct action is performed, will the user see that progress is being made?

Answers are **Y** (likely), **?** (uncertain, a probable difficulty) or **N** (unlikely). The user model is the study's target participant: a business or office user who works with spreadsheets, has never used Data Speak, and has no software background. Each problem receives a severity on Nielsen's (1994) 0 to 4 scale (0 not a problem, 1 cosmetic, 2 minor, 3 major, 4 catastrophic). Screenshot numbers refer to [walkthrough/](walkthrough/).

## Task T1: upload a CSV (screenshots 02, 03, 04, 05)

| Step | Action | Q1 | Q2 | Q3 | Q4 | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Click "New analysis" (sidebar or green button on the dashboard) | Y | Y | Y | Y | Two obvious entry points; the page title and the five-step stepper confirm the start of a flow |
| 2 | Type a name in "Analysis name" | Y | Y | Y | Y | Placeholder "September Sales Analysis" shows the expected kind of entry |
| 3 | Choose the file via the dashed dropzone | Y | Y | Y | Y | Label "Drop your CSV or Excel file here or click to choose" with accepted formats and size; the chosen file name and size appear beneath (04) |
| 4 | Click "Create and upload" | Y | Y | ? | Y | Button is pale (disabled) until a file is chosen, which is correct, but a user who clicks it first gets no explanation of why it is inactive |
| 5 | Read the validation outcome | Y | Y | Y | Y | Green tick, "File checked successfully", "240 rows and 7 columns detected", then a single "Continue to profile" button (05). Feedback appeared in 0.8 s |

Predicted outcome: independent completion likely. One probable question: "Use the sample retail dataset" is plain text, so a user may not realise it is clickable (severity 2).

## Task T2: read the profile (screenshots 06, 07)

| Step | Action | Q1 | Q2 | Q3 | Q4 | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Click "Continue to profile" | Y | Y | Y | Y | A progress card ("Checking the file", "Profiling the data", "Defining fields") with the note that it takes 10 to 60 seconds; the job took about 10 s |
| 2 | Read rows and columns | Y | Y | Y | Y | Four large cards at the top: Rows 240, Columns 7, Completeness 97.9%, Quality notes 1 "See below" |
| 3 | Find a missing-value warning | Y | ? | ? | Y | Two cues are on screen: the "Quality notes 1, See below" card and a red "14.6% (35)" in the discount row's Missing column. The explanatory yellow box is 1,187 px down, below the fold at 1080 px; a user must scroll or infer from "See below" |
| 4 | Explain what the warning means | Y | Y | Y | Y | The box text is explicit: "discount_pct: 14.6% of values are missing; statistics use the remaining rows." A user who reaches it has the explanation in plain words |

Predicted outcome: rows and columns independent; the warning may need a scroll prompt (assisted) for users who do not read "See below" (severity 2). The column table also shows the suggestion reasons in full sentences, which supports explanation.

## Task T3: read the finished report (screenshots 08, 09, 10)

| Step | Action | Q1 | Q2 | Q3 | Q4 | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Go to "Analyses" and open "Unit 6 usability reference" | Y | Y | Y | Y | List shows name, status "Complete", rows, date and a "Report" action; clicking the row opens the report (3.6 s) |
| 2 | Name a KPI | Y | Y | Y | Y | Four cards: Dataset records 240, Sales Amount (THB) total 92,032 THB, Units Sold total 999, Sales Amount (THB) average 383.5 THB. The units card has no unit word (just "999") |
| 3 | Explain a chart | Y | Y | ? | Y | Bar charts have clear titles and axis labels; each is followed by "What this chart shows" sentences. Finding #2's second sentence contains literal braces: "The difference in {Sales Amount (THB)} between {Region} groups…"; Findings #1 and #2 repeat the headline sentence inside the second sentence; chart #1 carries a "Unit not defined" badge |

Predicted outcome: KPI and chart direction independent for most users (the bar heights and titles are unambiguous). The placeholder defect is likely to be noticed and to reduce trust in the generated text (severity 3: it is a visible correctness error in a product whose promise is verified statements). Repetition is severity 2.

## Task T4: trace a claim (screenshots 11, 12)

| Step | Action | Q1 | Q2 | Q3 | Q4 | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Find a claim link in the executive summary | Y | ? | ? | – | The links are small superscript numbers (21 × 16 px) after each sentence; nothing says "click for evidence". The "How we know" text links under each chart are far more discoverable, and a user may use those instead |
| 2 | Open "How we know" | Y | Y | Y | Y | Panel slides in within 1.0 s, titled "How we know", with the sentence, a green "Checked against the calculated results" badge, and a card "Gap between highest and lowest group, +75.5%, Units Sold by Region, Compared the highest and lowest group averages" |
| 3 | Identify the supporting calculation | Y | Y | Y | Y | The card states the metric in plain words and shows the value |
| 4 | Explain what the evidence means | Y | Y | ? | Y | The plain-language card supports a basic explanation. "Technical details" (collapsed by default) exposes the raw template with `{ev_…}` placeholders, "rule_based_v1" and "prompt version exec-interp-v7", which would confuse a business reader if opened |

Predicted outcome: assisted completion plausible for users who do not notice the superscript links; independent for those who use the chart-level "How we know" links. Explanation at a basic level is well supported.

## Findings

| ID | Finding | Where | Severity (0-4) | Evidence |
| --- | --- | --- | --- | --- |
| W1 | Unresolved template placeholders shown to the reader ("{Sales Amount (THB)}", "{Region}") | Report, Finding #2 explanation | 3 major | Screenshot 10; `unresolved_placeholders` measurement |
| W2 | Claim links in the executive summary are 21 × 16 px superscripts with no affordance text | Report | 2 minor (3 for T4 specifically) | Screenshot 09; target-size measurement; WCAG 2.2 SC 2.5.8 |
| W3 | Missing-value warning box is below the fold; only "See below" and a red percentage signal it | Profile | 2 minor | Screenshot 06/07; warning top at 1,187 px |
| W4 | Headline sentence repeated inside the second "What this chart shows" sentence | Report, Findings #1 and #2 | 2 minor | Screenshot 10 |
| W5 | Low-contrast secondary text: inactive stepper labels 2.51:1, "(0)" counts and the verification footnote 2.63:1 (AA needs 4.5:1) | New analysis, Profile, Report, panel | 2 minor | axe color-contrast, 16 elements |
| W6 | "Use the sample retail dataset" looks like a label, not a button | New analysis | 2 minor | Screenshot 03 |
| W7 | Technical details expose internal identifiers and prompt versions in a business-facing panel (collapsed by default) | How we know panel | 1 cosmetic | Screenshot 12 |
| W8 | Units Sold KPI card shows "999" without a unit; chart badge "Unit not defined" | Report | 1 cosmetic | Screenshot 09 |
| W9 | Login page content not inside a main landmark; dialog role on `<aside>` | Login, panel | 1 cosmetic | axe landmark-one-main, region, aria-allowed-role |
| W10 | Disabled "Create and upload" button gives no reason while no file is chosen | New analysis | 1 cosmetic | Screenshot 03 |

**Strengths observed.** Immediate, specific validation feedback (row and column counts in under a second); a persistent five-step stepper; a privacy notice beside the upload; a warning that states its consequence ("statistics use the remaining rows"); short executive-summary sentences (mean 13 words, Flesch Reading Ease 74.6); a visible verification badge on every traced claim; and a two-click path from any report sentence to its evidence.

## How this relates to the planned participant study

The walkthrough predicts: T1 and T3 are likely to be completed independently; T2 may need a scroll hint for the warning; T4 depends on noticing a small link. These are predictions to be tested, not results. If participant sessions are run later, their observed completion statuses should be compared with this table.

## References (verify against the course reference list before use)

Nielsen, J. (1994). *Severity ratings for usability problems*. Nielsen Norman Group.

Wharton, C., Rieman, J., Lewis, C., & Polson, P. (1994). The cognitive walkthrough method: A practitioner's guide. In J. Nielsen & R. L. Mack (Eds.), *Usability inspection methods* (pp. 105–140). John Wiley & Sons.
