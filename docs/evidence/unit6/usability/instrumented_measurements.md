# Instrumented Usability Measurements (machine-measured, no participants)

Data Speak, UoPeople MSIT 5910, Part B. Measured on **2026-10-14, 19:57 to 20:10 (+07)** against the production application at https://dataspeaknow.com with the restricted test account and the synthetic `retail_sales.csv`. Tool: Playwright 1.60 (Chromium, 1920 × 1080 viewport) driving the real pages; axe-core 4.13 injected from the frontend's own dependency; textstat for readability. Raw values: [instrumented_measurements.json](instrumented_measurements.json); table form: [instrumented_measurements.csv](instrumented_measurements.csv); step screenshots: [walkthrough/](walkthrough/).

**What these numbers are and are not.** They are objective properties of the interface and its responses: how many actions the shortest path needs, how long feedback takes to appear, whether key information is on screen without scrolling, how large a control is, which accessibility rules fail, and how readable the generated sentences are. They are *not* task-completion rates or satisfaction ratings; those require human participants and were not collected.

## 1. Shortest path and time to feedback

| Task | Minimum user actions on the shortest path | Time to feedback | What was timed |
| --- | --- | --- | --- |
| T1 CSV upload | 4 (New analysis; type name; choose file; Create and upload) | 0.81 s | Click to "File checked successfully, 240 rows and 7 columns detected" |
| T2 Data profiling | 2 (Continue to profile; scroll to Data quality) | about 10 s for the profiling job; progress screen after 1.0 s | VALIDATED to PROFILED by API polling at 5 s resolution (reference analysis, 19:37); progress screen with "10 to 60 seconds" message appears first |
| T3 Report interpretation | 3 (Analyses; click the completed row; scroll to a chart) | 3.62 s | Click on Analyses to the Executive summary heading |
| T4 Claim traceability | 2 (claim link; Technical details) | 1.03 s | Click to the "How we know" panel |

## 2. Visibility and target size

| Measure | Value | Reference |
| --- | --- | --- |
| Overview cards (Rows 240, Columns 7, Completeness 97.9%, Quality notes 1) | Visible at the top of the profile without scrolling | Screenshot 06 |
| Red "14.6% (35)" missing count in the discount row | Visible without scrolling at 1080 px | Screenshot 06 |
| Yellow "Data quality" warning box (`discount_pct: 14.6% of values are missing; statistics use the remaining rows.`) | Top edge at 1,187 px; **not visible without scrolling** at 1080 px | Screenshot 07 |
| Executive-summary claim link (the small numbered link that opens "How we know") | 21 × 16 px | WCAG 2.2 SC 2.5.8 minimum target size 24 × 24 px (AA) |
| "How we know" text links under each chart | Full-width text links, easier to notice than the superscript numbers | Screenshot 10 |

## 3. Accessibility scan (axe-core 4.13, WCAG 2.x rules)

| Page | Rules passed | Violations (rule, impact, elements) | Elements concerned |
| --- | --- | --- | --- |
| Login | 30 | landmark-one-main (moderate, 1); region (moderate, 7) | Page content not wrapped in a `<main>` landmark |
| Dashboard | 37 | none | |
| New analysis | 41 | color-contrast (serious, 4) | Inactive stepper labels "Profile, Define, Analyze, Report": 2.51:1 (#90a1b9 on #f8fafc), AA requires 4.5:1 |
| Profile | 44 | color-contrast (serious, 10) | Inactive stepper labels 2.51:1; grey "(0)" missing counts 2.63:1 |
| Report | 46 | color-contrast (serious, 1) | Footnote "Every statement above was checked against the calculated results" 2.63:1 |
| "How we know" panel | 48 | aria-allowed-role (minor, 1); color-contrast (serious, 1) | `<aside role="dialog">`; the same footnote |

Total: 6 distinct rule failures across 26 elements, all of one of three kinds (low-contrast secondary text, missing landmark on the login page, one ARIA role). No violations of the critical impact level. Keyboard operability, form labels, image alternatives and heading structure passed on every page scanned.

## 4. Readability of the generated executive summary (production, Claude narrative)

| Sentence | Words | Flesch Reading Ease | FK grade |
| --- | --- | --- | --- |
| Average Units Sold was +75.5% higher for North than for the lowest Region group. | 14 | 78.9 | 4.0 |
| Average Sales Amount (THB) was +73.8% higher for North than for the lowest Region group. | 15 | 80.8 | 3.9 |
| Sales Amount (THB) and Units Sold tended to move together (rho=+0.71). | 11 | 72.6 | 5.9 |
| Over the whole period, Sales Amount (THB) ended +26.9% higher than it started. | 13 | 70.1 | 5.1 |
| Over the whole period, Units Sold ended +41.5% higher than it started. | 12 | 66.8 | 5.4 |
| **All five together** | 65 | **74.6** | **4.7** |

A Flesch Reading Ease of 74.6 is in the "fairly easy" band and a grade level under 5 is well within the reach of a general business reader. Formula scores do not capture whether "rho=+0.71" is understood; that is a vocabulary question only participants can answer.

## 5. Content defects found by the instrumented pass

| Observation | Where | Evidence |
| --- | --- | --- |
| Two unresolved placeholders rendered literally: "The difference in {Sales Amount (THB)} between {Region} groups is statistically meaningful" | Report, Finding #2, "What this chart shows" | Screenshot 10; `unresolved_placeholders` in the JSON |
| The chart headline sentence is repeated verbatim inside the second explanatory sentence | Findings #1 and #2 | Screenshot 10 |
| Chart #1 carries a "Unit not defined" badge because the suggested definition for units had no unit | Report | Screenshot 09 |

## 6. Limitations

- One run, one dataset, one viewport, one browser; timings include Internet latency from Bangkok to the EC2 region and will vary.
- Minimum action counts are the shortest path a knowledgeable user takes; they are a lower bound, not what a new user would do.
- axe-core detects a subset of accessibility problems automatically; it cannot judge meaning, reading order or ease of understanding.
- Readability formulas measure sentence and word length only.
