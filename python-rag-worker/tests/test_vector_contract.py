from __future__ import annotations

import pathlib
import sys
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

if "mysql.connector" not in sys.modules:
    mysql_module = types.ModuleType("mysql")
    connector_module = types.ModuleType("mysql.connector")
    mysql_module.connector = connector_module
    sys.modules["mysql"] = mysql_module
    sys.modules["mysql.connector"] = connector_module

from tokenhub_rag_worker.config import Config
from tokenhub_rag_worker.tasks import TaskProcessor, deterministic_embedding


class VectorContractTest(unittest.TestCase):
    def test_local_embedding_matches_php_crc32_contract(self) -> None:
        self.assertEqual([0.0, 1.0, 0.0], deterministic_embedding("alpha beta", 3))
        self.assertEqual(2, len(deterministic_embedding("alpha beta", 2)))

    def test_fallback_collection_sanitizes_external_dataset_id(self) -> None:
        processor = TaskProcessor(Config(), database=None)
        collection = processor.collection_name(
            {"collection_name": "", "external_dataset_id": "ds fallback/中文"},
            "multimodal",
        )
        self.assertEqual("tokenhub_rag_ds_fallback__multimodal", collection)

    def test_qdrant_collection_path_is_url_encoded(self) -> None:
        processor = TaskProcessor(Config(), database=None)
        missing = types.SimpleNamespace(status_code=404, text="missing")
        created = types.SimpleNamespace(status_code=200, text="")
        with patch("tokenhub_rag_worker.tasks.requests.get", return_value=missing) as get, patch(
            "tokenhub_rag_worker.tasks.requests.put", return_value=created
        ) as put:
            processor.ensure_qdrant_collection("name with/slash", 3)
        self.assertIn("name%20with%2Fslash", get.call_args.args[0])
        self.assertIn("name%20with%2Fslash", put.call_args.args[0])


if __name__ == "__main__":
    unittest.main()
