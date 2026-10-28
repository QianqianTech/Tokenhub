from __future__ import annotations

import os
from dataclasses import dataclass


def bool_env(name: str, default: bool = False) -> bool:
    value = os.getenv(name)
    if value is None:
        return default
    return value.lower() in {"1", "true", "yes", "on"}


@dataclass(frozen=True)
class Config:
    db_host: str = os.getenv("DB_HOST", "mysql")
    db_port: int = int(os.getenv("DB_PORT", "3306"))
    db_database: str = os.getenv("DB_DATABASE", "tokenhub")
    db_username: str = os.getenv("DB_USERNAME", "tokenhub")
    db_password: str = os.getenv("DB_PASSWORD", "tokenhub_secret")
    redis_host: str = os.getenv("REDIS_HOST", "redis")
    redis_port: int = int(os.getenv("REDIS_PORT", "6379"))
    redis_password: str = os.getenv("REDIS_PASSWORD", "")
    qdrant_url: str = os.getenv("QDRANT_URL", "http://qdrant:6333").rstrip("/")
    qdrant_api_key: str = os.getenv("QDRANT_API_KEY", "")
    qdrant_collection_prefix: str = os.getenv("QDRANT_COLLECTION_PREFIX", "tokenhub_rag")
    poll_interval_seconds: float = float(os.getenv("RAG_WORKER_POLL_INTERVAL_SECONDS", "2"))
    batch_size: int = int(os.getenv("RAG_WORKER_BATCH_SIZE", "5"))
    job_lease_seconds: int = max(300, int(os.getenv("RAG_EMBEDDING_JOB_LEASE_SECONDS", "900")))
    enable_local_fallback: bool = bool_env(
        "RAG_ALLOW_LOCAL_EMBEDDING_FALLBACK",
        bool_env("RAG_WORKER_ENABLE_LOCAL_FALLBACK", False),
    )
    storage_root: str = os.getenv("RAG_WORKER_STORAGE_ROOT", "/srv/app/storage")

    @property
    def qdrant_headers(self) -> dict[str, str]:
        headers = {"Content-Type": "application/json"}
        if self.qdrant_api_key:
            headers["api-key"] = self.qdrant_api_key
        return headers
