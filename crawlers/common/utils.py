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

IT_REGEXES = [
    re.compile(r"\bit\b", re.IGNORECASE),
    re.compile(r"\bsoftware\b", re.IGNORECASE),
    re.compile(r"\bdeveloper\b", re.IGNORECASE),
    re.compile(r"\bprogramator\b", re.IGNORECASE),
    re.compile(r"\badmin\b", re.IGNORECASE),
    re.compile(r"\badministrator\b", re.IGNORECASE),
    re.compile(r"\bsecurity\b", re.IGNORECASE),
    re.compile(r"\bcyber\b", re.IGNORECASE),
    re.compile(r"\bcibern", re.IGNORECASE),
    re.compile(r"\bdata\b", re.IGNORECASE),
    re.compile(r"\bbaze de date\b", re.IGNORECASE),
    re.compile(r"\bretea\b|\bnetwork\b", re.IGNORECASE),
    re.compile(r"\bsistem\b", re.IGNORECASE),
    re.compile(r"\bsysadmin\b", re.IGNORECASE),
    re.compile(r"\bdevops\b", re.IGNORECASE),
    re.compile(r"\bcloud\b", re.IGNORECASE),
    re.compile(r"\banalist\b", re.IGNORECASE),
    re.compile(r"\bbi\b", re.IGNORECASE),
]


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


def detect_it(*texts: str) -> bool:
    haystack = " ".join(normalize_text(t) for t in texts if t)
    return any(pattern.search(haystack) for pattern in IT_REGEXES)


def is_expired(deadline_iso: Optional[str]) -> bool:
    if not deadline_iso:
        return False
    try:
        deadline = date.fromisoformat(deadline_iso)
    except ValueError:
        return False
    return deadline < date.today()
