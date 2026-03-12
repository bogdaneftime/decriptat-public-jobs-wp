from __future__ import annotations

import logging
import re
from io import BytesIO

import requests
from docx import Document
from pypdf import PdfReader

from crawlers.common.config import Settings

LOGGER = logging.getLogger(__name__)


def extract_document_text(settings: Settings, document_url: str) -> str:
    lower_url = (document_url or "").lower()
    if not lower_url:
        return ""

    try:
        response = requests.get(
            document_url,
            timeout=settings.request_timeout,
            headers={"User-Agent": settings.user_agent},
        )
        response.raise_for_status()
        payload = response.content
    except Exception as exc:  # noqa: BLE001
        LOGGER.warning("Document download failed url=%s error=%s", document_url, exc)
        return ""

    try:
        if ".pdf" in lower_url:
            return _extract_pdf_text(payload)
        if ".docx" in lower_url:
            return _extract_docx_text(payload)
        if lower_url.endswith(".doc") or ".doc?" in lower_url:
            return _extract_doc_text(payload)
    except Exception as exc:  # noqa: BLE001
        LOGGER.warning("Document parse failed url=%s error=%s", document_url, exc)
        return ""

    return ""


def _extract_pdf_text(payload: bytes) -> str:
    reader = PdfReader(BytesIO(payload))
    chunks: list[str] = []
    for page in reader.pages:
        text = page.extract_text() or ""
        if text:
            chunks.append(text)
    return " ".join(chunks).strip()


def _extract_docx_text(payload: bytes) -> str:
    doc = Document(BytesIO(payload))
    chunks = [paragraph.text.strip() for paragraph in doc.paragraphs if paragraph.text.strip()]
    return " ".join(chunks).strip()


def _extract_doc_text(payload: bytes) -> str:
    # Lightweight fallback for legacy .doc (binary) files: extract printable chunks.
    latin_text = payload.decode("latin-1", errors="ignore")
    utf16_text = payload.decode("utf-16le", errors="ignore")
    merged = f"{latin_text}\n{utf16_text}"
    chunks = re.findall(r"[A-Za-z0-9\u0102\u0103\u00C2\u00E2\u00CE\u00EE\u0218\u0219\u021A\u021B ,.;:()\-_/]{12,}", merged)
    text = " ".join(chunks)
    text = re.sub(r"\s+", " ", text).strip()
    return text
