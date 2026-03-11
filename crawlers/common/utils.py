from __future__ import annotations

import hashlib
import re
import unicodedata
from datetime import date, datetime
from typing import Optional
from urllib.parse import urljoin


RO_MONTHS = {
    "ianuarie": 1,
    "februarie": 2,
    "martie": 3,
    "aprilie": 4,
    "mai": 5,
    "iunie": 6,
    "iulie": 7,
    "august": 8,
    "septembrie": 9,
    "octombrie": 10,
    "noiembrie": 11,
    "decembrie": 12,
}

def strip_accents(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def normalize_text(value: str) -> str:
    collapsed = re.sub(r"\s+", " ", value or "").strip().lower()
    return strip_accents(collapsed)


def compute_job_hash(title: str, details_url: str, deadline_iso: Optional[str]) -> str:
    base = normalize_text(f"{title}|{details_url}|{deadline_iso or ''}")
    return hashlib.sha1(base.encode("utf-8")).hexdigest()


def to_absolute_url(base_url: str, href: str) -> str:
    return urljoin(base_url, href or "")


def parse_deadline_to_iso(raw_text: str) -> Optional[str]:
    if not raw_text:
        return None

    text = re.sub(r"\s+", " ", raw_text).strip()

    for fmt in ("%d.%m.%Y", "%d/%m/%Y", "%Y-%m-%d"):
        try:
            return datetime.strptime(text, fmt).date().isoformat()
        except ValueError:
            pass

    match = re.search(r"(\d{1,2})[./-](\d{1,2})[./-](\d{4})", text)
    if match:
        day, month, year = match.groups()
        try:
            return date(int(year), int(month), int(day)).isoformat()
        except ValueError:
            return None

    month_match = re.search(
        r"(\d{1,2})\s+([A-Za-z\u0103\u00e2\u00ee\u0219\u021b\u015f\u0163]+)\s+(\d{4})",
        text,
        re.IGNORECASE,
    )
    if month_match:
        day_s, month_s, year_s = month_match.groups()
        month = RO_MONTHS.get(normalize_text(month_s))
        if month:
            try:
                return date(int(year_s), month, int(day_s)).isoformat()
            except ValueError:
                return None

    return None


def extract_iso_dates(text: str) -> list[str]:
    if not text:
        return []

    values: list[str] = []
    seen: set[str] = set()
    raw = re.sub(r"\s+", " ", text).strip()

    for match in re.finditer(r"\b\d{1,2}[./-]\d{1,2}[./-]\d{4}\b", raw):
        iso_date = parse_deadline_to_iso(match.group(0))
        if iso_date and iso_date not in seen:
            values.append(iso_date)
            seen.add(iso_date)

    for match in re.finditer(
        r"\b\d{1,2}\s+[A-Za-z\u0103\u00e2\u00ee\u0219\u021b\u015f\u0163]+\s+\d{4}\b",
        raw,
        re.IGNORECASE,
    ):
        iso_date = parse_deadline_to_iso(match.group(0))
        if iso_date and iso_date not in seen:
            values.append(iso_date)
            seen.add(iso_date)

    return values


def extract_first_iso_date(*texts: str, preferred_year: Optional[int] = None) -> Optional[str]:
    for raw in texts:
        for iso_date in extract_iso_dates(raw):
            if preferred_year is None or iso_date.startswith(f"{preferred_year:04d}-"):
                return iso_date
    return None


def extract_deadline_iso(*texts: str) -> Optional[str]:
    keywords = (
        "termen limita",
        "data limita",
        "pana la data de",
        "depunerea dosarelor",
        "dosarele se depun pana la",
        "concursul va avea loc",
        "inscrierile se fac pana la",
    )
    candidates: list[str] = []

    for raw in texts:
        if not raw:
            continue
        normalized = normalize_text(raw)
        for sentence in re.split(r"(?<=[.;\n])\s+", raw):
            norm_sentence = normalize_text(sentence)
            if not norm_sentence:
                continue
            if any(keyword in norm_sentence for keyword in keywords):
                candidates.extend(extract_iso_dates(sentence))

        # Fallback for pattern stretches like: "termen limita ... 15.05.2026".
        for keyword in keywords:
            pattern = re.compile(
                rf"{re.escape(keyword)}[^0-9a-z]{{0,80}}"
                r"(\d{1,2}[./-]\d{1,2}[./-]\d{4}|\d{1,2}\s+[a-z]+\s+\d{4})",
                re.IGNORECASE,
            )
            for match in pattern.finditer(normalized):
                parsed = parse_deadline_to_iso(match.group(1))
                if parsed:
                    candidates.append(parsed)

    if not candidates:
        return None

    return sorted(candidates)[0]


def is_year(value_iso: Optional[str], year: int) -> bool:
    return bool(value_iso and value_iso.startswith(f"{year:04d}-"))


def is_expired(deadline_iso: Optional[str]) -> bool:
    if not deadline_iso:
        return False
    try:
        deadline = date.fromisoformat(deadline_iso)
    except ValueError:
        return False
    return deadline < date.today()
