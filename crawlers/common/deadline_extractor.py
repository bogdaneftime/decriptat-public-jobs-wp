from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass
from datetime import date
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


def extract_application_deadline(
    settings: Settings,
    title: str,
    body_text: str,
    attachment_text: str = "",
    fallback_deadline_iso: Optional[str] = None,
) -> DeadlineResult:
    rules_result = _extract_deadline_rules(title=title, body_text=body_text, attachment_text=attachment_text)
    if rules_result.confident:
        return rules_result

    if settings.openai_api_key:
        ai_success, ai_deadline = _extract_deadline_openai(
            settings=settings,
            title=title,
            body_text=body_text,
            attachment_text=attachment_text,
        )
        if ai_success and ai_deadline:
            return DeadlineResult(deadline_iso=ai_deadline, source="openai", confident=True)
        if ai_success:
            return DeadlineResult(deadline_iso=None, source="openai", confident=True)

    if fallback_deadline_iso:
        return DeadlineResult(deadline_iso=fallback_deadline_iso, source="fallback", confident=False)
    return DeadlineResult(deadline_iso=rules_result.deadline_iso, source="rules", confident=False)


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


def _extract_deadline_openai(
    settings: Settings,
    title: str,
    body_text: str,
    attachment_text: str,
) -> tuple[bool, Optional[str]]:
    payload = {
        "model": settings.openai_model,
        "temperature": 0,
        "response_format": {"type": "json_object"},
        "messages": [
            {
                "role": "system",
                "content": (
                    "Extragi doar termenul limita pentru depunerea dosarelor. "
                    "Nu returna date de interviu/rezultate/contestatii. "
                    "Raspunzi strict JSON: {\"deadline\":\"YYYY-MM-DD\"} sau {\"deadline\":null}."
                ),
            },
            {
                "role": "user",
                "content": (
                    f"Titlu: {title}\n"
                    f"Continut: {(body_text or '')[:2200]}\n"
                    f"Text atasamente: {(attachment_text or '')[:1800]}\n"
                    "Returneaza doar termenul de depunere dosare."
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
        deadline_value = data.get("deadline")
        if deadline_value is None:
            return (True, None)
        if isinstance(deadline_value, str):
            parsed = parse_deadline_to_iso(deadline_value.strip())
            if parsed:
                return (True, parsed)
    except Exception as exc:  # noqa: BLE001
        LOGGER.warning("Deadline OpenAI fallback failed title=%s error=%s", title, exc)
        return (False, None)

    return (False, None)


def resolve_expired(deadline_iso: Optional[str]) -> bool:
    if not deadline_iso:
        return False
    try:
        return date.fromisoformat(deadline_iso) < date.today()
    except ValueError:
        return False
