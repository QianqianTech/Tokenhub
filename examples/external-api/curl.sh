#!/usr/bin/env sh
set -eu

TOKENHUB_BASE_URL="${TOKENHUB_BASE_URL:-http://localhost:8080}"
TOKENHUB_API_KEY="${TOKENHUB_API_KEY:-replace-with-tokenhub-api-key}"

curl_json() {
  path="$1"
  data="$2"
  curl -sS -X POST "${TOKENHUB_BASE_URL}${path}" \
    -H "Authorization: Bearer ${TOKENHUB_API_KEY}" \
    -H "Content-Type: application/json" \
    -d "${data}"
}

curl_json "/v1/videos/generations" '{"model":"seedance-video","prompt":"product demo","image_url":"https://example.invalid/image.png"}'
curl -sS "${TOKENHUB_BASE_URL}/v1/tasks/task_example" -H "Authorization: Bearer ${TOKENHUB_API_KEY}"
curl_json "/v1/tasks/task_example:refresh" '{}'
curl -sS "${TOKENHUB_BASE_URL}/v1/model-health" -H "Authorization: Bearer ${TOKENHUB_API_KEY}"
curl -sS "${TOKENHUB_BASE_URL}/v1/assets?type=file" -H "Authorization: Bearer ${TOKENHUB_API_KEY}"
curl_json "/v1/sync/records:batch" '{"records":[]}'
curl_json "/v1/webhooks" '{"url":"https://example.invalid/webhook","event_types":["request.completed"]}'
curl_json "/v1/webhooks/wh_example:test" '{}'
curl -sS "${TOKENHUB_BASE_URL}/v1/webhooks/wh_example/deliveries" -H "Authorization: Bearer ${TOKENHUB_API_KEY}"
curl_json "/v1/webhook-deliveries/whd_example:retry" '{}'
curl -sS "${TOKENHUB_BASE_URL}/v1/rag/knowledge-bases" -H "Authorization: Bearer ${TOKENHUB_API_KEY}"
curl -sS "${TOKENHUB_BASE_URL}/v1/rag/knowledge-bases/kb_example/datasets" -H "Authorization: Bearer ${TOKENHUB_API_KEY}"
curl_json "/v1/rag/knowledge-bases/kb_example/uploads" '{"filename":"qa.xlsx","content_base64":"","processing":{"template":"qa"}}'
curl -sS "${TOKENHUB_BASE_URL}/v1/rag/retrievals/ret_example" -H "Authorization: Bearer ${TOKENHUB_API_KEY}"
