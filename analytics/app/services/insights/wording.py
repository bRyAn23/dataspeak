"""Neutral, descriptive wording templates. No causal verbs (FastAPI prompt Section 9).

CAUSAL_WORDS is asserted against every generated headline/description in tests.
"""

from __future__ import annotations

CAUSAL_WORDS = (
    "because",
    "cause",
    "caused",
    "causes",
    "drive",
    "drives",
    "drove",
    "driven",
    "led to",
    "leads to",
    "due to",
    "result of",
    "results in",
    "resulted in",
    "thanks to",
    "impact of",
    "effect of",
    "explains",
)


def trend(label: str, growth_display: str, period: str) -> str:
    return f"{label} changed by {growth_display} per {period} on average"


def latest(label: str, change_display: str, frm: str, to: str) -> str:
    return f"{label} moved {change_display} from {frm} to {to}"


def concentration(group: str, share_display: str, measure: str, dimension: str) -> str:
    return f"{group} accounts for {share_display} of {measure} across {dimension}"


def group_difference(measure: str, dimension: str, hi: str, lo: str, diff_display: str) -> str:
    return f"Average {measure} differs between {dimension} groups: {hi} is {diff_display} higher than {lo}"


def association_numeric(a: str, b: str, direction: str, strength: str) -> str:
    verb = "moved together" if direction == "positive" else "moved in opposite directions"
    return f"{a} and {b} {verb} ({strength} association)"


def association_categorical(a: str, b: str, v_display: str) -> str:
    return f"{a} and {b} are associated ({v_display})"


def missing(label: str, rate_display: str) -> str:
    return f"{label} is missing in {rate_display} of rows"


def duplicates(count_display: str, share_display: str) -> str:
    return f"{count_display} duplicate rows ({share_display}) were found"


def outliers(label: str, count_display: str, share_display: str) -> str:
    return f"{label} has {count_display} outlying values ({share_display})"
