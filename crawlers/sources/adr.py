from __future__ import annotations

import logging
import re
import time
from dataclasses import dataclass
from typing import Dict, List, Optional, Set
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup, Tag

from crawlers.common.announcement_classifier import classify_announcement
from crawlers.common.classifier import classify_job
from crawlers.common.config import Settings
from crawlers.common.deadline_extractor import extract_application_deadline, resolve_expired
from crawlers.common.documents import extract_document_text
from crawlers.common.models import AttachmentRecord, JobRecord
from crawlers.common.utils import (
    extract_first_iso_date,
    is_year,
    normalize_text,
    to_absolute_url,
)

LOGGER = logging.getLogger(__name__)

ADR_LISTING_URL = "https://www.adr.gov.ro/cariera"
ADR_HOST = "www.adr.gov.ro"
TARGET_YEAR = 2026
MAX_LISTING_PAGES = 15


@dataclass(frozen=True)
class ListingEntry:
    title: str
    details_url: str
    published_date_iso: Optional[str]


def fetch_adr_jobs(settings: Settings) -> List[JobRecord]:
    listing_urls = _collect_listing_pages(settings)
    entries_by_url: Dict[str, ListingEntry] = {}

    for listing_url in listing_urls:
        try:
            response = _request_with_retry(settings, listing_url)
        except Exception as exc:  # noqa: BLE001
            LOGGER.warning("ADR listing fetch failed url=%s error=%s", listing_url, exc)
            continue
        for entry in _extract_listing_entries(listing_url, response.text):
            if entry.details_url not in entries_by_url:
                entries_by_url[entry.details_url] = entry

    jobs: List[JobRecord] = []
    for entry in entries_by_url.values():
        job = _build_job_from_entry(settings, entry)
        if job is not None:
            jobs.append(job)

    jobs.sort(
        key=lambda item: (item.deadline_iso or "9999-12-31", item.published_date_iso or "", item.title)
    )
    return jobs


def _request_with_retry(settings: Settings, url: str) -> requests.Response:
    last_exc: Optional[Exception] = None
    for attempt in range(1, settings.retry_attempts + 1):
        try:
            response = requests.get(
                url,
                timeout=settings.request_timeout,
                headers={
                    "User-Agent": settings.user_agent,
                    "Accept-Language": "ro-RO,ro;q=0.9,en-US;q=0.8,en;q=0.7",
                },
            )
            response.raise_for_status()
            response.encoding = response.apparent_encoding or response.encoding
            return response
        except Exception as exc:  # noqa: BLE001
            last_exc = exc
            if attempt == settings.retry_attempts:
                break
            time.sleep(attempt)
    raise RuntimeError(f"Request failed for {url}: {last_exc}") from last_exc


def _collect_listing_pages(settings: Settings) -> List[str]:
    queue: List[str] = [ADR_LISTING_URL]
    seen: Set[str] = set()
    pages: List[str] = []

    while queue and len(pages) < MAX_LISTING_PAGES:
        current_url = queue.pop(0)
        if current_url in seen:
            continue
        seen.add(current_url)
        pages.append(current_url)

        try:
            response = _request_with_retry(settings, current_url)
        except Exception as exc:  # noqa: BLE001
            LOGGER.warning("ADR pagination fetch failed url=%s error=%s", current_url, exc)
            continue

        soup = BeautifulSoup(response.text, "lxml")
        root = soup.find("main") or soup
        for link in root.find_all("a", href=True):
            href = to_absolute_url(current_url, link.get("href", ""))
            if _is_listing_page_url(href) and href not in seen and href not in queue:
                queue.append(href)

    return pages


def _is_listing_page_url(url: str) -> bool:
    parsed = urlparse(url)
    if parsed.netloc != ADR_HOST:
        return False
    if not parsed.path.rstrip("/").endswith("/cariera"):
        return False
    query = (parsed.query or "").lower()
    if not query:
        return True
    return "page=" in query


def _extract_listing_entries(base_url: str, listing_html: str) -> List[ListingEntry]:
    soup = BeautifulSoup(listing_html, "lxml")
    root = soup.find("main") or soup
    by_url: Dict[str, ListingEntry] = {}

    for link in root.find_all("a", href=True):
        href = to_absolute_url(base_url, link.get("href", "").strip())
        if not _is_details_page_url(href):
            continue

        title = link.get_text(" ", strip=True)
        if len(title) < 8:
            continue

        context = _extract_context_text(link)
        published_date_iso = extract_first_iso_date(context, title)
        existing = by_url.get(href)
        if existing is None or len(title) > len(existing.title):
            by_url[href] = ListingEntry(
                title=title,
                details_url=href,
                published_date_iso=published_date_iso,
            )

    return list(by_url.values())


def _extract_context_text(link: Tag) -> str:
    parent = link
    for _ in range(5):
        if parent is None:
            break
        if parent.name in {"article", "li", "section", "div"}:
            text = parent.get_text(" ", strip=True)
            if text:
                return text
        parent = parent.parent if isinstance(parent.parent, Tag) else None
    return link.get_text(" ", strip=True)


def _is_details_page_url(url: str) -> bool:
    parsed = urlparse(url)
    if parsed.netloc != ADR_HOST:
        return False
    path = parsed.path.rstrip("/")
    if not path.startswith("/cariera/"):
        return False
    return path != "/cariera"


def _build_job_from_entry(settings: Settings, entry: ListingEntry) -> Optional[JobRecord]:
    try:
        response = _request_with_retry(settings, entry.details_url)
    except Exception as exc:  # noqa: BLE001
        LOGGER.warning("ADR details fetch failed url=%s error=%s", entry.details_url, exc)
        return None

    soup = BeautifulSoup(response.text, "lxml")
    root = soup.find("main") or soup.find("article") or soup.body or soup
    title = _extract_title(soup, entry.title)
    page_text = re.sub(r"\s+", " ", root.get_text(" ", strip=True))

    attachments = _extract_attachments(root, entry.details_url)
    attachment_labels = " ".join(item.label for item in attachments if item.label)
    attachment_text_chunks = []
    for attachment in attachments:
        text = extract_document_text(settings, attachment.url)
        if text:
            attachment_text_chunks.append(text[:6000])
    attachment_text = " ".join(attachment_text_chunks)

    combined_text = " ".join(part for part in [title, page_text, attachment_labels, attachment_text] if part)
    body_excerpt = combined_text[:2200]

    announcement_decision = classify_announcement(
        settings=settings,
        title=title,
        publication_date_iso=entry.published_date_iso,
        body_excerpt=body_excerpt,
        attachment_labels=attachment_labels,
    )
    if "new_contest" != announcement_decision.label:
        LOGGER.info(
            "ADR skip announcement title=%s decision=%s source=%s",
            title,
            announcement_decision.label,
            announcement_decision.source,
        )
        return None

    published_date_iso = entry.published_date_iso or extract_first_iso_date(
        title,
        page_text,
        attachment_text,
    )
    if not is_year(published_date_iso, TARGET_YEAR):
        return None

    deadline_result = extract_application_deadline(
        settings=settings,
        title=title,
        body_text=page_text,
        attachment_text=attachment_text,
    )
    deadline_iso = deadline_result.deadline_iso
    expired = resolve_expired(deadline_iso)
    location = _extract_location(page_text)

    classification_result = classify_job(
        settings=settings,
        title=title,
        department="",
        details=combined_text,
    )

    job = JobRecord(
        source="adr",
        title=title,
        details_url=entry.details_url,
        institution_name="Autoritatea pentru Digitalizarea Rom\u00e2niei",
        location=location,
        published_date_iso=published_date_iso,
        deadline_iso=deadline_iso,
        details_text=combined_text[:8000],
        attachments=attachments,
        job_category=classification_result.category,
        is_it=classification_result.category == "IT",
        expired=expired,
    )
    first_pdf = next((item.url for item in attachments if item.url.lower().endswith(".pdf")), "")
    job.pdf_url = first_pdf
    return job


def _extract_title(soup: BeautifulSoup, fallback: str) -> str:
    title_tag = soup.find("h1")
    if title_tag:
        text = title_tag.get_text(" ", strip=True)
        if text:
            return text
    return fallback


def _extract_attachments(root: Tag, base_url: str) -> List[AttachmentRecord]:
    seen: Set[str] = set()
    attachments: List[AttachmentRecord] = []
    for link in root.find_all("a", href=True):
        href = to_absolute_url(base_url, link.get("href", "").strip())
        lowered = href.lower()
        if not any(ext in lowered for ext in (".pdf", ".docx", ".doc")):
            continue
        if href in seen:
            continue
        seen.add(href)
        label = link.get_text(" ", strip=True) or "Document"
        attachments.append(AttachmentRecord(label=label, url=href))
    return attachments


def _extract_location(page_text: str) -> str:
    if not page_text:
        return ""

    explicit = re.search(r"locati[ea]\s*[:\-]\s*([^.\\n;]{2,80})", page_text, re.IGNORECASE)
    if explicit:
        return explicit.group(1).strip(" :;,-")

    normalized = normalize_text(page_text)
    if "bucuresti" in normalized:
        return "Bucuresti"
    return ""
