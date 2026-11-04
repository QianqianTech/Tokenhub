"""Exception -> error code mapping for failed RAG jobs (BIZ-023).

Before, every failure of a text job was stored as RAG_MODEL_NOT_CONFIGURED,
so a Qdrant outage, a missing chunk or a database error all looked like a
configuration problem in the admin console.
"""
from __future__ import annotations

from typing import Any

RAG_MODEL_NOT_CONFIGURED = "RAG_MODEL_NOT_CONFIGURED"
RAG_IMAGE_EMBEDDING_FAILED = "RAG_IMAGE_EMBEDDING_FAILED"
RAG_MULTIMODAL_EMBEDDING_FAILED = "RAG_MULTIMODAL_EMBEDDING_FAILED"
RAG_OCR_UNAVAILABLE = "RAG_OCR_UNAVAILABLE"
RAG_DOCUMENT_EMPTY = "RAG_DOCUMENT_EMPTY"
RAG_SOURCE_NOT_FOUND = "RAG_SOURCE_NOT_FOUND"
RAG_FILE_NOT_FOUND = "RAG_FILE_NOT_FOUND"
RAG_IMAGE_INVALID = "RAG_IMAGE_INVALID"
RAG_JOB_TYPE_UNSUPPORTED = "RAG_JOB_TYPE_UNSUPPORTED"
RAG_VECTOR_STORE_TIMEOUT = "RAG_VECTOR_STORE_TIMEOUT"
RAG_VECTOR_STORE_UNAVAILABLE = "RAG_VECTOR_STORE_UNAVAILABLE"
RAG_VECTOR_STORE_REJECTED = "RAG_VECTOR_STORE_REJECTED"
RAG_DATABASE_ERROR = "RAG_DATABASE_ERROR"
RAG_JOB_FAILED = "RAG_JOB_FAILED"

# Codes a task raises explicitly as "CODE" or "CODE: detail".
_EXPLICIT = (
    RAG_MODEL_NOT_CONFIGURED,
    RAG_OCR_UNAVAILABLE,
    RAG_DOCUMENT_EMPTY,
    RAG_VECTOR_STORE_REJECTED,
    RAG_IMAGE_INVALID,
)


class VectorStoreError(RuntimeError):
    """Qdrant answered with an error status."""


def error_code_for(job: dict[str, Any], exc: BaseException | None = None) -> str:
    if exc is not None:
        code = classify(exc)
        if code is not None:
            return code
    job_type = str(job.get("job_type") or "")
    if job_type == "image_embedding":
        return RAG_IMAGE_EMBEDDING_FAILED
    if job_type == "multimodal_embedding":
        return RAG_MULTIMODAL_EMBEDDING_FAILED
    return RAG_JOB_FAILED


def classify(exc: BaseException) -> str | None:
    message = str(exc)
    for code in _EXPLICIT:
        if message == code or message.startswith(code + ":"):
            return code
    if isinstance(exc, VectorStoreError):
        return RAG_VECTOR_STORE_REJECTED
    try:
        import requests

        if isinstance(exc, requests.Timeout):
            return RAG_VECTOR_STORE_TIMEOUT
        if isinstance(exc, requests.ConnectionError):
            return RAG_VECTOR_STORE_UNAVAILABLE
        if isinstance(exc, requests.RequestException):
            return RAG_VECTOR_STORE_REJECTED
    except ImportError:  # pragma: no cover - requests is a hard dependency
        pass
    try:
        import mysql.connector

        mysql_error = getattr(mysql.connector, "Error", None)
        if isinstance(mysql_error, type) and isinstance(exc, mysql_error):
            return RAG_DATABASE_ERROR
    except ImportError:
        pass
    try:
        from PIL import UnidentifiedImageError

        if isinstance(exc, UnidentifiedImageError):
            return RAG_IMAGE_INVALID
    except ImportError:
        pass
    lowered = message.lower()
    if "unsupported rag job type" in lowered or "caption jobs are handled" in lowered:
        return RAG_JOB_TYPE_UNSUPPORTED
    if "file not found" in lowered:
        return RAG_FILE_NOT_FOUND
    if "row not found" in lowered or "not configured for image job" in lowered:
        return RAG_SOURCE_NOT_FOUND
    if isinstance(exc, FileNotFoundError):
        return RAG_FILE_NOT_FOUND

    return None
