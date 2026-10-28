# External API P1 Reliability Implementation Plan

**Goal:** Close external API pagination, file lifecycle, webhook secret, and documentation gaps.

1. Add failing tests for file cursor pagination and physical cleanup; implement both in `ExternalFileService`.
2. Add webhook encrypted-secret/rotation tests; add schema columns, rotation route, and dual signature delivery.
3. Replace event timestamp cursors with deterministic source-id cursors and document async usage consistency.
4. Update OpenAPI and Chinese integration docs; run focused verification.
