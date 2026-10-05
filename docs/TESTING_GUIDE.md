# Data Speak Testing Guide

How to verify the system at every level, from a single service's unit tests to a full analysis with
the real AI model. Each section says what to run, how long it takes, and what a pass looks like.

Levels, cheapest first:

| Level | What it proves | Needs | Time |
| --- | --- | --- | --- |
| 1. Automated suites | Each service's logic, with every other service faked | PHP, Python, Node (no Docker) | about 1 minute |
| 2. Live stack smoke | All six containers cooperate end to end | Docker stack running, Ollama optional | about 1 minute, plus model time |
| 3. Manual API walkthrough | You see every response yourself | same | 10 minutes |
| 4. AI evaluation | How well Qwen behaves under the validation rules | Ollama with the model | 2 to 5 minutes |
| 5. Privacy and failure drills | The rules that matter most hold under bad conditions | same | 10 minutes |

The frontend is wired to the API, so levels 2 and 3 can also be done in the browser at
http://localhost:5173 (see "Testing through the UI" below).

---

## Level 1: automated suites

Run from the repository root. No containers are needed; the backend tests use in-memory SQLite and
fake both FastAPI and Ollama with recorded responses.

```bash
# Backend: 85 tests
cd backend && php artisan test && vendor/bin/pint --test && cd ..

# Analytics: 41 tests
cd analytics && source .venv/bin/activate && pytest -q && ruff check . && ruff format --check . && deactivate && cd ..

# Frontend: lint, format, typecheck, 16 tests (MSW fakes the API), build
cd frontend && npm run lint && npm run format:check && npm run typecheck && npm test && npm run build && cd ..
```

Pass looks like `"result":"passed","tests":85`, `41 passed`, and `✓ built`. Three ESLint warnings in
prototype files are expected.

What the suites already cover, so you do not have to repeat it by hand:

- Backend: every endpoint's 200, 401, 404, 409 and 422 paths; the lifecycle state machine; transient
  file cleanup on success, dataset failure, delete and TTL expiry; the run pipeline with FastAPI faked;
  the AI pipeline with Ollama faked (schema retry, outage fallback, all-rejected, disabled, prompt
  injection); every claim validation rule; and sentinel tests that push a fake cell value through the
  pipeline and assert it never reaches logs, the database, or the model request.
- Analytics: CSV edge cases (BOM, delimiters, header only, duplicates, ragged rows, Thai and Myanmar
  headers), type inference, two golden datasets with independently computed expectations,
  determinism across runs, insight rules and causal-word guard, chart units, temp-dir cleanup on
  success and failure, payload limit, log leak, and the frozen OpenAPI contract.
- Frontend: the API client's error normalisation and 401 handling, status-to-label coverage for every
  dictionary value, polling that stops on terminal states and on unmount, an integration test that
  walks login, upload, profile, define, analyze, report and traceability against an in-memory fake
  of the Laravel contract, a privacy test (no FastAPI URL, no CSV text in storage or console, token
  only in sessionStorage), and axe accessibility checks on the login page and dashboard.

Useful single-file runs while changing code:

```bash
cd backend && php artisan test --filter=GenerateReportJobTest
cd analytics && pytest tests/unit/test_engine_golden.py -q
```

## Level 2: live stack smoke

Start everything, then run the smoke script. It registers a user, proves that analysis routes answer
403 `EMAIL_NOT_VERIFIED` before confirmation, fetches the confirmation link from Mailpit and follows it
(expects the redirect with `verified=1`), creates an analysis, uploads the
synthetic golden CSV, validates, profiles, saves definitions, queues the run, polls until ANALYZED,
reads insights and charts, queues the report, polls until COMPLETED, reads the report and one claim's
traceability, then deletes the analysis.

```bash
docker compose up -d
python3 tests/e2e/smoke_workflow.py            # delete at the end
python3 tests/e2e/smoke_workflow.py --keep     # keep the analysis for level 4
```

Pass looks like a column of `ok` lines ending in `E2E SMOKE PASSED`. Two lines deserve a look:

```
ai status=ready model=qwen3:8b prompt=exec-interp-v2 claims_total=11 supported=7
[FINDING] ...rendered sentence...
```

`ai status=ready` means Qwen answered and at least one claim passed validation. `unavailable` means
Ollama was not reachable; the report still completes with deterministic content, which is the
designed fallback, but check `curl http://127.0.0.1:11434/api/tags` if you expected a narrative.

If the run step never leaves ANALYZING, the queue worker is not running:
`docker compose ps backend-worker` and `docker compose logs backend-worker`.

## Testing through the UI

Open http://localhost:5173 and log in (an account `zywint@gmail.com` / `123456` exists on this
machine's development database; the register form enforces 8-character passwords for new users).
Walk the stepper with the sample dataset and watch for:

| Step | What to check in the browser |
| --- | --- |
| Register | After **Create account** you land on **Confirm your email**. Open http://127.0.0.1:8025, click **Confirm my email** in the message; the browser returns to login with a green banner. **Send the email again** delivers a second message and the fourth click within a minute shows the wait message. **I have confirmed my email** before clicking the link says "Not confirmed yet"; after it, the Analyses page opens. |
| Login | Wrong password shows an inline message, not a crash. Refreshing the page keeps you logged in; closing the tab logs you out. An unverified account is sent to the confirmation screen instead of the dashboard. |
| Chart review | After **Analyze and recommend charts** the progress bar stops at "Choosing charts" and a panel lists the recommended charts with previews. Untick one, pick one from **Chart to add** (only charts the results support are listed) and check that **Preview with your data** draws it before you press **Add chart**; the added chart then appears as a card in the grid with its data and a remove control. Press **Write the executive summary**: the report shows exactly the kept charts, the removed one is absent from the summary and from the PDF, and the added one has a reading under it. From the completed analysis, **Review charts** reopens the panel. |
| Languages | Switch to ไทย in the sidebar: every page, status badge and step label changes at once and the choice survives a refresh. Run an analysis while Thai is active: the summary, chart titles, chart readings, key-figure labels and the PDF (cover, headings, footer) are Thai, category values and column names are translated, and the Report page shows no language banner. Switch the interface language while on a report: chart titles, readings and key-figure labels change at once, a short notice says the summary is being translated, and within about thirty seconds the same statements appear in the new language with the same numbers. The report is not regenerated (no progress bar), and the PDF downloaded afterwards is in the interface language. Chinese reports load the CJK font (the first PDF takes longer). |
| English only | With a dataset whose category values are Thai or Myanmar (e.g. region names), the report, every chart axis and series label, the "What this chart shows" lines and the PDF show English only; the traceability panel's technical details still show the original evidence values. Typing a Thai business name on Define is refused with "Business names must be in English". |
| Export PDF | In the report header, **Export PDF** downloads `DataSpeak-<name>-<timestamp>.pdf`: cover with logo, analysis name, created and generated timestamps and your name; the logo letterhead on every page; the footer reads "Generated <timestamp>" left and "Page N of M" right; every chart is drawn and followed by "What this chart shows"; Thai and Myanmar names are legible. |
| Dashboard | Rows are links and the inline Continue, Report and Delete buttons work by keyboard. Delete asks for confirmation. Statuses read as words, never `ANALYZING` style codes. |
| New analysis | Choosing a file fills the name. A `.xlsx` or empty file is refused before upload. The privacy notice is visible. After upload the validation summary shows rows and columns and any warnings. |
| Profile | A progress bar shows "Profiling the data" then "Defining fields" with a percentage (10-60 s); refreshing the page keeps following the same job. Thai and Myanmar column headers display correctly, each with a suggested English name and meaning. Category columns show counts, never the category values. Quality notes list columns with missing values. Stop the analytics container before this step to see "Profiling did not complete" with a **Try again** button. |
| Define | Changing a column to Currency without a unit blocks saving with a message next to the field. Save moves to Analyze. | With the golden dataset (headers `ยอดขาย_thb`, `ရောင်းအား_units`) the Profile page already shows an English name and meaning per column ("suggested by the AI model"), the Define inputs are prefilled with them marked "Suggested, check it", and a name you type is kept when you press **Suggest again**. Long category values in bar charts are shortened and angled; hovering shows the full value.
| Analyze | One button, "Analyze and build the report", runs everything. Progress advances through six stages (statistics, insights, charts, narrative, verification, assembly) with a live region for screen readers. On completion a green "All done. Your report is ready." screen appears and the report opens by itself after three seconds, or at once with the button. A failure shows a safe message and a retry. |
| Report | Opens automatically after Analyze (the Generate button only appears for analyses that stopped at the analysed state, for example with AUTO_REPORT=false). The report shows metric cards with units from your definitions, one executive summary paragraph across all charts (small numbers open the figures behind each sentence), then every chart with its own "What this chart shows" reading (checked narrative, or a calculated sentence marked "calculated" when the model wrote nothing for that chart), other findings, and data notes in plain language. The PDF button is disabled with a coming-soon label. Rewrite summary repeats the AI step. |
| Traceability | How we know opens a side panel with the statement, a Checked badge, and the numbers behind it in business wording (what was measured, for what, the result, how it was calculated). Technical identifiers, the model name and the placeholder template sit under a collapsed Technical details section. Escape closes it. |

Browser developer tools: the Network tab should show calls to port 8000 only, never 8001. The
Application tab should show one `dataspeak.session` entry in sessionStorage and nothing in
localStorage.

## Level 3: manual API walkthrough

Section 6 of [GETTING_STARTED.md](GETTING_STARTED.md) has the full curl sequence. Things worth
checking with your own eyes at each step:

| Step | Look for |
| --- | --- |
| Register or login | A `token` and a user with `role: USER` and `email_verified: false` right after registration. A wrong password gives 401 `INVALID_CREDENTIALS`. |
| Confirm email | Before clicking the link, `POST /analyses` gives 403 `EMAIL_NOT_VERIFIED`. The link (Mailpit UI or API) answers 302 to `…/login?verified=1`; a second click gives `verified=already`; a changed character in the signature gives `verified=invalid`. `POST /auth/email/resend` gives 202 `sent`, or 200 `already_verified` afterwards. |
| Export PDF | `GET /analyses/{id}/report/pdf` with the bearer token: `Content-Type: application/pdf`, a `Content-Disposition` filename, body starting `%PDF`. Before COMPLETED it gives 409 `STEP_NOT_READY`; with another user's token 404. |
| Create analysis | `status: CREATED`, `current_stage: UPLOAD`, a UUID id. |
| Upload | 201, `dataset.sha256` present, `transient_file_present: true`. An `.xlsx` is accepted and answers with `meta.source` naming the worksheet used; try a `.xls`, a `.json` and an empty file: all 422. |
| Validate | `valid: true`, `row_count: 240` for the golden file. |
| Profile | 202 with a `job_id`; `GET /status` shows `job.kind: profile`, `job.stage` moving from PROFILING to DEFINITION and `progress` rising; then `status: PROFILED`. `GET /profile`: columns include `ยอดขาย_thb` and `ရောင်းအား_units` with inferred types and a suggested `definition` (`is_suggested: true`); category stats show counts only, never labels. With the analytics service stopped, status reports `job.status: FAILED` and `job.error.code: ANALYTICS_UNAVAILABLE` while the analysis stays VALIDATED. |
| Definitions | `meta.status: DEFINED`. Send `semantic_type: "Dimension"` to see a 422 with the field named. Omit `unit` for a CURRENCY column to see the specific error. |
| Run | 202 with a `job_id`. Send the same request again with the same `Idempotency-Key` header: identical body, header `Idempotent-Replayed: true`, and only one job. |
| Status | Poll every 2 seconds. `progress` moves 10, 55, 80, 100 and `is_terminal` flips at the end. |
| Insights | Each item has `evidence_id` and an `evidence` object with `method` and `source_columns`. Headlines contain no causal words. |
| Visualizations | Revenue charts carry `unit: THB` from your definition; charts for columns without a unit show `unit_missing: true`. |
| Report | 202, then poll status through REPORTING stages AI_SYNTHESIS, CLAIM_VALIDATION, REPORTING to COMPLETED. The report shows `ai.status`, `claims_total` against `claims_supported`, rendered `statement` next to `statement_template` with placeholders. |
| Claim | `validation.status`, `validation.reasons`, the evidence list, and `generation.model`. |
| Delete | 204, then 404 on every route for that id. |

Out-of-order calls are a good check of the state machine: run before definitions gives 409
`STEP_NOT_READY`, upload during a run gives 409 `ANALYSIS_BUSY`, reading the report before it
exists gives 409 `STEP_NOT_READY`.

Authorization: register a second user and call any route with the first user's analysis id. Every
response must be 404, never 403 and never data.

## Level 4: AI evaluation

This measures the model rather than the code. Keep an analysed dataset with `--keep`, then run the
harness several times. Nothing is persisted.

```bash
python3 tests/e2e/smoke_workflow.py --keep        # prints the analysis id at the end
docker compose exec backend php artisan dataspeak:ai-eval <analysis-id> --runs=5 --json
```

Read the output like this:

- `schema_valid_rate` should be 1.0. Anything lower means the model is not honouring the JSON schema.
- `claim_acceptance_rate` is the share of claims that reach the report. On this laptop with
  `qwen3:8b` and prompt v2 it was 0.64 over three runs.
- `rejection_reasons_by_rule` tells you why claims were held back. `DIGITS_OUTSIDE_PLACEHOLDER` means
  the model typed numbers; `CAUSAL_LANGUAGE` and `LOW_QUALITY_EVIDENCE_AS_FINDING` are review flags.
- `avg_latency_ms` is inference time per generation, around 25 to 35 seconds here.

Recorded baselines for v1 and v2 are in [../tests/ai-eval/README.md](../tests/ai-eval/README.md).
To compare a prompt change, edit `backend/app/Services/Ai/Prompts/ExecutiveInterpretationPrompt.php`,
set a new `AI_PROMPT_VERSION`, restart the worker, and run the harness again.

The live test group runs one real generation on a fixed fixture and fails if nothing validates:

```bash
cd backend && php artisan test --group=ollama
```

## Level 5: privacy and failure drills

These check the four core rules on the running system.

**Raw data never persists.** Run a full analysis, then search for a value you know is in the CSV
(the golden file has customer ids like `C01000`):

```bash
docker compose exec postgres psql -U dataspeak -d dataspeak -c "
SELECT 'evidence', count(*) FROM evidence WHERE payload::text LIKE '%C01000%'
UNION ALL SELECT 'columns', count(*) FROM dataset_columns WHERE profile_stats::text LIKE '%C01000%'
UNION ALL SELECT 'claims', count(*) FROM claims WHERE statement LIKE '%C01000%'
UNION ALL SELECT 'ai_generations', count(*) FROM ai_generations WHERE request_payload::text LIKE '%C01000%';"
docker compose logs backend backend-worker analytics | grep -c C01000     # expect 0
ls backend/storage/app/tmp/analyses                                       # empty after a completed run
```

**The model never receives rows.** The generation record shows what was sent:

```bash
docker compose exec postgres psql -U dataspeak -d dataspeak -c "SELECT request_payload FROM ai_generations ORDER BY id DESC LIMIT 1;"
```

You should see evidence keys, insight ranks, omissions and metadata, nothing resembling a row.

**No number in the report comes from the model.** Supported claims must contain no digit outside a
placeholder:

```bash
docker compose exec postgres psql -U dataspeak -d dataspeak -c "
SELECT count(*) FROM claims WHERE status='SUPPORTED'
AND regexp_replace(statement, '\{ev_[a-f0-9]{12}\.[a-z_]+\}', '', 'g') ~ '[0-9]';"   # expect 0
```

**Ollama outage.** Stop Ollama (`pkill ollama` on macOS, or stop the app), post a report, and poll.
The analysis must reach COMPLETED with `ai.status: unavailable`, zero claims, and the deterministic
summary metrics, insights and charts still present. Start Ollama again and post the report once more:
`ai.status` becomes `ready` and the previous attempt stays in `ai_generations` as FAILED with
`AI_UNAVAILABLE`.

**Analytics outage.** `docker compose stop analytics`, then post `/validate`: 503
`ANALYTICS_UNAVAILABLE` and the analysis stays UPLOADED. Post `/run` on a defined analysis: it ends
FAILED with `last_error.code: ANALYTICS_UNAVAILABLE` and the transient file is kept so the run can be
retried after `docker compose start analytics`.

**Expiry.** Upload a CSV and do nothing. Force the purge with
`docker compose exec backend php artisan dataspeak:purge-uploads --ttl=0`. The file disappears and
the analysis shows `purged_at`; posting `/validate` now gives 409 `UPLOAD_REQUIRED`.

**Rate limits.** Post a wrong password six times quickly: the sixth answer is 429 `RATE_LIMITED`.

### Drill: a job dies mid-report

Start a run, and while the Analyze page shows "Writing the narrative" run `docker compose kill backend-worker`
then `docker compose up -d backend-worker`. Expected: the page keeps polling; within the job deadline (or
immediately after `php artisan dataspeak:reap-stale-jobs --force`) it shows "This step stopped before it
finished" with reference `JOB_INTERRUPTED` and a **Build the report again** button. Clicking it reaches
COMPLETED without a new upload. `status` for that analysis reports `is_terminal: true` and the stage history
shows the interrupted AI_SYNTHESIS attempt as FAILED followed by a new attempt.

## When something fails

| Symptom | First thing to check |
| --- | --- |
| Backend suite fails after a schema edit | Did you regenerate the dictionary and write the migration? `php artisan migrate:fresh` on Postgres, then rerun. |
| Analytics contract test fails | `python -m scripts.export_openapi`, review the diff of `docs/contracts/fastapi-openapi.json`. |
| Smoke stops at run | Worker not running or stale: `docker compose restart backend-worker`. |
| Smoke stops at report with `ai.status: unavailable` | Ollama not reachable from the container: `docker compose exec backend php -r 'echo file_get_contents(getenv("OLLAMA_BASE_URL")."/api/version");'` |
| Any container exits on start after dependency changes | Stale anonymous volume: `docker compose up -d --force-recreate -V <service>`. |
| Docker commands hang while `docker ps` works | Docker Desktop engine wedge; quit and relaunch Docker Desktop. |
