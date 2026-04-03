from __future__ import annotations

import time
from typing import Any, Dict, Optional
from urllib.parse import quote

import requests
from requests.auth import HTTPBasicAuth

from crawlers.common.config import Settings
from crawlers.common.models import JobRecord

SOURCE_LABELS = {
    "bnr": "BNR",
    "adr": "ADR",
}


class WordPressClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self.base_api = f"{self.settings.wp_base_url}/wp-json/wp/v2"
        self.session = requests.Session()
        self._source_url_cache: dict[str, int] = {}
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

    def find_job_post_id_by_source_url(self, source_url: str) -> Optional[int]:
        normalized_url = (source_url or "").strip()
        if not normalized_url:
            return None

        if normalized_url in self._source_url_cache:
            return self._source_url_cache[normalized_url]

        page = 1
        statuses = "draft,publish,pending,future,private"

        while True:
            response = self._request(
                "GET",
                f"/public_job?per_page=100&page={page}&status={statuses}&context=edit",
            )
            response.raise_for_status()
            posts = response.json()
            if not posts:
                return None

            for post in posts:
                meta = post.get("meta", {})
                if not isinstance(meta, dict):
                    continue
                if str(meta.get("source_url", "")).strip() == normalized_url:
                    post_id = int(post["id"])
                    self._source_url_cache[normalized_url] = post_id
                    return post_id

            total_pages = int(response.headers.get("X-WP-TotalPages", "1"))
            if page >= total_pages:
                return None
            page += 1

    def create_job_post(self, job: JobRecord) -> int:
        institution_name = job.institution_name or "Banca Na\u021bional\u0103 a Rom\u00e2niei"
        institution_id = self.ensure_term("institution", institution_name)
        category_name = (job.job_category or "Altele").strip() or "Altele"
        category_id = self.ensure_term("job_category", category_name)

        payload: Dict[str, Any] = {
            "title": _build_title(job),
            "content": _build_content(job),
            "status": self.settings.wp_status,
            "institution": [institution_id],
            "job_category": [category_id],
            "meta": {
                "source_url": job.details_url,
                "published_date": job.published_date_iso or "",
                "deadline": job.deadline_iso or "",
                "location": job.location,
                "is_it": 1 if category_name == "IT" else 0,
                "expired": 1 if job.expired else 0,
            },
        }

        response = self._request("POST", "/public_job", json=payload)
        response.raise_for_status()
        data = response.json()
        post_id = int(data["id"])
        self._source_url_cache[job.details_url] = post_id
        return post_id


def _build_title(job: JobRecord) -> str:
    source_label = SOURCE_LABELS.get((job.source or "").lower(), (job.source or "").upper() or "SRC")
    suffix = ""
    if "bnr" == (job.source or "").lower() and job.location:
        suffix = f" - {job.location}"
    return f"[{source_label}] {job.title}{suffix}"


def _detail_line(label: str, value: str) -> str:
    return f"<li><strong>{label}:</strong> {value}</li>" if value else ""


def _build_content(job: JobRecord) -> str:
    published_label = job.published_date_iso or "Nespecificat"
    deadline_label = job.deadline_iso or "Nespecificat"
    category_label = job.job_category or "Altele"
    institution_label = job.institution_name or "Nespecificat"
    positions_label = str(job.number_of_positions) if job.number_of_positions else "Nespecificat"
    parts = [
        "<p>Acest anunt de angajare a fost preluat automat de pe sursa oficiala.</p>",
        "<p>Verificati sursa oficiala pentru cerinte complete si documentele necesare.</p>",
        "<h3>Detalii</h3>",
        "<ul>",
        _detail_line("Data publicare", published_label),
        _detail_line("Structura", job.department or "Nespecificat"),
        _detail_line("Numar posturi", positions_label),
        _detail_line("Locatie", job.location or "Nespecificat"),
        _detail_line("Termen limita", deadline_label),
        _detail_line("Categorie", category_label),
        _detail_line("Institutie", institution_label),
        "</ul>",
        (
            f'<p><a href="{job.details_url}" target="_blank" rel="noopener noreferrer">'
            "Vezi anuntul oficial</a></p>"
        ),
    ]
    if job.attachments:
        parts.append("<h3>Documente atasate</h3><ul>")
        for attachment in job.attachments:
            label = attachment.label or "Document"
            parts.append(
                '<li><a href="'
                + attachment.url
                + '" target="_blank" rel="noopener noreferrer">'
                + label
                + "</a></li>"
            )
        parts.append("</ul>")
    if job.pdf_url:
        parts.append(
            f'<p><a href="{job.pdf_url}" target="_blank" rel="noopener noreferrer">Document (PDF)</a></p>'
        )
    return "".join(parts)
