from __future__ import annotations

from dataclasses import dataclass
from typing import Optional


@dataclass
class JobRecord:
    source: str
    title: str
    details_url: str
    department: str = ""
    location: str = ""
    number_of_positions: Optional[int] = None
    deadline_iso: Optional[str] = None
    details_text: str = ""
    pdf_url: str = ""
    is_it: bool = False
    expired: bool = False
