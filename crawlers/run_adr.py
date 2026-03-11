from __future__ import annotations

import logging
import sys
from pathlib import Path

# Allow running as: python crawlers/run_adr.py
PROJECT_ROOT = Path(__file__).resolve().parents[1]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from crawlers.common.config import load_settings
from crawlers.common.storage import SeenStorage
from crawlers.common.utils import compute_job_hash
from crawlers.common.wp_client import WordPressClient
from crawlers.sources.adr import fetch_adr_jobs

LOGGER = logging.getLogger(__name__)


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )

    fetched = 0
    published = 0
    skipped_seen = 0
    failed = 0
    storage = None

    try:
        settings = load_settings()
        storage = SeenStorage(settings.storage_path)
        wp = WordPressClient(settings)

        jobs = fetch_adr_jobs(settings)
        fetched = len(jobs)

        for job in jobs:
            hash_value = compute_job_hash(
                f"{job.source}|{job.title}|{job.published_date_iso or ''}",
                job.details_url,
                None,
            )
            if storage.has_hash(hash_value):
                skipped_seen += 1
                continue

            try:
                post_id = wp.create_job_post(job)
                storage.upsert(
                    hash_value=hash_value,
                    source=job.source,
                    source_url=job.details_url,
                    wp_post_id=post_id,
                )
                published += 1
                LOGGER.info(
                    "Published title=%s post_id=%s category=%s is_it=%s",
                    job.title,
                    post_id,
                    job.job_category,
                    job.is_it,
                )
            except Exception as exc:  # noqa: BLE001
                failed += 1
                LOGGER.error("Failed to publish title=%s error=%s", job.title, exc)

        LOGGER.info(
            "Summary source=adr fetched=%s published=%s skipped_seen=%s failed=%s",
            fetched,
            published,
            skipped_seen,
            failed,
        )
    except Exception as exc:  # noqa: BLE001
        failed += 1
        LOGGER.exception("Fatal run error: %s", exc)
        LOGGER.info(
            "Summary source=adr fetched=%s published=%s skipped_seen=%s failed=%s",
            fetched,
            published,
            skipped_seen,
            failed,
        )
    finally:
        if storage is not None:
            storage.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
