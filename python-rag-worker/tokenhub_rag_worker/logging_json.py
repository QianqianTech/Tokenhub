"""Structured stdout logging for the RAG worker (BIZ-023, BIZ-008).

One JSON object per line on stdout, the same shape as the PHP JsonLogger:
timestamp, level, message, request_id, trace_id, channel, context. Docker
and log shippers read stdout; secrets in the context are masked.
"""
from __future__ import annotations

import json
import re
import sys
import threading
import time
from contextlib import contextmanager
from typing import Any, Iterator

_SENSITIVE_KEY = re.compile(r"authorization|cookie|api[_-]?key|key_hash|password|passwd|secret|token|connection", re.I)
_BEARER = re.compile(r"(Bearer\s+)[A-Za-z0-9._~+\-/]+=*", re.I)
_DSN = re.compile(r"\b(?:mysql|postgres|postgresql|redis)://[^\s\"']+", re.I)

_state = threading.local()


def _ids() -> dict[str, Any]:
    return {
        "request_id": getattr(_state, "request_id", None),
        "trace_id": getattr(_state, "trace_id", None),
    }


@contextmanager
def correlation(request_id: str | None, trace_id: str | None = None) -> Iterator[None]:
    """Log lines written inside the block carry these ids (one RAG job)."""
    previous = _ids()
    _state.request_id = request_id
    _state.trace_id = trace_id
    try:
        yield
    finally:
        _state.request_id = previous["request_id"]
        _state.trace_id = previous["trace_id"]


def sanitize(value: Any, key: str = "") -> Any:
    if key and _SENSITIVE_KEY.search(key):
        return "****"
    if isinstance(value, dict):
        return {str(k): sanitize(v, str(k)) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [sanitize(v) for v in value]
    if isinstance(value, str):
        return _DSN.sub("connection=****", _BEARER.sub(r"\1****", value))[:2000]
    return value


def record(level: str, message: str, **context: Any) -> dict[str, Any]:
    ids = _ids()
    return {
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime()),
        "level": level,
        "message": sanitize(message),
        "request_id": context.pop("request_id", None) or ids["request_id"],
        "trace_id": context.pop("trace_id", None) or ids["trace_id"],
        "channel": "rag-worker",
        "context": sanitize(context),
    }


def log(level: str, message: str, **context: Any) -> None:
    line = json.dumps(record(level, message, **context), ensure_ascii=False, default=str)
    try:
        sys.stdout.write(line + "\n")
        sys.stdout.flush()
    except (OSError, ValueError):
        pass


def info(message: str, **context: Any) -> None:
    log("info", message, **context)


def warning(message: str, **context: Any) -> None:
    log("warning", message, **context)


def error(message: str, **context: Any) -> None:
    log("error", message, **context)
