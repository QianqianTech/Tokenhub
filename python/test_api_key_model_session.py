import unittest

from api_key_model_session_test import assistant_text, identity_username, model_list, select_chat_models


class SelectChatModelsTest(unittest.TestCase):
    def test_selects_text_generation_models_and_preserves_model_names(self):
        models = [
            {"name": "chat-primary", "capability": "text_generation"},
            {"name": "vision-model", "capability": "vision_understanding"},
            {"name": "chat-fallback", "capability": "text_generation"},
        ]

        self.assertEqual(
            select_chat_models(models),
            ["chat-primary", "chat-fallback"],
        )

    def test_reads_openai_compatible_model_list_ids(self):
        payload = {"data": [{"id": "deepseek-v4-flash"}]}

        self.assertEqual(model_list(payload), [{"id": "deepseek-v4-flash"}])
        self.assertEqual(select_chat_models(model_list(payload)), ["deepseek-v4-flash"])

    def test_rejects_an_explicit_model_that_is_not_authorized(self):
        models = [{"name": "chat-primary", "capability": "text_generation"}]

        with self.assertRaisesRegex(ValueError, "not authorized"):
            select_chat_models(models, requested_models=["missing-model"])

    def test_reads_username_from_the_nested_identity_payload(self):
        self.assertEqual(identity_username({"user": {"username": "admin"}}), "admin")

    def test_reads_openai_and_anthropic_assistant_text(self):
        self.assertEqual(
            assistant_text({"choices": [{"message": {"content": "OpenAI response"}}]}),
            "OpenAI response",
        )
        self.assertEqual(
            assistant_text({"content": [{"type": "text", "text": "Kimi response"}]}),
            "Kimi response",
        )


if __name__ == "__main__":
    unittest.main()
