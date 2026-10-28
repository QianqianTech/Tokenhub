export class TokenHubClient {
  constructor({ baseUrl = process.env.TOKENHUB_BASE_URL || "http://localhost:8080", apiKey = process.env.TOKENHUB_API_KEY || "replace-with-tokenhub-api-key" } = {}) {
    this.baseUrl = baseUrl.replace(/\/$/, "");
    this.apiKey = apiKey;
  }

  async request(method, path, body) {
    const response = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers: { Authorization: `Bearer ${this.apiKey}`, "Content-Type": "application/json" },
      body: body === undefined ? undefined : JSON.stringify(body)
    });
    return response.json();
  }

  generateSeedanceVideo(prompt, imageUrl) {
    return this.request("POST", "/v1/videos/generations", { model: "seedance-video", prompt, image_url: imageUrl });
  }

  async pollTask(taskId) {
    for (;;) {
      const result = await this.refreshTask(taskId);
      if (["succeeded", "failed"].includes(result?.data?.status)) return result;
      await new Promise((resolve) => setTimeout(resolve, 2000));
    }
  }

  refreshTask(taskId) {
    return this.request("POST", `/v1/tasks/${taskId}:refresh`, {});
  }

  modelHealth() {
    return this.request("GET", "/v1/model-health");
  }

  listAssets(mediaType) {
    return this.request("GET", `/v1/assets${mediaType ? `?type=${encodeURIComponent(mediaType)}` : ""}`);
  }

  syncBatch(records) {
    return this.request("POST", "/v1/sync/records:batch", { records });
  }

  createWebhook(url) {
    return this.request("POST", "/v1/webhooks", { url, event_types: ["request.completed"] });
  }

  testWebhook(webhookId) {
    return this.request("POST", `/v1/webhooks/${webhookId}:test`, {});
  }

  webhookDeliveries(webhookId) {
    return this.request("GET", `/v1/webhooks/${webhookId}/deliveries`);
  }

  retryWebhookDelivery(deliveryId) {
    return this.request("POST", `/v1/webhook-deliveries/${deliveryId}:retry`, {});
  }

  ragKnowledgeBases() {
    return this.request("GET", "/v1/rag/knowledge-bases");
  }

  ragDatasets(kbId) {
    return this.request("GET", `/v1/rag/knowledge-bases/${kbId}/datasets`);
  }

  ragUpload(kbId, payload) {
    return this.request("POST", `/v1/rag/knowledge-bases/${kbId}/uploads`, payload);
  }

  ragConfirmVectorization(uploadId) {
    return this.request("POST", `/v1/rag/uploads/${uploadId}/confirm-vectorization`, {});
  }

  ragRetrieval(retrievalId) {
    return this.request("GET", `/v1/rag/retrievals/${retrievalId}`);
  }
}
