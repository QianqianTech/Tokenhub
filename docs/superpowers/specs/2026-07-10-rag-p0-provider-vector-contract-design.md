# RAG P0 Provider Vector Contract Design

## Goal

Close the production text-retrieval gap by making PHP the owner of provider-backed embeddings, keeping Python only for explicitly local fallback work, and enforcing one vector contract across MySQL, Qdrant, indexing, and retrieval.

## Scope

- Reuse existing `providers`, `models`, `rag_vector_configs`, encrypted provider secrets, and `ProviderHttpClient`.
- Support OpenAI-compatible `/v1/embeddings` for provider-backed text embeddings.
- Persist the selected vector configuration and execution backend on every embedding job.
- Make Qdrant collection names, point IDs, payload IDs, dimensions, and delete behavior deterministic and identical across PHP and Python.
- Add an end-to-end regression path covering upload confirmation, queued embedding work, provider response parsing, Qdrant indexing, and PHP retrieval.
- Preserve existing external RAG APIs and admin contracts.

The change does not modify `.env`, Docker Compose, Caddy, or Dockerfiles. It does not add a normal-user login flow or a second provider-secret store.

## Selected Architecture

### Provider-backed jobs

When a selected `rag_vector_configs` row references an active `provider_id` and `model_id`, `RagEmbeddingJobService::queueUpload()` creates a `php_provider` job and records its `vector_config_id`. It then publishes one `rag.vectorize` Redis job for the upload. The existing PHP queue worker processes the already-created database jobs; it does not create duplicate job rows.

`RagEmbeddingProviderService` loads the configured embedding model and provider, verifies that both are active and that the model capability is `embedding`, and calls the provider through `ProviderHttpClient` at `/v1/embeddings`. It parses `data[0].embedding`, rejects empty, non-numeric, non-finite, or wrong-dimension vectors, and records a stable RAG error code without exposing provider secrets.

### Local fallback jobs

When no provider-backed model is configured, the job backend is `python_local`. The Python worker claims only `python_local` jobs. Local deterministic embeddings remain opt-in through the existing fallback setting and are treated as development/smoke behavior, not as a silent substitute for a configured provider.

### Shared vector contract

The contract is:

- Business vector ID: `vec_<modality>_<external chunk id>`.
- Qdrant point ID: deterministic UUID v5 derived from the business vector ID.
- Collection: configured `rag_vector_configs.collection_name`; if absent, `tokenhub_rag_<external dataset id>_<modality>`.
- Payload `dataset_id`: external dataset ID.
- Payload `kb_id`: external knowledge-base ID.
- Payload `chunk_id`: external chunk ID.
- Payload `vector_id`: business vector ID.
- Vector dimension: exactly `rag_vector_configs.dimension`.

PHP centralizes this in a small vector-contract service. The Python worker reads the persisted `vector_config_id` and joined external IDs, then reproduces the same contract. Qdrant upsert, search result normalization, and delete all translate business IDs through the same UUID rule.

## Data Flow

1. Upload confirmation creates chunks.
2. `queueUpload()` resolves the modality-specific vector config and inserts one job per chunk/modality with `vector_config_id` and `execution_backend`.
3. Provider-backed uploads enqueue `rag.vectorize` in Redis; Python-local jobs remain database-polled.
4. The selected worker marks the existing job running and generates a vector.
5. The vector is validated against the configured dimension.
6. MySQL `rag_vector_records` and Qdrant receive the same business metadata and collection name.
7. PHP retrieval embeds the query with the same provider/model/config, searches the same collection, and filters by external dataset ID and modality.
8. Hybrid fusion and prompt injection continue through the existing `RagRetrievalService` path.

## Schema Compatibility

Add an incremental migration for:

- `rag_embedding_jobs.vector_config_id BIGINT UNSIGNED NULL`
- `rag_embedding_jobs.execution_backend ENUM('php_provider','python_local') NOT NULL DEFAULT 'python_local'`
- an index supporting backend/status polling

Existing jobs are classified from their dataset modality config where possible; unmatched legacy jobs remain `python_local`. New code always writes both fields explicitly.

## Failure Handling

- Missing or inactive provider/model: `RAG_MODEL_NOT_CONFIGURED`.
- Provider timeout or rejection: normalized provider error stored on the embedding job.
- Invalid provider payload: `RAG_EMBEDDING_INVALID_RESPONSE`.
- Dimension mismatch: `RAG_EMBEDDING_DIMENSION_MISMATCH`.
- Qdrant failure: job retry/failure behavior remains visible in `rag_embedding_jobs`; no successful index state is written before Qdrant accepts the point.
- Python never claims `php_provider` jobs, preventing cross-worker races.

## Verification Design

Tests are written red-first and cover:

1. Provider-backed embedding request construction, secret reuse through `ProviderHttpClient`, response parsing, and dimension validation.
2. Deterministic UUID mapping plus external dataset/knowledge-base/chunk payload IDs.
3. Job routing: provider config to `php_provider`, unconfigured fallback to `python_local`, with no duplicate job rows.
4. Python claim filtering and use of persisted vector configuration.
5. A Docker-backed Qdrant contract test for upsert, filtered search, business-ID normalization, and deletion.
6. An end-to-end service test: confirmed upload -> queued provider job -> PHP processing -> Qdrant record -> PHP retrieval returns the expected chunk.
7. Existing hybrid, rewrite/rerank, image retrieval, deletion, upload-confirmation, and full PHP suites remain green.

## Non-goals

- Chinese tokenization, chunking redesign, cross-encoder reranking, and retrieval thresholds are P1.
- Provider-backed image-native embeddings are not introduced here; image and multimodal local behavior remains explicit until a provider protocol is designed for those inputs.
- No automatic cleanup of existing test residue or historical Qdrant collections is included.
