from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv


@dataclass(frozen=True)
class Settings:
    wp_base_url: str
    wp_user: str
    wp_app_pass: str
    wp_status: str
    storage_path: Path
    request_timeout: int = 15
    user_agent: str = "decriptat-job-crawler/0.1 (+contact@decriptat.ro)"
    retry_attempts: int = 3


def load_settings() -> Settings:
    load_dotenv()

    wp_base_url = (os.getenv("WP_BASE_URL") or "").strip().rstrip("/")
    wp_user = (os.getenv("WP_USER") or "").strip()
    # Keep password value exactly as read, including spaces.
    wp_app_pass = os.getenv("WP_APP_PASS") or ""
    wp_status = (os.getenv("WP_STATUS") or "draft").strip().lower() or "draft"
    storage_raw = (os.getenv("STORAGE_PATH") or "crawlers/.state/seen.sqlite").strip()
    storage_path = Path(storage_raw)

    if not wp_base_url:
        raise ValueError("Missing required env var: WP_BASE_URL")
    if not wp_user:
        raise ValueError("Missing required env var: WP_USER")
    if not wp_app_pass:
        raise ValueError("Missing required env var: WP_APP_PASS")
    if wp_status != "draft":
        raise ValueError("WP_STATUS must be draft for this publishing mode.")

    return Settings(
        wp_base_url=wp_base_url,
        wp_user=wp_user,
        wp_app_pass=wp_app_pass,
        wp_status=wp_status,
        storage_path=storage_path,
    )
