import os
import time
import urllib.request


class TokenHubClient:
    def __init__(self, base_url=None, api_key=None):
        self.base_url = (base_url or os.getenv("TOKENHUB_BASE_URL") or "http://localhost:8080").rstrip("/")
        self.api_key = api_key or os.getenv("TOKENHUB_API_KEY") or "replace-with-tokenhub-api-key"

    def request(self, method, path, body=None):
        data = None if body is None else __import__("json").dumps(body).encode()
        req = urllib.request.Request(
            self.base_url + path,
            data=data,
            method=method,
            headers={"Authorization": "Bearer " + self.api_key, "Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req) as res:
            return __import__("json").loads(res.read().decode())

    def generate_seedance_video(self, prompt, image_url=None):
        return self.request("POST", "/v1/videos/generations", {"model": "seedance-video", "prompt": prompt, "image_url": image_url})

    def poll_task(self, task_id):
        while True:
            result = self.refresh_task(task_id)
            if result.get("data", {}).get("status") in ("succeeded", "failed"):
                return result
            time.sleep(2)

    def refresh_task(self, task_id):
        return self.request("POST", "/v1/tasks/" + task_id + ":refresh", {})

    def model_health(self):
        return self.request("GET", "/v1/model-health")

    def list_assets(self, media_type=None):
        suffix = "" if media_type is None else "?type=" + media_type
        return self.request("GET", "/v1/assets" + suffix)

    def sync_batch(self, records):
        return self.request("POST", "/v1/sync/records:batch", {"records": records})

    def create_webhook(self, url):
        return self.request("POST", "/v1/webhooks", {"url": url, "event_types": ["request.completed"]})

    def test_webhook(self, webhook_id):
        return self.request("POST", "/v1/webhooks/" + webhook_id + ":test", {})

    def webhook_deliveries(self, webhook_id):
        return self.request("GET", "/v1/webhooks/" + webhook_id + "/deliveries")

    def retry_webhook_delivery(self, delivery_id):
        return self.request("POST", "/v1/webhook-deliveries/" + delivery_id + ":retry", {})

    def rag_knowledge_bases(self):
        return self.request("GET", "/v1/rag/knowledge-bases")

    def rag_datasets(self, kb_id):
        return self.request("GET", "/v1/rag/knowledge-bases/" + kb_id + "/datasets")

    def rag_upload(self, kb_id, payload):
        return self.request("POST", "/v1/rag/knowledge-bases/" + kb_id + "/uploads", payload)

    def rag_confirm_vectorization(self, upload_id):
        return self.request("POST", "/v1/rag/uploads/" + upload_id + "/confirm-vectorization", {})

    def rag_retrieval(self, retrieval_id):
        return self.request("GET", "/v1/rag/retrievals/" + retrieval_id)
