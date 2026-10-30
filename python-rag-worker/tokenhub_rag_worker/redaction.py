"""Optional masking of personal data before indexing (RAG-022).

Mirror of app/Services/RagRedactionService.php: the PHP upload resolves the
types into ``rag_uploads.metadata.redaction_types``; the worker applies the
same rules to documents it parses itself (scanned/OCR PDFs).
"""

from __future__ import annotations

import re
from typing import Iterable

TYPES = ("phone", "email", "id_card", "bank_card", "ipv4")
ORDER = ("email", "id_card", "bank_card", "phone", "ipv4")

PATTERNS = {
    "phone": re.compile(r"(?<![\d*])(?:\+?86[ -]?)?1[3-9]\d(?:[ -]?\d{4}){2}(?![\d*])", re.ASCII),
    "email": re.compile(r"(?<![A-Za-z0-9_.%+-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}", re.ASCII),
    "id_card": re.compile(r"(?<![0-9A-Za-z*])[1-9]\d{5}(?:18|19|20)\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])\d{3}[\dXx](?![0-9A-Za-z*])", re.ASCII),
    "bank_card": re.compile(r"(?<![\d*])\d{4}(?:[ -]?\d{4}){2,3}(?:[ -]?\d{1,3})?(?![\d*])", re.ASCII),
    "ipv4": re.compile(r"(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?![\d.])", re.ASCII),
}

ID_WEIGHTS = (7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2)


def normalize_types(value: object) -> list[str]:
    if not value:
        return []
    if isinstance(value, str):
        value = value.split(",")
    wanted = {str(item).strip().lower() for item in value if str(item).strip()}  # type: ignore[union-attr]
    return [item for item in TYPES if item in wanted]


def valid_id_card(value: str) -> bool:
    total = sum(int(value[i]) * ID_WEIGHTS[i] for i in range(17))
    return "10X98765432"[total % 11] == value[17].upper()


def luhn(digits: str) -> bool:
    total = 0
    for index, char in enumerate(reversed(digits)):
        digit = int(char)
        if index % 2 == 1:
            digit *= 2
            if digit > 9:
                digit -= 9
        total += digit
    return total % 10 == 0


def mask(kind: str, value: str) -> str:
    if kind == "phone":
        local = re.sub(r"\D", "", value)[-11:]
        return local[:3] + "****" + local[-4:]
    if kind == "email":
        local, domain = value.split("@", 1)
        return local[:1] + "***@" + domain
    if kind == "id_card":
        return value[:6] + "********" + value[-4:] if valid_id_card(value) else value
    if kind == "bank_card":
        digits = re.sub(r"\D", "", value)
        if not 13 <= len(digits) <= 19 or not luhn(digits):
            return value
        return "*" * (len(digits) - 4) + digits[-4:]
    if kind == "ipv4":
        parts = value.split(".")
        return parts[0] + "." + parts[1] + ".*.*"
    return value


def redact(text: str, types: Iterable[str]) -> tuple[str, dict[str, int]]:
    selected = set(types)
    counts: dict[str, int] = {}
    if not selected or not text:
        return text, counts
    for kind in ORDER:
        if kind not in selected:
            continue
        hits = 0

        def replace(match: re.Match[str]) -> str:
            nonlocal hits
            masked = mask(kind, match.group(0))
            if masked != match.group(0):
                hits += 1
            return masked

        text = PATTERNS[kind].sub(replace, text)
        if hits:
            counts[kind] = hits
    return text, counts
