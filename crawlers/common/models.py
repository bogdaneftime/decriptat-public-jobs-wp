from __future__ import annotations

from dataclasses import dataclass
from typing import Optional


@dataclass
class AttachmentRecord:
    label: str
    url: str


@dataclass
class JobRecord:
    source: str
    title: str
    details_url: str
    department: str = ""
    location: str = ""
    institution_name: str = "Banca Na\u021bional\u0103 a Rom\u00e2niei"
    number_of_positions: Optional[int] = None
    published_date_iso: Optional[str] = None
    deadline_iso: Optional[str] = None
    details_text: str = ""
    pdf_url: str = ""
    attachments: list[AttachmentRecord] = None
    job_category: str = "Altele"
    is_it: bool = False
    expired: bool = False

    def __post_init__(self) -> None:
        if self.attachments is None:
            self.attachments = []
