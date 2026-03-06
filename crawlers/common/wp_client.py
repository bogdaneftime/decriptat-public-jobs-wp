from __future__ import annotations

import time
from typing import Any, Dict, Optional
from urllib.parse import quote

import requests
from requests.auth import HTTPBasicAuth

from crawlers.common.config import Settings
from crawlers.common.models import JobRecord


class WordPressClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self.base_api = f"{self.settings.wp_base_url}/wp-json/wp/v2"
        self.session = requests.Session()
        self.session.headers.update(
            {
                "Accept": "application/json",
                "Content-Type": "application/json",
                "User-Agent": self.settings.user_agent,
            }
        )
        self.session.auth = HTTPBasicAuth(
            self.settings.wp_user, self.settings.wp_app_pass
        )

    def _request(self, method: str, path: str, **kwargs: Any) -> requests.Response:
        url = f"{self.base_api}{path}"
        last_exc: Optional[Exception] = None
        for attempt in range(1, self.settings.retry_attempts + 1):
            try:
                response = self.session.request(
                    method,
                    url,
                    timeout=self.settings.request_timeout,
                    **kwargs,
                )
                if response.status_code >= 500:
                    raise requests.HTTPError(
                        f"HTTP {response.status_code} for {url}", response=response
                    )
                return response
            except Exception as exc:  # noqa: BLE001
                last_exc = exc
                if attempt == self.settings.retry_attempts:
                    break
                time.sleep(attempt)
        raise RuntimeError(f"Request failed for {method} {url}: {last_exc}") from last_exc

    def ensure_term(self, taxonomy: str, term_name: str) -> int:
        search = quote(term_name)
        get_resp = self._request("GET", f"/{taxonomy}?search={search}&per_page=100")
        get_resp.raise_for_status()
        terms = get_resp.json()
        wanted = term_name.strip().lower()
        for term in terms:
            if str(term.get("name", "")).strip().lower() == wanted:
                return int(term["id"])

        post_resp = self._request("POST", f"/{taxonomy}", json={"name": term_name})
        if post_resp.status_code == 400:
            err = post_resp.json()
            data = err.get("data", {}) if isinstance(err, dict) else {}
            if isinstance(data, dict) and data.get("term_id"):
                return int(data["term_id"])
        post_resp.raise_for_status()
        term_data = post_resp.json()
        return int(term_data["id"])

    def create_job_post(self, job: JobRecord) -> int:
        institution_id = self.ensure_term(
            "institution", "Banca Na\u021bional\u0103 a Rom\u00e2niei"
        )
        category_name = "IT" if job.is_it else "Altele"
        category_id = self.ensure_term("job_category", category_name)

        payload: Dict[str, Any] = {
            "title": _build_title(job),
            "content": _build_content(job),
            "status": self.settings.wp_status,
            "institution": [institution_id],
            "job_category": [category_id],
            "meta": {
                "source_url": job.details_url,
                "deadline": job.deadline_iso or "",
                "location": job.location,
                "is_it": 1 if job.is_it else 0,
                "expired": 1 if job.expired else 0,
            },
        }

        response = self._request("POST", "/public_job", json=payload)
        response.raise_for_status()
        data = response.json()
        return int(data["id"])


def _build_title(job: JobRecord) -> str:
    suffix = f" - {job.location}" if job.location else ""
    return f"[BNR] {job.title}{suffix}"


def _detail_line(label: str, value: str) -> str:
    return f"<li><strong>{label}:</strong> {value}</li>" if value else ""


def _build_content(job: JobRecord) -> str:
    deadline_label = job.deadline_iso or "Nespecificat"
    positions_label = str(job.number_of_positions) if job.number_of_positions else "Nespecificat"
    parts = [
        "<p>Acest anunt de angajare a fost preluat automat de pe site-ul oficial BNR.</p>",
        "<p>Verificati sursa oficiala pentru cerinte complete si documentele necesare.</p>",
        "<h3>Detalii</h3>",
        "<ul>",
        _detail_line("Structura", job.department or "Nespecificat"),
        _detail_line("Numar posturi", positions_label),
        _detail_line("Locatie", job.location or "Nespecificat"),
        _detail_line("Termen limita", deadline_label),
        "</ul>",
        (
            f'<p><a href="{job.details_url}" target="_blank" rel="noopener noreferrer">'
            "Vezi anuntul oficial</a></p>"
        ),
    ]
    if job.pdf_url:
        parts.append(
            f'<p><a href="{job.pdf_url}" target="_blank" rel="noopener noreferrer">Document (PDF)</a></p>'
        )
    return "".join(parts)
