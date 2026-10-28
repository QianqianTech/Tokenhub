#!/usr/bin/env python3
"""Verify a TokenHub API key and its authorized chat-model sessions.

The API key must be supplied through TOKENHUB_API_KEY or --api-key. It is never
written to disk or included in command output.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


DEFAULT_BASE_URL = "http://localhost:8080"
DEFAULT_PROMPT = "TokenHub API-key session test. Reply exactly: TOKENHUB_SESSION_OK"
DEFAULT_MAX_TOKENS = 1024


def select_chat_models(
    models: list[dict[str, Any]], requested_models: list[str] | None = None
) -> list[str]:
    authorized = {
        model_id: str(model.get("capability") or "text_generation")
        for model in models
        if (model_id := str(model.get("name") or model.get("id") or ""))
    }
    if requested_models:
        missing = [model for model in requested_models if model not in authorized]
        if missing:
            raise ValueError(f"Requested model is not authorized: {', '.join(missing)}")
        unsupported = [
            model for model in requested_models if authorized[model] != "text_generation"
        ]
        if unsupported:
            raise ValueError(
                "Requested model does not support text chat testing: "
                + ", ".join(unsupported)
            )
        return requested_models

    return [
        model_id
        for model in models
        if (model_id := str(model.get("name") or model.get("id") or ""))
        and str(model.get("capability") or "text_generation") == "text_generation"
    ]


def request_json(
    base_url: str,
    api_key: str,
    method: str,
    path: str,
    payload: dict[str, Any] | None,
    timeout: float,
) -> tuple[int, dict[str, Any]]:
    body = None if payload is None else json.dumps(payload).encode("utf-8")
    request = Request(
        f"{base_url.rstrip('/')}{path}",
        data=body,
        method=method,
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8")
            return response.status, json.loads(raw) if raw else {}
    except HTTPError as error:
        raw = error.read().decode("utf-8", errors="replace")
        try:
            data = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            data = {"error": {"message": raw}}
        return error.code, data
    except URLError as error:
        raise RuntimeError(f"Cannot reach TokenHub: {error.reason}") from error


def unwrap(payload: dict[str, Any]) -> dict[str, Any]:
    data = payload.get("data", payload)
    if not isinstance(data, dict):
        raise ValueError("TokenHub returned an unexpected JSON payload")
    return data


def model_list(payload: dict[str, Any]) -> list[dict[str, Any]]:
    data = payload.get("data")
    if isinstance(data, list):
        return [model for model in data if isinstance(model, dict)]

    unwrapped = unwrap(payload)
    models = unwrapped.get("models", [])
    if isinstance(models, list):
        return [model for model in models if isinstance(model, dict)]
    return []


def identity_username(identity: dict[str, Any]) -> str:
    user = identity.get("user")
    if isinstance(user, dict) and user.get("username"):
        return str(user["username"])
    return str(identity.get("username") or "unknown")


def assistant_text(payload: dict[str, Any]) -> str:
    choices = payload.get("choices")
    if isinstance(choices, list) and choices and isinstance(choices[0], dict):
        message = choices[0].get("message")
        if isinstance(message, dict) and isinstance(message.get("content"), str):
            return message["content"].strip()

    blocks = payload.get("content")
    if isinstance(blocks, list):
        return "\n".join(
            str(block["text"]).strip()
            for block in blocks
            if isinstance(block, dict) and block.get("type") == "text" and block.get("text")
        ).strip()
    return ""


def error_message(payload: dict[str, Any]) -> str:
    error = payload.get("error", {})
    if isinstance(error, dict):
        return str(error.get("message") or error.get("code") or "Unknown error")
    return str(error or "Unknown error")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--base-url",
        default=os.getenv("TOKENHUB_BASE_URL", DEFAULT_BASE_URL),
        help=f"TokenHub base URL (default: {DEFAULT_BASE_URL})",
    )
    parser.add_argument(
        "--api-key",
        default=os.getenv("TOKENHUB_API_KEY"),
        help="API key; prefer TOKENHUB_API_KEY so it is not retained in shell history",
    )
    parser.add_argument(
        "--model",
        action="append",
        dest="models",
        help="Authorized text model to test; repeat to limit the model set",
    )
    parser.add_argument(
        "--max-tokens",
        type=int,
        default=DEFAULT_MAX_TOKENS,
        help=f"Maximum completion tokens per model call (default: {DEFAULT_MAX_TOKENS})",
    )
    parser.add_argument("--timeout", type=float, default=120.0, help="Per-request timeout in seconds")
    args = parser.parse_args()

    if not args.api_key:
        parser.error("TOKENHUB_API_KEY or --api-key is required")
    if args.max_tokens < 1:
        parser.error("--max-tokens must be at least 1")

    status, me_payload = request_json(
        args.base_url, args.api_key, "GET", "/v1/me", None, args.timeout
    )
    if status != 200:
        print(f"API key validation failed: HTTP {status}: {error_message(me_payload)}", file=sys.stderr)
        return 2

    identity = unwrap(me_payload)
    print(f"API key validated for user={identity_username(identity)}")

    status, models_payload = request_json(
        args.base_url, args.api_key, "GET", "/v1/models", None, args.timeout
    )
    if status != 200:
        print(f"Model discovery failed: HTTP {status}: {error_message(models_payload)}", file=sys.stderr)
        return 3

    models = model_list(models_payload)
    if not isinstance(models, list):
        print("Model discovery returned an invalid model list", file=sys.stderr)
        return 3
    try:
        chat_models = select_chat_models(models, args.models)
    except ValueError as error:
        print(str(error), file=sys.stderr)
        return 3
    if not chat_models:
        print("No authorized text-generation model is available for chat testing", file=sys.stderr)
        return 3

    results: list[dict[str, Any]] = []
    for model in chat_models:
        started = time.monotonic()
        try:
            status, payload = request_json(
                args.base_url,
                args.api_key,
                "POST",
                "/v1/chat/completions",
                {
                    "model": model,
                    "messages": [{"role": "user", "content": DEFAULT_PROMPT}],
                    "temperature": 0,
                    "max_tokens": args.max_tokens,
                    "stream": False,
                },
                args.timeout,
            )
            elapsed_ms = round((time.monotonic() - started) * 1000)
            if 200 <= status < 300:
                text = assistant_text(payload)
                if text:
                    results.append({"model": model, "status": "passed", "http_status": status, "latency_ms": elapsed_ms, "response_chars": len(text)})
                    print(f"PASS  {model} ({elapsed_ms} ms, {len(text)} response chars)")
                else:
                    results.append({"model": model, "status": "failed", "http_status": status, "message": "Model response contained no assistant text", "latency_ms": elapsed_ms})
                    print(f"FAIL  {model}: HTTP {status}: Model response contained no assistant text", file=sys.stderr)
            else:
                results.append({"model": model, "status": "failed", "http_status": status, "message": error_message(payload), "latency_ms": elapsed_ms})
                print(f"FAIL  {model}: HTTP {status}: {error_message(payload)}", file=sys.stderr)
        except RuntimeError as error:
            elapsed_ms = round((time.monotonic() - started) * 1000)
            results.append({"model": model, "status": "failed", "message": str(error), "latency_ms": elapsed_ms})
            print(f"FAIL  {model}: {error}", file=sys.stderr)

    passed = sum(result["status"] == "passed" for result in results)
    print(json.dumps({"passed": passed, "failed": len(results) - passed, "results": results}, ensure_ascii=False))
    return 0 if passed == len(results) else 4


if __name__ == "__main__":
    raise SystemExit(main())
