# Researcher Runbook: Running One Usability Session

Print this. One copy per participant. Say the text in quotation marks exactly as written. Write everything the participant says and does on the observation form as it happens. Do not help unless rule 9 applies.

## A. Ten minutes before the participant arrives

1. On your laptop open Chrome. Go to https://dataspeaknow.com and sign in with the test account (you know the password; never show or say it).
2. Open the Analyses page. Check that "Unit 6 usability reference" is listed with a Report / completed status. If it is not, stop and contact me before running the session.
3. Put a copy of `retail_sales.csv` on the Desktop. (Source: `analytics/tests/golden/retail_sales.csv` in the repository.)
4. Open a fresh observation form. Write the participant ID (P01 for the first person, then P02, P03, P04, P05), today's date, "production", the browser name, and whether the laptop is yours.
5. Open the feedback form and write the same ID at the top.
6. Keep this runbook, a pen, and a watch or phone timer within reach.

## B. Welcome and consent (3 minutes)

7. Say:
   "Thank you for helping. Data Speak is a student project that turns a spreadsheet into a short business report. I am testing the software, not you, so anything that is confusing is useful for me. Taking part is voluntary; you can stop at any time or skip any task. I only write down an anonymous ID, whether you work in IT, what happens during the tasks, your comments and your ratings. No name, no email, no screenshots or recordings of you. The file you will upload is made-up sales data, not real customers. You will use a test account that is already signed in, so you need no password. Do you agree to continue?"
8. If yes, tick "Consent confirmed". Ask: "Do you work in software development or IT?" Tick technical or nontechnical. Optionally tick the role type.
9. Say:
   "There are four short tasks. Please try each one on your own and think out loud: say what you are looking for and what you expect to happen. If you are really stuck you can ask me, and I will note that I helped."

## C. Task 1: upload (expected 2 to 5 minutes)

10. Make sure the browser shows the Analyses page. Start the timer when you finish reading. Say:
    "Task one. Create a new analysis, give it any name, and upload the file retail_sales.csv from the Desktop. When you are done, tell me whether the file passed the check and what the screen tells you about it."
11. Watch silently. Write where they look, click, hesitate.
12. The task ends when they tell you the outcome. Stop the timer. Write their answer word for word.
13. Mark **independent** if, without any help, they uploaded the file and said it passed (the screen shows "File checked successfully, 240 rows and 7 columns detected"). Mark **assisted** if you helped in any way, and write the help. Mark **failed** if they gave up or gave a wrong answer.

## D. Task 2: profile (expected 3 to 6 minutes)

14. Start the timer. Say:
    "Task two. Continue to the profile of the file you just uploaded. Tell me how many rows and how many columns it has. Then find any warning about missing values and tell me, in your own words, what that warning means."
15. Write the rows and columns they say (correct: 240 and 7), which warning they point to (correct: discount_pct, 14.6% missing), and their explanation word for word.
16. Independent if all three are right with no help (an explanation like "some discount values are empty, so the statistics use the rest" is right). Assisted if you had to hint, for example "scroll down" or "look at the yellow box". Failed if they cannot find the warning or explain it incorrectly.

## E. Task 3: report (expected 3 to 6 minutes)

17. Start the timer. Say:
    "Task three. Go back to the Analyses list and open the analysis called 'Unit 6 usability reference'. It is already finished. In the report, tell me one number at the top that a manager would care about, and why. Then pick one chart and explain in your own words what it shows."
18. Write the KPI they name and their reason, the chart they pick, and their explanation word for word. Look at the chart yourself and tick whether their explanation is right, partly right or wrong (for example "North is highest" for the regional bar chart, or "it went up over the year" for the line).
19. Independent if a KPI is named and the chart explanation is right without help. Assisted if you had to point them to the Analyses list or to the charts. Failed if the explanation contradicts the chart or they give up.

## F. Task 4: traceability (expected 2 to 5 minutes)

20. Start the timer. Say:
    "Task four. In the executive summary at the top of the report, the sentences have a small link or number next to them. Pick one sentence and open 'How we know'. Tell me what calculation or number supports that sentence, and in your own words what that evidence means."
21. Write whether they found the link without help, whether the panel opened, what they identified as the supporting number, and their explanation word for word.
22. Independent if they open the panel and give a basically correct explanation (for example "sales and units go up and down together, 0.71 is how strongly"). Assisted if you had to show them the link. Failed if they cannot find it or misread the evidence.

## G. Feedback form (5 minutes)

23. Say:
    "Last part. Please fill in this short form on your own: five statements to rate from one to five, and one line for anything you would change. There are no wrong answers, and it is anonymous."
24. Step away while they fill it in. Do not comment on their ratings.

## H. Close (1 minute)

25. Say: "That is everything. Thank you very much, this is really helpful." Answer any questions they have about the project only after the forms are complete.
26. Immediately write anything else you noticed in "Session notes" (slow pages, errors, anything you did that might have influenced them).
27. In the browser, go back to the Analyses list so the next participant starts from the same place. Do not delete the analysis the participant created.

## I. Entering the data (same day, 10 minutes)

28. Open `docs/evidence/unit6/usability/usability_results.csv` in a text editor or spreadsheet. Add four lines for this participant, one per task, in this exact format:

    participant_id,participant_category,task_id,completion_status,duration_seconds,difficulty,comment

    Example (replace with what you observed):

    P01,nontechnical,T1,independent,95,Looked around the page before finding the dashed upload box,"Oh it says 240 rows, so it worked"
    P01,nontechnical,T2,assisted,140,Did not notice the yellow box until I said scroll down,"I thought 14.6 percent was the discount itself"
    P01,nontechnical,T3,independent,180,Picked total sales; read the region bars correctly,"North sells the most, the others are about the same"
    P01,nontechnical,T4,failed,200,Clicked the chart instead of the small link; gave up,"I would never find that number on my own"

    Rules: status is exactly `independent`, `assisted` or `failed`; category is exactly `technical` or `nontechnical`; leave duration empty if you did not time it; put a comment that contains a comma inside double quotes; copy words verbatim.

29. Open `usability_feedback.csv` and add one line for this participant:

    participant_id,ease_of_use,validation_clarity,chart_clarity,summary_clarity,evidence_confidence,suggestions

    Example:

    P01,4,5,4,3,2,"Make the How we know link bigger and explain what 0.71 means"

30. Keep the paper forms. They are the primary record.

## J. After the last participant

31. In Terminal:

    cd /Users/zeyarwint/Documents/MSIT/dataspeak/docs/evidence/unit6/usability
    python3 ../scripts/analyze_usability.py

    It prints how many attempts and the independent rate, and writes `usability_summary.md`, `B05_task_completion_chart.png` and `usability_numbers.json`. If it prints an ERROR naming a line, fix the typo in that CSV line and run it again.
32. Message me: "usability data entered", and paste the LaunchNotes (2023) reference from your reading list. I will write the themes, the interpretation, the Figure 13 caption and the 130 to 180 word subsection.

## Rules that protect the study

- Do not change, redeploy or "fix" the application between the first and the last session.
- A task counts as independent only if the participant did it and answered correctly with no help. The software working is not the criterion.
- Write failures and negative comments exactly as they happened. They are valid results.
- Never record names, emails or anything identifying. Never show the password.
- If something breaks (site down, error page), write it in Session notes, mark the task as failed with the reason, and continue if possible.
