# DEF-09 Root Cause Analysis: narrative generation times out while the local model serves a translation

Data Speak, Unit 7 final verification, 2026-10-16. Defect first recorded during the post-fix baseline run of 2026-10-16 10:36–10:41 (`../retesting/defect-retesting-results.md`, section 2b). Severity Medium on the development stack; not reproducible with the production provider (Claude API) because that provider serves concurrent requests.

## 1. Symptom

`GenerateReportJob` requested the executive narrative for the workflow analysis while `backend-worker-translations` was running a `TranslateReportJob` for an earlier report. The narrative HTTP request to Ollama exceeded `OLLAMA_TIMEOUT` (300 s). `ai_generations` recorded `FAILED / AI_TIMEOUT`; the report was assembled deterministically (READY, 0 claims, data notes shown). Three harness checks that depend on narrative claims (SYS-09, SYS-08, INT-08) failed in that attempt and passed when re-run with an idle model. No fabricated content was produced at any point.

## 2. Investigation

| Step | Method | Finding |
| --- | --- | --- |
| 2.1 Timeline | `ai_generations` row times against the translations worker log | The narrative generation started while a translation job was in flight; the translation job took 4 min 34 s and finished shortly before the narrative's 300 s timeout fired. |
| 2.2 Ollama concurrency | Two 40-token generations sent at the same moment to `localhost:11434` (`/api/generate`, `qwen3:8b`); `scripts/ollama_serial_probe.py`, output in `evidence/logs/ollama_serial_probe.log` (run 2026-10-16 21:20, idle model) | A single request takes 0.80 s (0.77 s of token evaluation). Of two concurrent requests one finished after 0.83 s and the other after 1.68 s, each with about 0.8 s of evaluation: the second request started only after the first finished, i.e. requests to one loaded model are processed one at a time. An earlier probe during the investigation gave the same pattern (0.33 s and 0.64 s with a shorter prompt). `ollama --version` 0.35.1; `OLLAMA_NUM_PARALLEL` is **unset**, so the server chooses the parallelism (one slot on this machine for an 8 B model with an 8192-token context). |
| 2.3 Where time is spent | Ollama HTTP semantics | The client timeout covers the whole request, including the time the request spends queued inside the Ollama server. A narrative that needs 90 s of generation therefore fails after 300 s when it arrives behind 240 s or more of translation batches, even though the model never worked on it. |
| 2.4 Application-side scheduling | Code review of `AiGateway`, `OllamaClient`, the jobs | No component knew that the model is a single shared resource. `OllamaClient::chatMany` sends translation batches sequentially but a second worker can interleave its own request between them. There was no priority, no bounded wait, and no retry for this case (`GenerateReportJob::$tries = 1`). |
| 2.5 Why it appeared now | DEF-04 fix (commit 0a3137f) | Before DEF-04 one worker served both queues, so translation and narrative jobs never overlapped (user jobs waited behind translations instead, which was DEF-04). Splitting the queues onto two workers removed that accidental serialisation. |
| 2.6 Memory headroom (measured before considering `OLLAMA_NUM_PARALLEL`) | `sysctl hw.memsize`, `ollama ps`, `memory_pressure` during the run (`logs/ollama_memory_samples.log`) | 64 GiB unified memory; the loaded `qwen3:8b` occupies 6.6 GB (100 % GPU) with an 8192-token context; about 54 % of system memory free at the start of the run. A second parallel slot would add another context's KV cache (of the order of 1–2 GB for this model), which this machine could hold. It would **not** remove the defect: two slots are still finite, a third request queues again, and per-request latency rises when the slots are shared. |

## 3. Root cause

The system treated the local model as if it could serve many requests at once. In reality Ollama serves one generation at a time per loaded model, the HTTP timeout includes the server-side queue wait, and after the DEF-04 worker split two workers could submit model requests simultaneously. A long, low-value background request (pre-translation of a finished report) could therefore occupy the model for longer than the narrative request's whole timeout budget. The defect is a missing application-level access policy for a single shared model: no priority for user-facing work, no bounded waiting, and a timeout that was consumed by queueing rather than generation.

## 4. Contributing factors

- `OLLAMA_TIMEOUT` (300 s) is a per-request ceiling, not a generation budget; it cannot distinguish "queued" from "generating".
- Pre-translation is dispatched immediately after every report (three languages, several batches each), so the model is busy for minutes right after each report is written.
- Burmese translation batches are the slowest requests in the system (4–5 minutes per job in the development stack).
- `GenerateReportJob` had no retry path; one blocked request produced a final, claim-less report.

## 5. Options considered

| Option | Assessment |
| --- | --- |
| Raise `OLLAMA_TIMEOUT` | Rejected: hides the queueing, makes the user wait longer and still fails when the backlog is longer than the new ceiling. |
| Set `OLLAMA_NUM_PARALLEL=2` | Not adopted as the fix: memory allows it (section 2.6) but it only moves the limit, slows both requests, is host configuration outside the repository, and does nothing for the production provider path. Kept as an optional operational tuning with the measurement recorded. |
| Revert DEF-04 (one worker) | Rejected: re-introduces DEF-04 (user jobs delayed by translations). |
| Pause pre-translation while a narrative is generating, with bounded waits and priority | **Adopted**: application-side coordination of model access (see `def09-fix-summary.md`). |
| Retry the narrative once when the model was busy | **Adopted** in bounded form (one delayed retry, only for the busy case). |

## 6. Evidence references

- Original failing record: `../retesting/logs/system_tests_post_fix.log` (SYS-09 first attempt), `../retesting/evidence/system-run/system_test_results.json`, `../retesting/defect-retesting-results.md` section 2b (kept unchanged).
- Serial-processing probe and memory samples: section 2.2 above; `logs/ollama_memory_samples.log`.
- Fix verification: `def09-test-results.md`, `concurrency-performance-results.csv`, `final-regression-results.md`.
