"""Shared request handling for the multipart dataset endpoints."""

from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Iterator, Tuple

from fastapi import Depends, UploadFile
from pydantic import ValidationError

from app.core.config import Settings, get_settings
from app.core.errors import AnalyticsError
from app.core.logging import get_logger
from app.schemas.requests import DatasetMetadata
from app.utils.tempfiles import request_tempdir

log = get_logger("dataspeak.api")
CHUNK = 1024 * 1024


def parse_metadata(metadata: str) -> DatasetMetadata:
    try:
        return DatasetMetadata.model_validate(json.loads(metadata))
    except json.JSONDecodeError as exc:
        raise AnalyticsError("ANALYTICS_INVALID_DATASET", "metadata must be a JSON object.") from exc
    except ValidationError as exc:
        details = [
            {"field": ".".join(str(p) for p in e.get("loc", [])), "issue": e.get("msg", "")} for e in exc.errors()
        ]
        raise AnalyticsError("ANALYTICS_INVALID_DATASET", "metadata is not valid.", details=details) from exc


def spool_upload(file: UploadFile, settings: Settings) -> Iterator[Tuple[Path, Path]]:
    """Streams the upload into a per-request temp dir with a hard size limit. Yields (dir, path)."""
    with request_tempdir(settings.dataspeak_tmp_dir) as tmp:
        path = tmp / "dataset.csv"
        written = 0
        with path.open("wb") as fh:
            while True:
                chunk = file.file.read(CHUNK)
                if not chunk:
                    break
                written += len(chunk)
                if written > settings.max_upload_bytes:
                    raise AnalyticsError(
                        "ANALYTICS_PAYLOAD_TOO_LARGE", f"The dataset exceeds {settings.max_upload_mb} MB."
                    )
                fh.write(chunk)
        yield tmp, path


class Timer:
    def __init__(self) -> None:
        self.start = time.perf_counter()

    @property
    def ms(self) -> int:
        return int((time.perf_counter() - self.start) * 1000)


def settings_dep(settings: Settings = Depends(get_settings)) -> Settings:
    return settings
