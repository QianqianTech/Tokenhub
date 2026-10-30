from __future__ import annotations

import json
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

from tokenhub_rag_worker.redaction import normalize_types, redact

FIXTURE = pathlib.Path(__file__).resolve().parents[2] / "tests" / "fixtures" / "rag_redaction_cases.json"


class RedactionTest(unittest.TestCase):
    def test_matches_php_fixture(self) -> None:
        data = json.loads(FIXTURE.read_text(encoding="utf-8"))
        for case in data["cases"]:
            out, counts = redact(case["in"], data["types"])
            self.assertEqual(case["out"], out, case["in"])
            self.assertEqual(case["counts"], counts, case["in"])

    def test_only_selected_types(self) -> None:
        self.assertEqual("电话13812345678", redact("电话13812345678", ["email"])[0])

    def test_normalize(self) -> None:
        self.assertEqual(["phone", "id_card"], normalize_types("id_card, phone"))
        self.assertEqual([], normalize_types(None))


if __name__ == "__main__":
    unittest.main()
