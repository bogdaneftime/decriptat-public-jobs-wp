from __future__ import annotations

import unittest
from datetime import date
from pathlib import Path

from crawlers.common.config import Settings
from crawlers.common.deadline_extractor import (
    extract_application_deadline,
    extract_publication_date,
    resolve_expired_with_publication,
)


def _settings_no_ai() -> Settings:
    return Settings(
        wp_base_url="https://example.com",
        wp_user="user",
        wp_app_pass="pass",
        wp_status="draft",
        storage_path=Path("crawlers/.state/test.sqlite"),
        openai_api_key="",
    )


class DeadlineExtractorTests(unittest.TestCase):
    def setUp(self) -> None:
        self.settings = _settings_no_ai()

    def test_extracts_application_deadline(self) -> None:
        result = extract_application_deadline(
            settings=self.settings,
            title="Concurs recrutare",
            body_text="Dosarele se depun pana la data de 15.03.2026 la registratura institutiei.",
            attachment_text="",
        )
        self.assertEqual("2026-03-15", result.deadline_iso)

    def test_extracts_bnr_application_phrase(self) -> None:
        result = extract_application_deadline(
            settings=self.settings,
            title="Post vacant BNR",
            body_text="Termen limita pentru primirea aplicatiilor: 31.01.2026",
            attachment_text="",
        )
        self.assertEqual("2026-01-31", result.deadline_iso)

    def test_ignores_interview_and_results_dates(self) -> None:
        result = extract_application_deadline(
            settings=self.settings,
            title="Rezultatele probei scrise",
            body_text=(
                "Proba scrisa are loc la 12.03.2026. "
                "Interviul va avea loc la 20.03.2026. "
                "Rezultatele finale se afiseaza la 25.03.2026."
            ),
            attachment_text="",
        )
        self.assertIsNone(result.deadline_iso)

    def test_returns_none_for_ambiguous_text(self) -> None:
        result = extract_application_deadline(
            settings=self.settings,
            title="Anunt",
            body_text="Sunt publicate informatii administrative fara termen explicit.",
            attachment_text="",
        )
        self.assertIsNone(result.deadline_iso)

    def test_extracts_publication_date_from_publicare_hint(self) -> None:
        result = extract_publication_date(
            settings=self.settings,
            title="Calendar concurs",
            body_text="26.01.2026 Publicare anunt; 26-30.01.2026 Depunere dosare.",
            attachment_text="",
        )
        self.assertEqual("2026-01-26", result.published_date_iso)

    def test_marks_expired_if_publication_is_older_than_previous_month_without_deadline(self) -> None:
        is_expired = resolve_expired_with_publication(
            deadline_iso=None,
            published_date_iso="2026-01-01",
            today=date(2026, 3, 30),
        )
        self.assertTrue(is_expired)

    def test_keeps_active_if_publication_is_in_previous_month_without_deadline(self) -> None:
        is_expired = resolve_expired_with_publication(
            deadline_iso=None,
            published_date_iso="2026-02-05",
            today=date(2026, 3, 30),
        )
        self.assertFalse(is_expired)

    def test_marks_expired_if_publication_is_unknown_without_deadline(self) -> None:
        is_expired = resolve_expired_with_publication(
            deadline_iso=None,
            published_date_iso=None,
            today=date(2026, 3, 30),
        )
        self.assertTrue(is_expired)


if __name__ == "__main__":
    unittest.main()
