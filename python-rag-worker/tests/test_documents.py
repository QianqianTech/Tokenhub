from __future__ import annotations

import pathlib
import sys
import tempfile
import types
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

if "mysql.connector" not in sys.modules:
    mysql_module = types.ModuleType("mysql")
    connector_module = types.ModuleType("mysql.connector")
    mysql_module.connector = connector_module
    sys.modules["mysql"] = mysql_module
    sys.modules["mysql.connector"] = connector_module

from tokenhub_rag_worker import documents
from tokenhub_rag_worker.tasks import storage_path, vector_payload


def make_pdf(path: str, pages: list[list[str]]) -> bool:
    try:
        from reportlab.pdfgen import canvas  # type: ignore
    except ImportError:
        return False
    pdf = canvas.Canvas(path)
    for lines in pages:
        y = 800
        for line in lines:
            pdf.drawString(72, y, line)
            y -= 16
        pdf.showPage()
    pdf.save()
    return True


class DocumentParsingTest(unittest.TestCase):
    def test_pdf_pages_headers_and_headings(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = f"{tmp}/r.pdf"
            pages = [[
                "ACME Confidential",
                f"{n}. Section {n}",
                f"Page {n} body text describes threshold number {n * 7} and inspection",
                f"steps that differ on every page {n * 13}.",
                f"Page {n} of 4",
            ] for n in range(1, 5)]
            if not make_pdf(path, pages):
                self.skipTest("reportlab not installed")
            result = documents.pdf_to_markdown(path)
            markdown = result["markdown"]
            self.assertEqual(4, result["pages"])
            self.assertIn("<!-- page:3 -->", markdown)
            self.assertNotIn("ACME Confidential", markdown)
            self.assertNotIn("Page 2 of 4", markdown)
            self.assertIn("## 2. Section 2", markdown)
            self.assertIn("threshold number 14", markdown)

    def test_scanned_pdf_uses_ocr_or_fails_honestly(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = f"{tmp}/scan.pdf"
            if not make_pdf(path, [[], []]):
                self.skipTest("reportlab not installed")
            if documents.ocr_engine() is None:
                with self.assertRaises(documents.OcrUnavailable):
                    documents.pdf_to_markdown(path)
            result = documents.pdf_to_markdown(path, ocr=lambda p, i: f"第{i + 1}章 扫描内容\n识别出的正文第 {i + 1} 页。")
            self.assertEqual(2, result["ocr_pages"])
            self.assertIn("# 第1章 扫描内容", result["markdown"])

    def test_payload_contract_matches_php(self) -> None:
        payload = vector_payload(
            {"external_kb_id": "kb_1", "external_dataset_id": "ds_1", "dataset_name": "DS"},
            {"chunk_id": "chk_1", "title": "T", "content": "C", "metadata": '{"document_key": "file:a.pdf", "source_uri": "s3://a"}'},
            "vec_text_chk_1",
            "text",
        )
        php_keys = {"vector_id", "kb_id", "dataset_id", "dataset_name", "chunk_id", "document_key", "title", "content", "modality", "image_id", "source_uri", "human_annotation", "ai_annotation"}
        self.assertEqual(php_keys, set(payload))
        self.assertEqual("file:a.pdf", payload["document_key"])

    def test_storage_path_maps_source_install_paths(self) -> None:
        self.assertEqual("/srv/app/storage/rag/uploads/x.pdf", storage_path("/srv/app/storage", "/opt/tokenhub/storage/rag/uploads/x.pdf"))
        self.assertEqual("/srv/app/storage/rag/uploads/x.pdf", storage_path("/srv/app/storage", "storage/rag/uploads/x.pdf"))


if __name__ == "__main__":
    unittest.main()
