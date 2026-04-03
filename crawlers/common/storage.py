from __future__ import annotations

import sqlite3
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional


@dataclass
class SeenEntry:
    hash_value: str
    source: str
    source_url: str
    wp_post_id: Optional[int]
    last_seen_at: str


class SeenStorage:
    def __init__(self, db_path: Path) -> None:
        self.db_path = db_path
        self.db_path.parent.mkdir(parents=True, exist_ok=True)
        self._conn = sqlite3.connect(str(self.db_path))
        self._conn.row_factory = sqlite3.Row
        self._ensure_schema()

    def _ensure_schema(self) -> None:
        self._conn.execute(
            """
            CREATE TABLE IF NOT EXISTS seen (
                hash TEXT PRIMARY KEY,
                source TEXT NOT NULL,
                source_url TEXT NOT NULL,
                wp_post_id INTEGER,
                last_seen_at TEXT NOT NULL
            )
            """
        )
        self._conn.commit()

    def has_hash(self, hash_value: str) -> bool:
        row = self._conn.execute(
            "SELECT hash FROM seen WHERE hash = ? LIMIT 1", (hash_value,)
        ).fetchone()
        return row is not None

    def has_source_url(self, source_url: str) -> bool:
        row = self._conn.execute(
            "SELECT source_url FROM seen WHERE source_url = ? LIMIT 1", (source_url,)
        ).fetchone()
        return row is not None

    def get_wp_post_id_by_source_url(self, source_url: str) -> Optional[int]:
        row = self._conn.execute(
            "SELECT wp_post_id FROM seen WHERE source_url = ? LIMIT 1", (source_url,)
        ).fetchone()
        if row is None:
            return None
        value = row["wp_post_id"]
        return int(value) if value is not None else None

    def upsert(
        self,
        hash_value: str,
        source: str,
        source_url: str,
        wp_post_id: Optional[int],
    ) -> None:
        now_iso = datetime.now(timezone.utc).isoformat()
        existing_by_url = self._conn.execute(
            "SELECT hash FROM seen WHERE source_url = ? LIMIT 1", (source_url,)
        ).fetchone()
        if existing_by_url is not None:
            self._conn.execute(
                """
                UPDATE seen
                SET hash = ?, source = ?, wp_post_id = ?, last_seen_at = ?
                WHERE source_url = ?
                """,
                (hash_value, source, wp_post_id, now_iso, source_url),
            )
            self._conn.commit()
            return

        self._conn.execute(
            """
            INSERT INTO seen (hash, source, source_url, wp_post_id, last_seen_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(hash) DO UPDATE SET
                source=excluded.source,
                source_url=excluded.source_url,
                wp_post_id=excluded.wp_post_id,
                last_seen_at=excluded.last_seen_at
            """,
            (hash_value, source, source_url, wp_post_id, now_iso),
        )
        self._conn.commit()

    def close(self) -> None:
        self._conn.close()
