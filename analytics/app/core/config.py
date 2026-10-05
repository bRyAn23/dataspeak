"""Service configuration. All values come from the environment; nothing is hard-coded."""

from __future__ import annotations

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict

SERVICE_NAME = "dataspeak-analytics"
SERVICE_VERSION = "1.0"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    analytics_internal_token: str = ""
    dataspeak_tmp_dir: str = "./tmp"
    max_upload_mb: int = 50
    log_level: str = "INFO"

    @property
    def max_upload_bytes(self) -> int:
        return self.max_upload_mb * 1024 * 1024


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
