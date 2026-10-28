# External API P0 Security Design

## Goal

Close the public API's P0 gaps without changing the Bearer API Key protocol: trusted client IP resolution, per-key rate limiting, idempotent mutations, atomic quota reservations, safe webhook delivery, and race-free sync versions.

## Public contract

- Client-provided `X-Forwarded-For` is never trusted unless the immediate peer is configured as a trusted proxy. Direct callers use `REMOTE_ADDR`.
- Failed authentication is limited to 30 requests per IP per minute. Authenticated model calls are limited to 600 requests per API Key per minute; all other `/v1` routes are limited to 120 requests per API Key per minute. Limits are configurable.
- Mutating external endpoints accept an optional `Idempotency-Key`. A repeated key with the same method, route, API Key, and request digest replays the original response. A different digest returns `409 IDEMPOTENCY_CONFLICT`.
- Webhook targets must resolve only to public addresses, use HTTPS, and cannot redirect. Due delivery jobs obtain a database lease before sending; retries run only after `next_attempt_at`.
- Sync writes allocate a per-user monotonically increasing version inside a transaction. Upsert and delete both enforce `base_sync_version`; disabled or unknown devices cannot write.
- Quotas reserve estimated token usage atomically in Redis and reconcile the reservation with actual usage once the provider returns.

## Components

1. `TrustedProxyService` owns client-IP selection and only reads forwarding headers when the peer matches configured trusted CIDRs.
2. `ExternalRateLimitService` uses Redis fixed windows and produces 429 responses with `Retry-After`.
3. `IdempotencyService` stores a request fingerprint and completed response payload in a new `api_idempotency_records` table.
4. `WebhookTargetValidator` resolves and rejects private/reserved addresses; `WebhookService` claims due deliveries with a lease before posting.
5. `UserSyncService` uses `user_sync_sequences` plus transactions and validates active devices before every write.
6. `QuotaService` and `ApiKeyModelQuotaService` reserve and reconcile counters through atomic Redis operations.

## Error handling and observability

All new denials retain the standard error envelope and request ID. Rate limits return `Retry-After`; idempotency conflicts return `409`; stale sync writes return `409`; unsafe webhooks return `422`. Queue delivery records retain attempt count, next retry time, and lease ownership for diagnostics.

## Compatibility

Existing clients continue using `Authorization: Bearer`. Idempotency is opt-in, while retries without it remain possible but are documented as unsafe for mutation operations. Rate limits are conservative defaults and are configurable through application settings.

## Testing

Focused service tests cover forwarded-header spoofing, fixed-window throttling, idempotent replay/conflict, private webhook rejection and due-lease behavior, concurrent-safe sync version allocation/conflict deletion, and reservation/reconciliation quota accounting. Existing external API contract tests remain green.
