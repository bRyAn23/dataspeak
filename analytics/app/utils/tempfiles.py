"""Per-request scratch directory (FEAT-UPL-003). Always removed, on success and on failure."""

from __future__ import annotations

import os
import shutil
import tempfile
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator


@contextmanager
def request_tempdir(root: str) -> Iterator[Path]:
    base = Path(root)
    base.mkdir(parents=True, exist_ok=True)
    path = Path(tempfile.mkdtemp(prefix="req-", dir=str(base)))
    try:
        yield path
    finally:
        shutil.rmtree(path, ignore_errors=True)


def tempdir_entries(root: str) -> list[str]:
    """Names of request directories currently present (used by cleanup tests and diagnostics)."""
    base = Path(root)
    if not base.exists():
        return []
    return sorted(p for p in os.listdir(base) if p.startswith("req-"))
