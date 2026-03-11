from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass
from typing import Dict, List, Tuple

import requests

from crawlers.common.config import Settings
from crawlers.common.utils import normalize_text

LOGGER = logging.getLogger(__name__)

JOB_CATEGORIES: List[str] = [
    "IT",
    "Juridic",
    "Administrativ",
    "Economic / Financiar",
    "Comunicare / PR",
    "Resurse Umane",
    "Audit / Control / Conformitate",
    "Tehnic / Inginerie",
    "Achizi\u021bii / Proiecte",
    "Altele",
]

RULE_KEYWORDS: Dict[str, Tuple[str, ...]] = {
    "IT": (
        "it",
        "tehnologia informatiei",
        "software",
        "developer",
        "programator",
        "baze de date",
        "database",
        "sysadmin",
        "retea",
        "network",
        "cyber",
        "securitate cibernetica",
        "devops",
        "cloud",
        "data engineer",
        "analist date",
    ),
    "Juridic": (
        "juridic",
        "jurist",
        "consilier juridic",
        "drept",
        "contencios",
        "legal",
        "avizare",
        "normativ",
    ),
    "Administrativ": (
        "administrativ",
        "secretariat",
        "registratura",
        "arhiva",
        "protocol",
        "suport administrativ",
        "office manager",
        "asistent administrativ",
    ),
    "Economic / Financiar": (
        "economic",
        "financiar",
        "contabil",
        "contabilitate",
        "buget",
        "trezorerie",
        "control financiar",
        "fiscal",
        "analist financiar",
        "audit financiar",
    ),
    "Comunicare / PR": (
        "comunicare",
        "relatii publice",
        "pr",
        "presa",
        "social media",
        "campanie",
        "comunicat",
        "marketing",
    ),
    "Resurse Umane": (
        "resurse umane",
        "hr",
        "recrutare",
        "personal",
        "salarizare",
        "training",
        "dezvoltare organizationala",
    ),
    "Audit / Control / Conformitate": (
        "audit",
        "control",
        "conformitate",
        "compliance",
        "risc",
        "risk",
        "inspectie",
        "verificare",
    ),
    "Tehnic / Inginerie": (
        "inginer",
        "tehnic",
        "mecanic",
        "electric",
        "electro",
        "constructii",
        "mentenanta",
        "automatizari",
        "instalatii",
    ),
    "Achizi\u021bii / Proiecte": (
        "achizitii",
        "licitatii",
        "proceduri de achizitie",
        "proiect",
        "project",
        "fonduri",
        "implementare",
        "management de proiect",
    ),
}


@dataclass(frozen=True)
class ClassificationResult:
    category: str
    source: str
    scores: Dict[str, int]
    ambiguous: bool


def classify_job(
    settings: Settings,
    title: str,
    department: str,
    details: str,
) -> ClassificationResult:
    rules_result = _classify_with_rules(title=title, department=department, details=details)
    if not rules_result.ambiguous:
        return rules_result

    if not settings.openai_api_key:
        LOGGER.info(
            "Classifier fallback skipped (no OPENAI_API_KEY). category=%s title=%s",
            rules_result.category,
            title,
        )
        return rules_result

    ai_category = _classify_with_openai(
        settings=settings,
        title=title,
        department=department,
        details=details,
    )
    if not ai_category:
        return rules_result

    return ClassificationResult(
        category=ai_category,
        source="openai",
        scores=rules_result.scores,
        ambiguous=True,
    )


def _classify_with_rules(title: str, department: str, details: str) -> ClassificationResult:
    title_text = normalize_text(title)
    dept_text = normalize_text(department)
    details_text = normalize_text(details)

    scores: Dict[str, int] = {category: 0 for category in JOB_CATEGORIES}

    for category, keywords in RULE_KEYWORDS.items():
        score = 0
        for keyword in keywords:
            normalized_keyword = normalize_text(keyword)
            if not normalized_keyword:
                continue
            if _contains_keyword(title_text, normalized_keyword):
                score += 4
            if _contains_keyword(dept_text, normalized_keyword):
                score += 3
            if _contains_keyword(details_text, normalized_keyword):
                score += 1
        scores[category] = score

    ranked = sorted(scores.items(), key=lambda item: item[1], reverse=True)
    best_category, best_score = ranked[0]
    second_score = ranked[1][1] if len(ranked) > 1 else 0
    ambiguous = best_score == 0 or best_score - second_score <= 1

    if ambiguous:
        # Keep deterministic safe default for publish flow.
        if best_score == 0:
            best_category = "Altele"

    return ClassificationResult(
        category=best_category,
        source="rules",
        scores=scores,
        ambiguous=ambiguous,
    )


def _contains_keyword(text: str, keyword: str) -> bool:
    if " " in keyword:
        return keyword in text
    pattern = re.compile(rf"\b{re.escape(keyword)}\b", re.IGNORECASE)
    return bool(pattern.search(text))


def _classify_with_openai(
    settings: Settings,
    title: str,
    department: str,
    details: str,
) -> str:
    truncated_details = (details or "")[:1400]
    payload = {
        "model": settings.openai_model,
        "temperature": 0,
        "response_format": {"type": "json_object"},
        "messages": [
            {
                "role": "system",
                "content": (
                    "Clasifici anunturi de job din sectorul public din Romania. "
                    "Raspunzi strict JSON: {\"category\":\"...\"}. "
                    "Categoria trebuie sa fie exact una din lista data."
                ),
            },
            {
                "role": "user",
                "content": (
                    "Categorii permise: "
                    + ", ".join(JOB_CATEGORIES)
                    + "\n"
                    + f"Titlu: {title}\n"
                    + f"Departament: {department}\n"
                    + f"Detalii: {truncated_details}\n"
                    + "Alege cea mai potrivita categorie."
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
        result = json.loads(content)
        raw_category = str(result.get("category", "")).strip()
        category = _canonical_category(raw_category)
        if category:
            LOGGER.info("Classifier fallback used OpenAI category=%s title=%s", category, title)
            return category
        LOGGER.warning(
            "Classifier fallback returned invalid category=%s title=%s",
            raw_category,
            title,
        )
    except Exception as exc:  # noqa: BLE001
        LOGGER.warning("Classifier OpenAI fallback failed for title=%s error=%s", title, exc)

    return ""


def _canonical_category(raw_value: str) -> str:
    if not raw_value:
        return ""
    normalized_map = {normalize_text(category): category for category in JOB_CATEGORIES}
    return normalized_map.get(normalize_text(raw_value), "")
