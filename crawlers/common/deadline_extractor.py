from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass
from datetime import date, timedelta
from typing import Optional

import requests

from crawlers.common.config import Settings
from crawlers.common.utils import extract_iso_dates, normalize_text, parse_deadline_to_iso

LOGGER = logging.getLogger(__name__)

DEADLINE_HINTS = (
    "termen limita",
    "data limita",
    "depunerea dosarelor",
    "dosarele se depun pana la",
    "pana la data de",
    "data pana la care",
    "inscrierile se fac pana la",
    "primirea aplicatiilor",
)

NON_APPLICATION_HINTS = (
    "interviu",
    "proba scrisa",
    "rezultat",
    "rezultate",
    "contestatii",
    "contestatie",
)


@dataclass(frozen=True)
class DeadlineResult:
    deadline_iso: Optional[str]
    source: str
    confident: bool


@dataclass(frozen=True)
class PublicationResult:
    published_date_iso: Optional[str]
    source: str
    confident: bool


@dataclass(frozen=True)
class DatesInferenceResult:
    deadline_iso: Optional[str]
    published_date_iso: Optional[str]
    source: str
    confident: bool


PUBLICATION_HINTS = (
    "publicat",
    "publicare",
    "publicare anunt",
    "data publicarii",
    "afisat",
)


def infer_dates(
    settings: Settings,
    title: str,
    body_text: str,
    attachment_text: str = "",
    fallback_published_iso: Optional[str] = None,
    fallback_deadline_iso: Optional[str] = None,
) -> DatesInferenceResult:
    deadline_rules = _extract_deadline_rules(title=title, body_text=body_text, attachment_text=attachment_text)
    published_rules = _extract_publication_rules(title=title, body_text=body_text, attachment_text=attachment_text)

    needs_ai = not deadline_rules.confident or not published_rules.confident
    if needs_ai and settings.openai_api_key:
        ai_success, ai_deadline, ai_published = _extract_dates_openai(
            settings=settings,
            title=title,
            body_text=body_text,
            attachment_text=attachment_text,
        )
        if ai_success:
            deadline_value = ai_deadline if ai_deadline is not None else deadline_rules.deadline_iso
            published_value = (
                ai_published
                if ai_published is not None
                else published_rules.published_date_iso
            )
            if not published_value:
                published_value = fallback_published_iso
            if not deadline_value:
                deadline_value = fallback_deadline_iso
            return DatesInferenceResult(
                deadline_iso=deadline_value,
                published_date_iso=published_value,
                source="openai",
                confident=True,
            )

    deadline_value = deadline_rules.deadline_iso or fallback_deadline_iso
    published_value = published_rules.published_date_iso or fallback_published_iso
    return DatesInferenceResult(
        deadline_iso=deadline_value,
        published_date_iso=published_value,
        source="rules",
        confident=deadline_rules.confident and published_rules.confident,
    )


def extract_application_deadline(
    settings: Settings,
    title: str,
    body_text: str,
    attachment_text: str = "",
    fallback_published_iso: Optional[str] = None,
    fallback_deadline_iso: Optional[str] = None,
) -> DeadlineResult:
    result = infer_dates(
        settings=settings,
        title=title,
        body_text=body_text,
        attachment_text=attachment_text,
        fallback_published_iso=fallback_published_iso,
        fallback_deadline_iso=fallback_deadline_iso,
    )
    return DeadlineResult(
        deadline_iso=result.deadline_iso,
        source=result.source,
        confident=result.confident,
    )


def extract_publication_date(
    settings: Settings,
    title: str,
    body_text: str,
    attachment_text: str = "",
    fallback_published_iso: Optional[str] = None,
    fallback_deadline_iso: Optional[str] = None,
) -> PublicationResult:
    result = infer_dates(
        settings=settings,
        title=title,
        body_text=body_text,
        attachment_text=attachment_text,
        fallback_published_iso=fallback_published_iso,
        fallback_deadline_iso=fallback_deadline_iso,
    )
    return PublicationResult(
        published_date_iso=result.published_date_iso,
        source=result.source,
        confident=result.confident,
    )


def _extract_deadline_rules(title: str, body_text: str, attachment_text: str) -> DeadlineResult:
    candidates: list[str] = []
    for chunk in (title, body_text, attachment_text):
        if not chunk:
            continue
        candidates.extend(_extract_candidates_from_chunk(chunk))

    candidates = sorted(set(candidates))
    if not candidates:
        return DeadlineResult(deadline_iso=None, source="rules", confident=False)
    if len(candidates) == 1:
        return DeadlineResult(deadline_iso=candidates[0], source="rules", confident=True)
    return DeadlineResult(deadline_iso=min(candidates), source="rules", confident=False)


def _extract_publication_rules(title: str, body_text: str, attachment_text: str) -> PublicationResult:
    candidates: list[str] = []
    for chunk in (title, body_text, attachment_text):
        if not chunk:
            continue
        candidates.extend(_extract_publication_candidates_from_chunk(chunk))

    candidates = sorted(set(candidates))
    if not candidates:
        return PublicationResult(published_date_iso=None, source="rules", confident=False)
    if len(candidates) == 1:
        return PublicationResult(published_date_iso=candidates[0], source="rules", confident=True)
    return PublicationResult(published_date_iso=min(candidates), source="rules", confident=False)


def _extract_candidates_from_chunk(text: str) -> list[str]:
    picked: list[str] = []
    normalized = normalize_text(text)
    sentences = re.split(r"(?<=[.;\n])\s+", text)

    for sentence in sentences:
        norm_sentence = normalize_text(sentence)
        if not norm_sentence:
            continue
        if not any(hint in norm_sentence for hint in DEADLINE_HINTS):
            continue
        if any(hint in norm_sentence for hint in NON_APPLICATION_HINTS):
            continue
        picked.extend(extract_iso_dates(sentence))

    for hint in DEADLINE_HINTS:
        pattern = re.compile(
            rf"{re.escape(hint)}[^0-9a-z]{{0,80}}"
            r"(\d{1,2}[./-]\d{1,2}[./-]\d{4}|\d{1,2}\s+[a-z]+\s+\d{4})",
            re.IGNORECASE,
        )
        for match in pattern.finditer(normalized):
            parsed = parse_deadline_to_iso(match.group(1))
            if parsed:
                picked.append(parsed)

    # Conservative fallback: if no explicit deadline hints, do not guess from random dates.
    return picked


def _extract_publication_candidates_from_chunk(text: str) -> list[str]:
    picked: list[str] = []
    normalized = normalize_text(text)

    patterns = (
        r"(?:publicat|publicare|data publicarii|afisat)[^0-9]{0,60}"
        r"(\d{1,2}[./-]\d{1,2}[./-]\d{4}|\d{1,2}\s+[a-z]+\s+\d{4})",
        r"(\d{1,2}[./-]\d{1,2}[./-]\d{4}|\d{1,2}\s+[a-z]+\s+\d{4})[^a-z]{0,20}(?:publicare anunt|publicat|afisat)",
    )

    for pattern in patterns:
        for match in re.finditer(pattern, normalized, re.IGNORECASE):
            parsed = parse_deadline_to_iso(match.group(1))
            if parsed:
                picked.append(parsed)

    # Secondary fallback: if publication hints are present, use local line dates.
    for sentence in re.split(r"(?<=[.;\n])\s+", text):
        norm_sentence = normalize_text(sentence)
        if not norm_sentence:
            continue
        if any(hint in norm_sentence for hint in PUBLICATION_HINTS):
            picked.extend(extract_iso_dates(sentence))

    return picked


def _extract_dates_openai(
    settings: Settings,
    title: str,
    body_text: str,
    attachment_text: str,
) -> tuple[bool, Optional[str], Optional[str]]:
    text_payload = {
        "title": title,
        "body_excerpt": (body_text or "")[:2500],
        "attachment_excerpt": (attachment_text or "")[:2200],
    }
    payload = {
        "model": settings.openai_model,
        "temperature": 0,
        "response_format": {"type": "json_object"},
        "messages": [
            {
                "role": "system",
                "content": (
                    "Extragi data publicarii si termenul limita de depunere dosare. "
                    "Nu confunda cu interviu/rezultate/contestatii. "
                    "Raspuns strict JSON: "
                    "{\"published_date\":\"YYYY-MM-DD\"|null,\"deadline\":\"YYYY-MM-DD\"|null}."
                ),
            },
            {
                "role": "user",
                "content": (
                    "Date de analizat (JSON):\n"
                    + json.dumps(text_payload, ensure_ascii=False)
                ),
            },
        ],
    }
    headers = {
        "Authorization": f"Bearer {settings.openai_api_key}",
        "Content-Type": "application/json",
    }

    try:
        response = requests.post(
            "https://api.openai.com/v1/chat/completions",
            headers=headers,
            json=payload,
            timeout=settings.request_timeout,
        )
        response.raise_for_status()
        content = (
            response.json()
            .get("choices", [{}])[0]
            .get("message", {})
            .get("content", "{}")
        )
        data = json.loads(content)
        publication_value = data.get("published_date")
        deadline_value = data.get("deadline")
        parsed_publication = None
        parsed_deadline = None

        if publication_value is None:
            parsed_publication = None
        elif isinstance(publication_value, str):
            parsed_publication = parse_deadline_to_iso(publication_value.strip())

        if deadline_value is None:
            parsed_deadline = None
        if isinstance(deadline_value, str):
            parsed_deadline = parse_deadline_to_iso(deadline_value.strip())

        return (True, parsed_deadline, parsed_publication)
    except Exception as exc:  # noqa: BLE001
        LOGGER.warning("Dates OpenAI fallback failed title=%s error=%s", title, exc)
        return (False, None, None)

    return (False, None, None)


def resolve_expired(deadline_iso: Optional[str]) -> bool:
    if not deadline_iso:
        return False
    try:
        return date.fromisoformat(deadline_iso) < date.today()
    except ValueError:
        return False


def resolve_expired_with_publication(
    deadline_iso: Optional[str],
    published_date_iso: Optional[str],
    stale_days: int = 30,
) -> bool:
    if resolve_expired(deadline_iso):
        return True
    if deadline_iso:
        return False
    if not published_date_iso:
        return False
    try:
        published_date = date.fromisoformat(published_date_iso)
    except ValueError:
        return False
    return published_date < (date.today() - timedelta(days=stale_days))
