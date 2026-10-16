# Part B insert: Expert Inspection and Instrumented Usability Measures

Ready to paste. This subsection replaces the participant-based subsection for now, because no participant sessions have been run; the participant materials remain in this folder as prepared, unfilled instruments. Do not present any of the content below as participant testing.

## Expert Inspection and Instrumented Usability Measures

Because voluntary participants were not available before submission, usability was evaluated with two inspection methods on the production deployment on 14 October 2026, using the restricted test account and the synthetic retail dataset; the planned participant study is prepared but not conducted. First, an AI-assisted cognitive walkthrough (Wharton et al., 1994) examined each action of the four tasks against the questions of whether a first-time business user would know what to do, notice the control, connect it to the goal, and see feedback. Second, a scripted pass measured the shortest path and time to feedback for each task, scanned six screens with axe-core, measured the position and size of key elements, and scored the generated executive summary for readability (Table 2, Figure 13). The shortest paths needed two to four actions, and feedback appeared within 0.8 s for upload validation, about 10 s for profiling, 3.6 s for the report and 1.0 s for the evidence panel. The five executive-summary sentences averaged 13 words with a Flesch Reading Ease of 74.6 (grade 4.7). The scan found no critical accessibility failures; the recurring issue was low-contrast secondary text (2.5:1 to 2.6:1 against the 4.5:1 requirement). The walkthrough identified one major problem, two unresolved template placeholders rendered literally in a chart explanation, and several minor ones: evidence links of 21 × 16 px with no affordance text, a missing-value warning placed below the fold, and a repeated sentence. These findings are predictions of difficulty and measurements of the interface, not observations of users; they cannot establish completion rates or satisfaction and are not representative of any user population. They do give concrete, reproducible defects to correct before a participant study is run.

(Word count: 268. If the brief limit of 130 to 180 words applies to this subsection, use the short version below.)

### Short version (176 words)

Because participants were unavailable before submission, usability was assessed by inspection on the production deployment on 14 October 2026, with the planned participant study prepared but not conducted. An AI-assisted cognitive walkthrough (Wharton et al., 1994) examined every action in the four tasks against whether a first-time business user would know what to do, notice the control, connect it to the goal and see feedback. A scripted pass then measured shortest paths, time to feedback, element visibility and size, axe-core accessibility results on six screens, and readability of the generated summary (Table 2, Figure 13). Shortest paths required two to four actions; feedback appeared in 0.8 s for upload validation, about 10 s for profiling, 3.6 s for the report and 1.0 s for the evidence panel. The summary's five sentences averaged 13 words (Flesch Reading Ease 74.6). One major defect was found, template placeholders rendered literally in a chart explanation, plus minor issues: 21 × 16 px evidence links, a below-the-fold warning, and low-contrast secondary text. These are predictions and measurements, not user observations, and establish neither completion rates nor satisfaction.

## Table 2

*Cognitive Walkthrough Findings and Instrumented Measures by Task*

| Task | Shortest path (actions) | Time to feedback | Predicted difficulty for a first-time business user | Problems found (severity 0-4) |
| --- | --- | --- | --- | --- |
| T1 CSV upload | 4 | 0.8 s to "File checked successfully, 240 rows and 7 columns" | Low | Sample-dataset link looks like plain text (2); disabled button gives no reason (1) |
| T2 Data profiling | 2 | about 10 s profiling job, progress screen within 1 s | Low for rows and columns; moderate for the warning | Warning box below the fold, signalled only by "See below" and a red 14.6% (2); low-contrast labels and counts (2) |
| T3 Report interpretation | 3 | 3.6 s to the executive summary | Low for KPI and chart direction; trust affected by text defects | Unresolved placeholders "{Sales Amount (THB)}", "{Region}" (3); repeated headline sentence (2); units card without unit, "Unit not defined" badge (1) |
| T4 Claim traceability | 2 | 1.0 s to the "How we know" panel | Moderate: link discovery | 21 × 16 px superscript links without affordance text (2 to 3); technical details expose internal identifiers (1) |

*Note.* Inspection performed on 14 October 2026 against https://dataspeaknow.com with a restricted test account and the synthetic file `retail_sales.csv`. Shortest path and time to feedback were measured by a Playwright script on a 1920 × 1080 viewport; predicted difficulty comes from an AI-assisted cognitive walkthrough; severity follows Nielsen's (1994) 0 to 4 scale. No participants were observed; values are not completion rates. Full detail in cognitive_walkthrough.md and instrumented_measurements.md.

## Table 3 (optional)

*Automated Accessibility Scan Results by Screen (axe-core 4.13)*

| Screen | Rules passed | Violations | Impact | Elements |
| --- | --- | --- | --- | --- |
| Login | 30 | Missing main landmark; content outside landmarks | Moderate | 8 |
| Dashboard | 37 | None | | 0 |
| New analysis | 41 | Colour contrast 2.51:1 | Serious | 4 |
| Profile | 44 | Colour contrast 2.51:1 and 2.63:1 | Serious | 10 |
| Report | 46 | Colour contrast 2.63:1 | Serious | 1 |
| Evidence panel | 48 | Dialog role on aside; colour contrast | Minor; serious | 2 |

*Note.* WCAG 2.1 AA requires 4.5:1 contrast for normal text. No critical-impact violations were found.

## Figure 13

*Cognitive Walkthrough Steps on the Production Application*

Image: B05_walkthrough_steps.png (composite of four unedited screenshots, 300 DPI).

*Note.* Panels show the production interface during the scripted walkthrough on 14 October 2026: (a) upload validated with 240 rows and 7 columns detected; (b) the profile page scrolled to the missing-value warning, which sits below the fold at a 1080 px viewport; (c) a report chart with its generated explanation, including the literal "{Sales Amount (THB)}" and "{Region}" placeholders in the second finding; (d) the "How we know" panel with the verification badge, the supporting calculation and the expanded technical details. The panels are evidence of the interface state inspected, not of participant behaviour.

## References used here (verify against the course reference list)

Nielsen, J. (1994). *Severity ratings for usability problems*. Nielsen Norman Group.

Wharton, C., Rieman, J., Lewis, C., & Polson, P. (1994). The cognitive walkthrough method: A practitioner's guide. In J. Nielsen & R. L. Mack (Eds.), *Usability inspection methods* (pp. 105–140). John Wiley & Sons.

LaunchNotes (2023) was requested for the participant subsection; it is not cited here because the participant study was not conducted and the reference entry was not supplied.
