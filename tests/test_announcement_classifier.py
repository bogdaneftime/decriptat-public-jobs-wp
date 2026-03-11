from __future__ import annotations

import unittest
from pathlib import Path

from crawlers.common.announcement_classifier import classify_announcement
from crawlers.common.config import Settings


def _settings_no_ai() -> Settings:
    return Settings(
        wp_base_url="https://example.com",
        wp_user="user",
        wp_app_pass="pass",
        wp_status="draft",
        storage_path=Path("crawlers/.state/test.sqlite"),
        openai_api_key="",
    )


class AnnouncementClassifierTests(unittest.TestCase):
    def setUp(self) -> None:
        self.settings = _settings_no_ai()

    def test_keeps_new_contest_announcement(self) -> None:
        result = classify_announcement(
            settings=self.settings,
            title="Anunt privind organizarea concursului de recrutare pentru ocuparea unui post",
            publication_date_iso="2026-02-01",
            body_excerpt="Se organizeaza concurs de recrutare pentru ocuparea unui post vacant.",
            attachment_labels="Anunt concurs.pdf",
        )
        self.assertEqual("new_contest", result.label)

    def test_excludes_final_results(self) -> None:
        result = classify_announcement(
            settings=self.settings,
            title="Rezultatele finale ale concursului",
            publication_date_iso="2026-02-12",
            body_excerpt="Rezultatele finale dupa contestatii.",
            attachment_labels="Rezultate finale.pdf",
        )
        self.assertEqual("results_or_followup", result.label)

    def test_excludes_contestation_results(self) -> None:
        result = classify_announcement(
            settings=self.settings,
            title="Rezultatul contestatiilor depuse la proba scrisa",
            publication_date_iso="2026-02-15",
            body_excerpt="Rezultatul contestatiilor pentru concursul anterior.",
            attachment_labels="Rezultate contestatii.pdf",
        )
        self.assertEqual("results_or_followup", result.label)


if __name__ == "__main__":
    unittest.main()
