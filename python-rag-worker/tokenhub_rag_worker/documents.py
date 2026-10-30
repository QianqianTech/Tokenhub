"""Document parsing for the RAG worker (RAG-020 / RAG-021).

PHP parses most formats itself (RagDocumentParserService). The worker takes
the PDFs PHP cannot: no `pdftotext` on the PHP host, or scanned pages that
need OCR. Output is the same structure-preserving Markdown: page markers
(``<!-- page:N -->``), numbered headings promoted to Markdown headings,
running headers/footers removed.

Optional dependencies, each detected at runtime:
  * pdfplumber or pypdf   - text layer
  * pypdfium2 + rapidocr_onnxruntime - OCR for pages without a text layer
"""

from __future__ import annotations

import re
from typing import Callable, Optional

HEADING_RE = re.compile(r"^(第[一二三四五六七八九十百零\d]+[章节部分篇条]\s*|[一二三四五六七八九十]+、\s*|\d+(\.\d+){0,3}[\s、.．]\s*)\S")
PAGE_COUNTER_RE = re.compile(r"(页|page|Page|PAGE|/|of)")
BARE_PAGE_NUMBER_RE = re.compile(r"^\s*(-\s*)?(第\s*)?\d{1,4}(\s*页)?(\s*/\s*\d{1,4})?\s*(-\s*)?$")


class OcrUnavailable(RuntimeError):
    pass


def text_layer_pages(path: str) -> list[str]:
    try:
        import pdfplumber  # type: ignore

        with pdfplumber.open(path) as pdf:
            return [(page.extract_text() or "") for page in pdf.pages]
    except ImportError:
        pass
    from pypdf import PdfReader  # type: ignore

    reader = PdfReader(path)
    return [(page.extract_text() or "") for page in reader.pages]


def ocr_engine() -> Optional[Callable[[str, int], str]]:
    """Returns a callable (pdf_path, page_index) -> text, or None when OCR is not installed."""
    try:
        import pypdfium2  # type: ignore
        from rapidocr_onnxruntime import RapidOCR  # type: ignore
    except ImportError:
        return None
    engine = RapidOCR()

    def run(path: str, index: int) -> str:
        pdf = pypdfium2.PdfDocument(path)
        try:
            image = pdf[index].render(scale=2).to_pil()
        finally:
            pdf.close()
        result, _ = engine(image)
        lines = [item[1] for item in (result or []) if item and len(item) > 1 and item[1]]
        return "\n".join(lines)

    return run


def image_ocr_engine() -> Optional[Callable[[str], str]]:
    try:
        from rapidocr_onnxruntime import RapidOCR  # type: ignore
    except ImportError:
        return None
    engine = RapidOCR()

    def run(path: str) -> str:
        result, _ = engine(path)
        return "\n".join(item[1] for item in (result or []) if item and len(item) > 1 and item[1])

    return run


def line_key(line: str) -> str:
    line = re.sub(r"\s+", " ", line).strip()
    if len(line) <= 30 and PAGE_COUNTER_RE.search(line):
        return re.sub(r"\d+", "#", line)
    return line


def repeated_page_lines(pages: list[str]) -> set[str]:
    if len(pages) < 3:
        return set()
    counts: dict[str, int] = {}
    for text in pages:
        lines = [line.strip() for line in text.split("\n") if line.strip()]
        edge = {line_key(line) for line in lines[:2] + lines[-2:]}
        for key in edge:
            counts[key] = counts.get(key, 0) + 1
    threshold = max(3, -(-len(pages) * 6 // 10))
    return {key for key, count in counts.items() if key and count >= threshold and len(key) <= 80}


def join_wrapped_lines(text: str) -> str:
    lines = text.split("\n")
    lengths = sorted(len(line.strip()) for line in lines if line.strip())
    typical = lengths[int(len(lengths) * 0.75)] if lengths else 0
    out: list[str] = []
    for line in lines:
        line = line.rstrip()
        previous = out[-1] if out else ""
        wrapped = (
            previous
            and line
            and len(previous.strip()) >= max(20, int(typical * 0.8))
            and not re.search(r"[。！？.!?:：;；]$", previous)
            and not re.match(r"^\s*(第.{1,6}[章节条]|\d+(\.\d+)*[\s.、]|[一二三四五六七八九十]+、|[-*•])", line)
        )
        if wrapped:
            joiner = "" if re.search(r"[一-鿿]$", previous) else " "
            out[-1] = previous + joiner + line.lstrip()
            continue
        out.append(line)
    return "\n".join(out)


def promote_headings(text: str) -> str:
    out: list[str] = []
    for line in text.split("\n"):
        stripped = line.strip()
        if stripped and len(stripped) <= 40 and HEADING_RE.match(stripped) and not re.search(r"[。；;，,]$", stripped):
            if re.match(r"^第.+[章篇部分]", stripped):
                level = 1
            elif re.match(r"^\d+\.\d+", stripped):
                level = 3
            else:
                level = 2
            out.extend(["", "#" * level + " " + stripped, ""])
            continue
        out.append(line.rstrip())
    return "\n".join(out)


def pdf_to_markdown(path: str, ocr: Optional[Callable[[str, int], str]] = None) -> dict:
    """Returns {markdown, pages, ocr_pages, warnings}."""
    pages = text_layer_pages(path)
    warnings: list[str] = []
    ocr_pages = 0
    missing_text = [index for index, text in enumerate(pages) if not text.strip()]
    if missing_text:
        if ocr is None:
            ocr = ocr_engine()
        if ocr is None:
            if len(missing_text) == len(pages):
                raise OcrUnavailable("PDF has no text layer and OCR (rapidocr_onnxruntime + pypdfium2) is not installed")
            warnings.append(f"{len(missing_text)} page(s) have no text layer; OCR is not installed")
        else:
            for index in missing_text:
                pages[index] = ocr(path, index)
                ocr_pages += 1
    repeated = repeated_page_lines(pages)
    if repeated:
        warnings.append(f"Removed {len(repeated)} repeated page header/footer line(s).")
    blocks: list[str] = []
    for index, text in enumerate(pages):
        blocks.append(f"<!-- page:{index + 1} -->")
        kept = [line for line in text.split("\n") if line_key(line) not in repeated and not BARE_PAGE_NUMBER_RE.match(line)]
        body = "\n".join(kept).strip()
        if body:
            blocks.append(promote_headings(join_wrapped_lines(body)))
            blocks.append("")
    markdown = re.sub(r"\n{3,}", "\n\n", "\n".join(blocks)).strip() + "\n"
    return {"markdown": markdown, "pages": len(pages), "ocr_pages": ocr_pages, "warnings": warnings}
