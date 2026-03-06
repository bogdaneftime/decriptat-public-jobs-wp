from __future__ import annotations

import re
import time
from typing import Dict, List, Optional, Tuple
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup, Tag

from crawlers.common.config import Settings
from crawlers.common.models import JobRecord
from crawlers.common.utils import detect_it, is_expired, parse_deadline_to_iso, to_absolute_url

BNR_LISTING_URL = "https://www.bnr.ro/3025-posturi-vacante"
BNR_BLOCKS_URL = "https://www.bnr.ro/blocks"
TARGET_DEADLINE_YEAR = 2026
MIN_DEADLINE_ISO = "2026-01-01"
MAX_JOBS = 15


def _request_with_retry(
    settings: Settings,
    url: str,
    method: str = "GET",
    data: Optional[Dict[str, str]] = None,
) -> requests.Response:
    headers = {
        "User-Agent": settings.user_agent,
        "Accept-Language": "ro-RO,ro;q=0.9,en-US;q=0.8,en;q=0.7",
    }
    if method.upper() == "POST":
        headers["X-Requested-With"] = "XMLHttpRequest"

    last_exc: Optional[Exception] = None
    for attempt in range(1, settings.retry_attempts + 1):
        try:
            response = requests.request(
                method=method.upper(),
                url=url,
                headers=headers,
                data=data,
                timeout=settings.request_timeout,
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


def _extract_block_ids(listing_html: str) -> List[str]:
    soup = BeautifulSoup(listing_html, "lxml")
    ids: List[str] = []
    for block in soup.select(".block-wrapper[id]"):
        bid = block.get("id", "").strip()
        if bid.isdigit():
            ids.append(bid)
    return ids


def _current_slug(listing_url: str) -> str:
    path = urlparse(listing_url).path.strip("/")
    return path.split("/")[-1] if path else ""


def _parse_positions(text: str) -> Optional[int]:
    match = re.search(r"(\d+)", text or "")
    return int(match.group(1)) if match else None


def _extract_pdf_and_text(settings: Settings, details_url: str) -> Tuple[str, str]:
    response = _request_with_retry(settings, details_url)
    soup = BeautifulSoup(response.text, "lxml")

    pdf_url = ""
    for link in soup.find_all("a", href=True):
        href = link.get("href", "")
        if ".pdf" in href.lower():
            pdf_url = to_absolute_url(details_url, href)
            break

    root = soup.find("main") or soup.find("article") or soup.body or soup
    details_text = re.sub(r"\s+", " ", root.get_text(" ", strip=True))
    if len(details_text) > 1200:
        details_text = details_text[:1200]
    return pdf_url, details_text


def _parse_job_cards(block_html: str) -> List[JobRecord]:
    soup = BeautifulSoup(block_html, "lxml")
    jobs: List[JobRecord] = []

    for card in soup.select(".row-vpost"):
        cols = card.select(".row > div")
        if len(cols) < 5:
            continue

        # Keep only vacancy rows that contain the deadline label.
        card_text = card.get_text(" ", strip=True).lower()
        if "termen limit" not in card_text:
            continue

        title = cols[0].get_text(" ", strip=True)
        dept_parts = [p.get_text(" ", strip=True) for p in cols[1].find_all("p")]
        department = " | ".join([p for p in dept_parts if p]) if dept_parts else ""
        location = dept_parts[-1] if dept_parts else ""

        positions_parts = [p.get_text(" ", strip=True) for p in cols[2].find_all("p")]
        number_of_positions = _parse_positions(" ".join(positions_parts))

        deadline_parts = [p.get_text(" ", strip=True) for p in cols[3].find_all("p")]
        deadline_iso = None
        for part in reversed(deadline_parts):
            parsed = parse_deadline_to_iso(part)
            if parsed:
                deadline_iso = parsed
                break

        details_link = cols[4].find("a", href=True)
        if not details_link:
            continue
        details_url = to_absolute_url(BNR_LISTING_URL, details_link["href"])

        if not deadline_iso:
            continue
        if int(deadline_iso[:4]) != TARGET_DEADLINE_YEAR:
            continue
        if deadline_iso < MIN_DEADLINE_ISO:
            continue

        jobs.append(
            JobRecord(
                source="bnr",
                title=title.strip(),
                details_url=details_url,
                department=department.strip(),
                location=location.strip(),
                number_of_positions=number_of_positions,
                deadline_iso=deadline_iso,
                expired=is_expired(deadline_iso),
            )
        )

    return jobs


def fetch_bnr_jobs(settings: Settings) -> List[JobRecord]:
    listing_response = _request_with_retry(settings, BNR_LISTING_URL)
    block_ids = _extract_block_ids(listing_response.text)
    slug = _current_slug(BNR_LISTING_URL)

    jobs: List[JobRecord] = []
    seen_details_urls = set()
    for block_id in block_ids:
        block_response = _request_with_retry(
            settings=settings,
            url=BNR_BLOCKS_URL,
            method="POST",
            data={"bid": block_id, "currentSlug": slug, "cat_id": ""},
        )
        for job in _parse_job_cards(block_response.text):
            if job.details_url in seen_details_urls:
                continue
            seen_details_urls.add(job.details_url)
            jobs.append(job)

    jobs.sort(key=lambda j: (j.deadline_iso or "", j.title), reverse=True)
    jobs = jobs[:MAX_JOBS]

    for job in jobs:
        try:
            pdf_url, details_text = _extract_pdf_and_text(settings, job.details_url)
        except Exception:  # noqa: BLE001
            pdf_url, details_text = "", ""
        job.pdf_url = pdf_url
        job.details_text = details_text
        job.is_it = detect_it(job.title, job.department, details_text)

    return jobs
