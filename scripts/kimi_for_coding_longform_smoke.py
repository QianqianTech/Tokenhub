#!/usr/bin/env python3
"""Run a real TokenHub long-form chat test against kimi-for-coding.

This script is intentionally standalone: it only uses the Python standard
library and calls TokenHub's OpenAI-compatible HTTP API.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any


DEFAULT_PROMPT = (
    "请写一篇约5000字的中文长文，主题是“一个中小型团队如何用统一模型网关、API Key 权限、"
    "审计日志和真实验证流程构建可靠的 AI 编程助手平台”。要求：1. 内容自然连贯，不要使用 Markdown 表格；"
    "2. 包含架构、权限、真实模型调用、故障排查、成本控制、上线验收几个部分；"
    "3. 不要编造具体产品发布新闻；4. 尽量接近5000个中文字符。"
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Real long-form smoke test for TokenHub kimi-for-coding.")
    parser.add_argument("--base-url", default=os.getenv("TOKENHUB_BASE_URL", "http://localhost:8080"))
    parser.add_argument("--api-key", default=os.getenv("TOKENHUB_API_KEY", ""))
    parser.add_argument("--model", default=os.getenv("TOKENHUB_MODEL", "kimi-for-coding"))
    parser.add_argument("--prompt", default=os.getenv("TOKENHUB_LONGFORM_PROMPT", DEFAULT_PROMPT))
    parser.add_argument("--output", default=os.getenv("TOKENHUB_LONGFORM_OUTPUT", "storage/kimi-for-coding-longform.md"))
    parser.add_argument("--meta-output", default=os.getenv("TOKENHUB_LONGFORM_META", "storage/kimi-for-coding-longform.meta.json"))
    parser.add_argument("--max-tokens", type=int, default=int(os.getenv("TOKENHUB_MAX_TOKENS", "9000")))
    parser.add_argument("--temperature", type=float, default=float(os.getenv("TOKENHUB_TEMPERATURE", "1")))
    parser.add_argument("--timeout", type=int, default=int(os.getenv("TOKENHUB_TIMEOUT_SECONDS", "300")))
    parser.add_argument("--retries", type=int, default=int(os.getenv("TOKENHUB_RETRIES", "1")))
    return parser.parse_args()


def request_json(url: str, api_key: str, payload: dict[str, Any], timeout: int) -> tuple[int, dict[str, Any], str]:
    body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {api_key}",
            "Accept": "application/json",
            "Content-Type": "application/json; charset=utf-8",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            return response.status, json.loads(raw), raw
    except urllib.error.HTTPError as error:
        raw = error.read().decode("utf-8", errors="replace")
        try:
            decoded = json.loads(raw)
        except json.JSONDecodeError:
            decoded = {}
        return error.code, decoded, raw


def content_from_response(payload: dict[str, Any]) -> str:
    choices = payload.get("choices")
    if not isinstance(choices, list) or not choices:
        return ""
    first = choices[0]
    if not isinstance(first, dict):
        return ""
    message = first.get("message")
    if isinstance(message, dict) and isinstance(message.get("content"), str):
        return message["content"]
    return ""


def finish_reason(payload: dict[str, Any]) -> str | None:
    choices = payload.get("choices")
    if isinstance(choices, list) and choices and isinstance(choices[0], dict):
        reason = choices[0].get("finish_reason")
        return str(reason) if reason is not None else None
    return None


def write_text(path: str, value: str) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(value, encoding="utf-8")


def main() -> int:
    args = parse_args()
    if not args.api_key:
        print("TOKENHUB_API_KEY or --api-key is required.", file=sys.stderr)
        return 2

    endpoint = args.base_url.rstrip("/") + "/v1/chat/completions"
    payload = {
        "model": args.model,
        "messages": [
            {
                "role": "system",
                "content": (
                    "You are a coding and architecture writing assistant. "
                    "For this test, produce long Chinese prose and avoid markdown tables."
                ),
            },
            {"role": "user", "content": args.prompt},
        ],
        "max_tokens": args.max_tokens,
        "temperature": args.temperature,
    }

    started = time.time()
    last_status = 0
    last_payload: dict[str, Any] = {}
    last_raw = ""
    attempts = max(1, args.retries)
    for attempt in range(1, attempts + 1):
        last_status, last_payload, last_raw = request_json(endpoint, args.api_key, payload, args.timeout)
        if last_status == 200:
            break
        if attempt < attempts:
            time.sleep(min(2 * attempt, 10))

    duration_ms = int((time.time() - started) * 1000)
    content = content_from_response(last_payload)
    metadata = {
        "ok": last_status == 200,
        "status": last_status,
        "model": args.model,
        "base_url": args.base_url,
        "duration_ms": duration_ms,
        "finish_reason": finish_reason(last_payload),
        "content_chars": len(content),
        "content_bytes": len(content.encode("utf-8")),
        "usage": last_payload.get("usage"),
        "request_id": last_payload.get("id") or last_payload.get("request_id"),
        "output": args.output,
        "meta_output": args.meta_output,
    }

    write_text(args.output, content)
    write_text(args.meta_output, json.dumps(metadata, ensure_ascii=False, indent=2) + "\n")

    print(json.dumps(metadata, ensure_ascii=False, separators=(",", ":")))
    if last_status != 200:
        print(last_raw, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
