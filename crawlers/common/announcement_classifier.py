from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass
from typing import Literal

import requests

from crawlers.common.config import Settings
from crawlers.common.utils import normalize_text

LOGGER = logging.getLogger(__name__)

AnnouncementLabel = Literal["new_contest", "results_or_followup", "not_relevant"]


@dataclass(frozen=True)
class AnnouncementDecision:
    label: AnnouncementLabel
    source: str
    confident: bool


STRONG_INCLUDE = (
    "anunt privind organizarea concursului",
    "concurs de recrutare",
    "ocuparii unui post",
    "ocuparea unui post",
    "ocuparea unei functii",
    "selectie in vederea ocuparii",
    "selectie in vederea ocuparii unui post",
    "examen de promovare",
    "recrutare",
    "organizarea concursului",
)

STRONG_EXCLUDE = (
    "rezultat",
    "rezultate",
    "rezultatul contesta",
    "contestatii",
    "contestatiilor",
    "rezultate finale",
    "rezultat final",
    "proba scrisa",
    "interviu",
    "erata",
    "corrigendum",
    "clarificari",
)

FOLLOWUP_HINTS = (
    "selectia dosarelor",
    "rezultatul selectiei dosarelor",
    "afisare rezultate",
    "dupa contestatii",
)


def classify_announcement(
    settings: Settings,
    title: str,
    publication_date_iso: str | None,
    body_excerpt: str,
    attachment_labels: str,
) -> AnnouncementDecision:
    rules = _classify_rules(title=title, body_excerpt=body_excerpt, attachment_labels=attachment_labels)
    if rules.confident:
        return rules

    if not settings.openai_api_key:
        # Conservative behavior requested: if uncertain, do not publish.
        return AnnouncementDecision(label="not_relevant", source="rules", confident=False)

    ai_label = _classify_openai(
        settings=settings,
        title=title,
        publication_date_iso=publication_date_iso,
        body_excerpt=body_excerpt,
        attachment_labels=attachment_labels,
    )
    if ai_label:
        return AnnouncementDecision(label=ai_label, source="openai", confident=True)
    return AnnouncementDecision(label="not_relevant", source="rules", confident=False)


def _classify_rules(title: str, body_excerpt: str, attachment_labels: str) -> AnnouncementDecision:
    normalized_title = normalize_text(title)
    combined = normalize_text(f"{title} {body_excerpt} {attachment_labels}")

    include_hits = _count_hits(combined, STRONG_INCLUDE) + _count_hits(normalized_title, STRONG_INCLUDE)
    exclude_hits = _count_hits(combined, STRONG_EXCLUDE) + _count_hits(normalized_title, STRONG_EXCLUDE)
    followup_hits = _count_hits(combined, FOLLOWUP_HINTS)

    if exclude_hits >= 2 or followup_hits >= 1:
        return AnnouncementDecision(label="results_or_followup", source="rules", confident=True)
    if include_hits >= 2 and exclude_hits == 0:
        return AnnouncementDecision(label="new_contest", source="rules", confident=True)
    if include_hits == 0 and exclude_hits == 0:
        return AnnouncementDecision(label="not_relevant", source="rules", confident=True)
    return AnnouncementDecision(label="not_relevant", source="rules", confident=False)


def _count_hits(text: str, phrases: tuple[str, ...]) -> int:
    score = 0
    for phrase in phrases:
        p = normalize_text(phrase)
        if not p:
            continue
        if " " in p:
            if p in text:
                score += 1
        else:
            if re.search(rf"\b{re.escape(p)}\b", text, re.IGNORECASE):
                score += 1
    return score


def _classify_openai(
    settings: Settings,
    title: str,
    publication_date_iso: str | None,
    body_excerpt: str,
    attachment_labels: str,
) -> AnnouncementLabel | None:
    payload = {
        "model": settings.openai_model,
        "temperature": 0,
        "response_format": {"type": "json_object"},
        "messages": [
            {
                "role": "system",
                "content": (
                    "Clasifici anunturi ADR. Raspunzi strict JSON: "
                    '{"label":"new_contest|results_or_followup|not_relevant"}'
                ),
            },
            {
                "role": "user",
                "content": (
                    f"Titlu: {title}\n"
                    f"Data publicare: {publication_date_iso or 'necunoscuta'}\n"
                    f"Continut: {(body_excerpt or '')[:1800]}\n"
                    f"Etichete atasamente: {(attachment_labels or '')[:500]}\n"
                    "Alege exact un label din: new_contest, results_or_followup, not_relevant."
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
        label = str(data.get("label", "")).strip()
        if label in ("new_contest", "results_or_followup", "not_relevant"):
            return label  # type: ignore[return-value]
    except Exception as exc:  # noqa: BLE001
        LOGGER.warning("Announcement OpenAI fallback failed title=%s error=%s", title, exc)

    return None
