import io
import json
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from tokenhub_rag_worker import logging_json  # noqa: E402
from tokenhub_rag_worker.errors import (  # noqa: E402
    RAG_DOCUMENT_EMPTY,
    RAG_FILE_NOT_FOUND,
    RAG_IMAGE_EMBEDDING_FAILED,
    RAG_JOB_FAILED,
    RAG_JOB_TYPE_UNSUPPORTED,
    RAG_MODEL_NOT_CONFIGURED,
    RAG_OCR_UNAVAILABLE,
    RAG_SOURCE_NOT_FOUND,
    RAG_VECTOR_STORE_REJECTED,
    VectorStoreError,
    error_code_for,
)


class ErrorCodeMappingTest(unittest.TestCase):
    def test_explicit_codes_win(self):
        self.assertEqual(RAG_MODEL_NOT_CONFIGURED, error_code_for({"job_type": "text_embedding"}, RuntimeError(RAG_MODEL_NOT_CONFIGURED)))
        self.assertEqual(RAG_OCR_UNAVAILABLE, error_code_for({"job_type": "ocr"}, RuntimeError(RAG_OCR_UNAVAILABLE + ": missing")))
        self.assertEqual(RAG_DOCUMENT_EMPTY, error_code_for({"job_type": "parse"}, RuntimeError(RAG_DOCUMENT_EMPTY)))

    def test_failures_are_no_longer_reported_as_model_not_configured(self):
        self.assertEqual(RAG_VECTOR_STORE_REJECTED, error_code_for({"job_type": "text_embedding"}, VectorStoreError("bad request")))
        self.assertEqual(RAG_SOURCE_NOT_FOUND, error_code_for({"job_type": "text_embedding"}, RuntimeError("rag_chunks row not found")))
        self.assertEqual(RAG_FILE_NOT_FOUND, error_code_for({"job_type": "parse"}, RuntimeError("upload file not found: /x")))
        self.assertEqual(RAG_JOB_TYPE_UNSUPPORTED, error_code_for({"job_type": "x"}, RuntimeError("unsupported RAG job type: x")))
        self.assertEqual(RAG_JOB_FAILED, error_code_for({"job_type": "text_embedding"}, ValueError("boom")))
        self.assertEqual(RAG_IMAGE_EMBEDDING_FAILED, error_code_for({"job_type": "image_embedding"}, ValueError("boom")))

    def test_requests_errors_when_available(self):
        try:
            import requests
        except ImportError:
            self.skipTest("requests not installed")
        self.assertEqual("RAG_VECTOR_STORE_TIMEOUT", error_code_for({}, requests.Timeout()))
        self.assertEqual("RAG_VECTOR_STORE_UNAVAILABLE", error_code_for({}, requests.ConnectionError()))


class StructuredLoggingTest(unittest.TestCase):
    def test_one_json_line_with_correlation_and_masking(self):
        buffer = io.StringIO()
        with mock.patch.object(sys, "stdout", buffer):
            with logging_json.correlation("req_123", "trc_456"):
                logging_json.error("rag job failed", error_code="RAG_JOB_FAILED", api_key="tkh_secret", error="Bearer abc.def")
            logging_json.info("outside")
        lines = buffer.getvalue().strip().splitlines()
        self.assertEqual(2, len(lines))
        first = json.loads(lines[0])
        self.assertEqual("error", first["level"])
        self.assertEqual("req_123", first["request_id"])
        self.assertEqual("trc_456", first["trace_id"])
        self.assertEqual("RAG_JOB_FAILED", first["context"]["error_code"])
        self.assertEqual("****", first["context"]["api_key"])
        self.assertNotIn("abc.def", first["context"]["error"])
        second = json.loads(lines[1])
        self.assertIsNone(second["request_id"])


if __name__ == "__main__":
    unittest.main()
