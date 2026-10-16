"""Analyse the participant usability study from the two CSV files.

Reads docs/evidence/unit6/usability/usability_results.csv and usability_feedback.csv, refuses to run
while either holds no data rows, and otherwise writes:
  - usability/usability_summary.md     counts, rates, Table 2, rating means, verbatim comments per task
  - usability/B05_task_completion_chart.png   independent completion rate per task, 300 DPI, 0-100% axis
  - usability/usability_numbers.json   the figures to quote in the written subsection
Nothing is invented: every number is computed from the rows present, with the actual number of
attempted tasks as the denominator. Qualitative themes are NOT generated automatically; the script
lists the verbatim comments grouped by task so the researcher can code them by hand.
"""

from __future__ import annotations

import csv
import json
import statistics
import sys
from collections import Counter, defaultdict
from datetime import date
from pathlib import Path

HERE = Path(__file__).resolve().parent
USAB = HERE.parent / "usability"
RESULTS = USAB / "usability_results.csv"
FEEDBACK = USAB / "usability_feedback.csv"
TASKS = {"T1": "CSV upload", "T2": "Data profiling", "T3": "Report interpretation", "T4": "Claim traceability"}
STATUSES = {"independent", "assisted", "failed"}
RATINGS = ["ease_of_use", "validation_clarity", "chart_clarity", "summary_clarity", "evidence_confidence"]
RATING_LABELS = {
    "ease_of_use": "Overall ease of use",
    "validation_clarity": "Clarity of validation messages",
    "chart_clarity": "Clarity of charts",
    "summary_clarity": "Understandability of executive summaries",
    "evidence_confidence": "Confidence in the supporting evidence",
}


def read(path: Path) -> list[dict]:
    with path.open(encoding="utf-8", newline="") as f:
        return [r for r in csv.DictReader(f) if any((v or "").strip() for v in r.values())]


def fail(msg: str) -> None:
    print(f"ERROR: {msg}", file=sys.stderr)
    sys.exit(2)


def main() -> int:
    results = read(RESULTS)
    feedback = read(FEEDBACK)
    if not results:
        fail(f"{RESULTS.name} has no data rows. Run participant sessions first; nothing is generated from blank templates.")
    # validate
    for i, r in enumerate(results, 2):
        if r["task_id"] not in TASKS:
            fail(f"{RESULTS.name} line {i}: unknown task_id {r['task_id']!r}")
        if r["completion_status"] not in STATUSES:
            fail(f"{RESULTS.name} line {i}: completion_status must be one of {sorted(STATUSES)}")
        if r["participant_category"] not in {"technical", "nontechnical"}:
            fail(f"{RESULTS.name} line {i}: participant_category must be technical or nontechnical")
        if r["duration_seconds"].strip() and not r["duration_seconds"].strip().isdigit():
            fail(f"{RESULTS.name} line {i}: duration_seconds must be whole seconds or blank")
    for i, r in enumerate(feedback, 2):
        for k in RATINGS:
            v = r[k].strip()
            if v and (not v.isdigit() or not 1 <= int(v) <= 5):
                fail(f"{FEEDBACK.name} line {i}: {k} must be 1-5 or blank")

    participants = sorted({r["participant_id"] for r in results})
    cats = {r["participant_id"]: r["participant_category"] for r in results}
    attempted = len(results)
    by_status = Counter(r["completion_status"] for r in results)
    per_task: dict[str, Counter] = defaultdict(Counter)
    for r in results:
        per_task[r["task_id"]][r["completion_status"]] += 1
    per_task_attempted = {t: sum(c.values()) for t, c in per_task.items()}
    per_task_rate = {t: (per_task[t]["independent"] / n * 100 if n else None) for t, n in per_task_attempted.items()}
    overall_rate = by_status["independent"] / attempted * 100

    rating_stats = {}
    for k in RATINGS:
        vals = [int(r[k]) for r in feedback if r[k].strip()]
        rating_stats[k] = {"n": len(vals), "mean": round(statistics.fmean(vals), 2) if vals else None,
                           "min": min(vals) if vals else None, "max": max(vals) if vals else None}
    durations = defaultdict(list)
    for r in results:
        if r["duration_seconds"].strip():
            durations[r["task_id"]].append(int(r["duration_seconds"]))

    numbers = {
        "generated_on": date.today().isoformat(),
        "participants": len(participants), "participant_ids": participants,
        "categories": dict(Counter(cats.values())),
        "tasks_attempted": attempted, "independent": by_status["independent"], "assisted": by_status["assisted"],
        "failed": by_status["failed"], "independent_rate_pct": round(overall_rate, 1),
        "per_task": {t: {"attempted": per_task_attempted.get(t, 0), "independent": per_task[t]["independent"],
                         "assisted": per_task[t]["assisted"], "failed": per_task[t]["failed"],
                         "independent_rate_pct": round(per_task_rate[t], 1) if per_task_rate.get(t) is not None else None,
                         "median_duration_s": statistics.median(durations[t]) if durations.get(t) else None}
                     for t in TASKS},
        "ratings": rating_stats, "feedback_rows": len(feedback),
    }
    (USAB / "usability_numbers.json").write_text(json.dumps(numbers, indent=2, ensure_ascii=False), encoding="utf-8")

    # ---- summary markdown
    L = []
    L.append("# Usability Evaluation Summary\n")
    L.append(f"Generated {numbers['generated_on']} from `usability_results.csv` ({attempted} task rows) and `usability_feedback.csv` ({len(feedback)} participant rows). Participants: {len(participants)} ({', '.join(participants)}); categories: " + ", ".join(f"{k} {v}" for k, v in numbers['categories'].items()) + ". All figures are computed from the recorded rows; the denominator is the number of tasks actually attempted.\n")
    L.append("## 1. Measured outcomes\n")
    L.append("| Measure | Value |\n| --- | --- |")
    L.append(f"| Tasks attempted | {attempted} |")
    L.append(f"| Independently completed | {by_status['independent']} |")
    L.append(f"| Assisted completions | {by_status['assisted']} |")
    L.append(f"| Failed | {by_status['failed']} |")
    L.append(f"| Independent task completion rate | {overall_rate:.1f}% ({by_status['independent']}/{attempted}) |")
    e = rating_stats["ease_of_use"]
    L.append(f"| Mean ease-of-use rating (1-5) | {e['mean'] if e['mean'] is not None else 'no ratings recorded'} (n = {e['n']}) |\n")
    L.append("**Table 2**\n\n*Task-Based Usability Evaluation Results*\n")
    L.append("| Task | Participants attempting | Independent completions | Assisted completions | Failures | Independent rate | Observed usability issues |\n| --- | --- | --- | --- | --- | --- | --- |")
    for t, name in TASKS.items():
        c = per_task[t]; n = per_task_attempted.get(t, 0)
        rate = f"{per_task_rate[t]:.0f}%" if per_task_rate.get(t) is not None else "n/a"
        L.append(f"| {t} {name} | {n} | {c['independent']} | {c['assisted']} | {c['failed']} | {rate} | [researcher: summarise from the difficulty notes below] |")
    L.append("\n*Note.* Independent = completed without help; assisted = completed after a recorded hint; failed = not completed or incorrect. Denominators are the tasks actually attempted.\n")
    L.append("## 2. Participant ratings (participant-reported)\n")
    L.append("| Statement | n | Mean | Min | Max |\n| --- | --- | --- | --- | --- |")
    for k in RATINGS:
        s = rating_stats[k]
        L.append(f"| {RATING_LABELS[k]} | {s['n']} | {s['mean'] if s['mean'] is not None else '-'} | {s['min'] if s['min'] is not None else '-'} | {s['max'] if s['max'] is not None else '-'} |")
    L.append("\n## 3. Observed behaviour (researcher log, verbatim)\n")
    for t, name in TASKS.items():
        L.append(f"### {t} {name}\n")
        rows = [r for r in results if r["task_id"] == t]
        if not rows:
            L.append("_No attempts recorded._\n"); continue
        L.append("| Participant | Category | Status | Duration (s) | Observed difficulty | Participant comment |\n| --- | --- | --- | --- | --- | --- |")
        for r in rows:
            L.append(f"| {r['participant_id']} | {r['participant_category']} | {r['completion_status']} | {r['duration_seconds'] or '-'} | {r['difficulty'].replace('|', '/')} | {r['comment'].replace('|', '/')} |")
        L.append("")
    L.append("## 4. Participant suggestions (verbatim)\n")
    for r in feedback:
        L.append(f"- {r['participant_id']}: {r['suggestions'] or '(none)'}")
    L.append("\n## 5. Interpretation (researcher)\n")
    L.append("[To be written by the researcher after reading sections 3 and 4: recurring themes with the supporting participant IDs and quotes, what the completion pattern suggests, and limitations (formative design, five participants, one dataset, researcher present). Keep this section separate from the measured and observed sections above.]\n")
    (USAB / "usability_summary.md").write_text("\n".join(L), encoding="utf-8")

    # ---- chart
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
    except ImportError:
        print("matplotlib not available: summary written, chart skipped"); return 0
    labels = [f"{t}\n{TASKS[t]}" for t in TASKS]
    rates = [per_task_rate.get(t) or 0 for t in TASKS]
    ns = [per_task_attempted.get(t, 0) for t in TASKS]
    plt.rcParams.update({"font.family": "DejaVu Sans", "font.size": 10, "axes.spines.top": False, "axes.spines.right": False})
    fig, ax = plt.subplots(figsize=(7.5, 4.2), dpi=300)
    bars = ax.bar(labels, rates, color="#1f4e79", width=0.55, zorder=3)
    for b, rate, n, t in zip(bars, rates, ns, TASKS):
        ax.annotate(f"{rate:.0f}%\n({per_task[t]['independent']}/{n})", (b.get_x() + b.get_width() / 2, b.get_height()), textcoords="offset points", xytext=(0, 4), ha="center", fontsize=9)
    ax.set_ylim(0, 100); ax.set_ylabel("Independent task completion rate (%)"); ax.set_xlabel("Task")
    ax.set_title("Data Speak Usability Evaluation: Independent Task Completion by Task", fontsize=12, pad=12)
    ax.grid(axis="y", color="#dddddd", linewidth=0.6, zorder=0)
    fig.text(0.99, 0.01, f"n = {len(participants)} participants, {attempted} task attempts; generated {numbers['generated_on']}", ha="right", va="bottom", fontsize=7, color="#666666")
    fig.tight_layout(); fig.savefig(USAB / "B05_task_completion_chart.png", dpi=300)
    print(f"written: usability_summary.md, usability_numbers.json, B05_task_completion_chart.png | {attempted} attempts, {overall_rate:.1f}% independent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
