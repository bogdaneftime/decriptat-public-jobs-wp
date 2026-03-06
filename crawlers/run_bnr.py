from __future__ import annotations

import sys
from pathlib import Path

# Allow running as: python crawlers/run_bnr.py
PROJECT_ROOT = Path(__file__).resolve().parents[1]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from crawlers.common.config import load_settings
from crawlers.common.storage import SeenStorage
from crawlers.common.utils import compute_job_hash
from crawlers.common.wp_client import WordPressClient
from crawlers.sources.bnr import fetch_bnr_jobs


def main() -> int:
    fetched = 0
    published = 0
    skipped_seen = 0
    failed = 0
    storage = None

    try:
        settings = load_settings()
        storage = SeenStorage(settings.storage_path)
        wp = WordPressClient(settings)

        jobs = fetch_bnr_jobs(settings)
        fetched = len(jobs)
        for job in jobs:
            hash_value = compute_job_hash(job.title, job.details_url, job.deadline_iso)
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
                print(f"[PUBLISHED] {job.title} -> post_id={post_id}")
            except Exception as exc:  # noqa: BLE001
                failed += 1
                print(f"[ERROR] Failed to publish {job.title}: {exc}")

        print(
            f"[SUMMARY] source=bnr fetched={fetched} published={published} "
            f"skipped_seen={skipped_seen} failed={failed}"
        )
    except Exception as exc:  # noqa: BLE001
        failed += 1
        print(f"[ERROR] Fatal run error: {exc}")
        print(
            f"[SUMMARY] source=bnr fetched={fetched} published={published} "
            f"skipped_seen={skipped_seen} failed={failed}"
        )
    finally:
        if storage is not None:
            storage.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
