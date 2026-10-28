# RAG P0 Provider Vector Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route provider-backed embeddings through PHP and enforce one Qdrant contract across PHP indexing, Python fallback indexing, and retrieval.

**Architecture:** Persist the selected vector configuration and worker backend on each embedding job. PHP owns real OpenAI-compatible embedding requests through the existing provider stack; Python claims only explicit local-fallback jobs. A dedicated contract service normalizes collection names, external IDs, business vector IDs, UUID point IDs, dimensions, and deletion.

**Tech Stack:** PHP 8.2, MySQL 9, Redis queue, Python 3.12, Qdrant REST API, existing TokenHub provider/model tables.

---

### Task 1: Persist embedding-job ownership

**Files:**
- Create: `database/migrations/2026_07_10_000011_rag_embedding_job_contract.sql`
- Modify: `database/migrations/2026_07_09_000002_rag_knowledge_base.sql`
- Modify: `tests/Console/RagSchemaTest.php`

- [ ] **Step 1: Add failing schema assertions**

Add `vector_config_id` and `execution_backend` to the required `rag_embedding_jobs` columns and assert the incremental migration contains both names.

- [ ] **Step 2: Verify RED**

Run: `docker compose exec -T php php tests/Console/RagSchemaTest.php`

Expected: failure because the columns do not exist.

- [ ] **Step 3: Add the migration and base-schema columns**

The migration uses `information_schema.columns` guards, adds nullable `vector_config_id`, adds `execution_backend ENUM('php_provider','python_local') NOT NULL DEFAULT 'python_local'`, and adds `(execution_backend,status,next_run_at)` index only when absent. Update the base table definition with the same columns and index.

- [ ] **Step 4: Apply and verify GREEN**

Run: `docker compose exec -T php php bin/migrate.php`

Run: `docker compose exec -T php php tests/Console/RagSchemaTest.php`

Expected: migration applies once and the schema test passes.

### Task 2: Centralize the vector contract

**Files:**
- Create: `app/Services/RagVectorContractService.php`
- Create: `tests/Services/RagVectorContractServiceTest.php`
- Modify: `app/Services/QdrantVectorStore.php`
- Modify: `tests/Services/RagVectorDeletionServiceTest.php`

- [ ] **Step 1: Write the failing contract test**

The test constructs a dataset with internal ID `42`, external ID `ds_contract`, external KB ID `kb_contract`, and config collection `contract_text`. Assert:

```php
$contract = (new RagVectorContractService())->forChunk($dataset, $config, $chunk, 'text');
assert_same('contract_text', $contract['collection']);
assert_same('vec_text_chk_contract', $contract['vector_id']);
assert_same('ds_contract', $contract['payload']['dataset_id']);
assert_same('kb_contract', $contract['payload']['kb_id']);
assert_same('chk_contract', $contract['payload']['chunk_id']);
assert_true(preg_match('/^[0-9a-f-]{36}$/', $contract['point_id']) === 1);
assert_same($contract['point_id'], (new RagVectorContractService())->pointId($contract['vector_id']));
```

- [ ] **Step 2: Verify RED**

Run: `docker compose exec -T php php tests/Services/RagVectorContractServiceTest.php`

Expected: failure because `RagVectorContractService` does not exist.

- [ ] **Step 3: Implement the contract service**

Implement `pointId()` using UUID v5 with namespace URL, `collection()` using configured name then external dataset fallback, and `forChunk()` returning collection, business vector ID, point ID, dimension, and external-ID payload.

- [ ] **Step 4: Normalize Qdrant IDs**

`QdrantVectorStore::upsert()` sends the UUID point ID while preserving business `vector_id` in payload. `search()` returns `payload.vector_id` as `vector_id`. `delete()` maps each business vector ID to its UUID before calling Qdrant.

- [ ] **Step 5: Verify GREEN**

Run the contract and deletion tests. Both must pass.

### Task 3: Call real provider-backed embedding models

**Files:**
- Modify: `app/Services/RagEmbeddingProviderService.php`
- Create: `tests/Services/RagProviderEmbeddingServiceTest.php`

- [ ] **Step 1: Write failing provider tests**

Insert an active OpenAI provider, an active `embedding` model, and a vector config referencing both. Inject a requester callable that records path and payload and returns:

```php
['ok' => true, 'status' => 200, 'body' => '', 'json' => ['data' => [['embedding' => [0.1, 0.2, 0.3]]]]]
```

Assert the path is `/v1/embeddings`, the payload contains the model external name and input text, and the returned vector is `[0.1, 0.2, 0.3]`. Add a second assertion that a two-element response for configured dimension three throws `RAG_EMBEDDING_DIMENSION_MISMATCH`.

- [ ] **Step 2: Verify RED**

Run: `docker compose exec -T php php tests/Services/RagProviderEmbeddingServiceTest.php`

Expected: failure because provider-backed configs still throw `RAG_MODEL_NOT_CONFIGURED`.

- [ ] **Step 3: Implement provider routing and validation**

Extend the constructor with an optional provider requester callable. When `provider_id` and `model_id` are present, load the active embedding route, call the requester or `ProviderHttpClient::request()`, parse `data[0].embedding`, cast finite numeric values, and require the configured dimension. Preserve the existing explicit local-fallback branch.

- [ ] **Step 4: Verify GREEN and fallback compatibility**

Run the new provider test and `RagEmbeddingFallbackPolicyTest.php`.

Expected: both pass.

### Task 4: Route queued jobs to the correct worker

**Files:**
- Modify: `app/Services/RagEmbeddingJobService.php`
- Modify: `app/Services/RagUploadService.php`
- Modify: `app/Services/WorkerService.php`
- Modify: `tests/Services/RagUploadConfirmQueuesVectorizationTest.php`
- Create: `tests/Services/RagProviderJobRoutingTest.php`

- [ ] **Step 1: Write failing routing assertions**

Create one provider-backed text config and one local-fallback text config. Assert queued rows persist `vector_config_id`; provider jobs use `php_provider`; local jobs use `python_local`; confirming a provider upload pushes one Redis `rag.vectorize` job.

- [ ] **Step 2: Verify RED**

Run the two routing tests and confirm the missing columns/backend/Redis dispatch cause failure.

- [ ] **Step 3: Persist config and backend**

Make `jobsForChunk()` include the config primary key and backend. Insert both values in `queueUpload()`. Publish one Redis job only when at least one `php_provider` job was queued.

- [ ] **Step 4: Process existing provider rows without duplicates**

Refactor `processUpload()` to load the already-created `php_provider` rows, mark each running, call `indexChunk()`, and update the same row to succeeded or failed. It must not insert a second embedding job. Upload and dataset counters are refreshed after processing.

- [ ] **Step 5: Verify GREEN**

Run routing, upload-confirmation, image-vectorization, and worker-service tests.

### Task 5: Restrict and align the Python fallback worker

**Files:**
- Modify: `python-rag-worker/tokenhub_rag_worker/db.py`
- Modify: `python-rag-worker/tokenhub_rag_worker/tasks.py`
- Modify: `tests/Console/PythonRagWorkerDeploymentTest.php`

- [ ] **Step 1: Make the deployment contract test fail**

Change the test to require `execution_backend = 'python_local'`, `vector_config_id`, `qdrant_id`, configured collection use, external dataset payload, and configured dimension. Remove the obsolete assertions that forbid UUID mapping.

- [ ] **Step 2: Verify RED**

Run: `docker compose exec -T php php tests/Console/PythonRagWorkerDeploymentTest.php`

Expected: failure on the new source-contract requirements.

- [ ] **Step 3: Align Python claim and indexing**

Filter `claim_next_job()` to `execution_backend = 'python_local'`. Load vector config, dataset external ID, and KB external ID for the claimed job. Use config collection name, config dimension, UUID v5 point ID, and external payload IDs. Keep local fallback opt-in.

- [ ] **Step 4: Verify GREEN and source/container parity**

Run the deployment test. Rebuild only the `rag-worker` service, then compare host/container `tasks.py` SHA256 values; they must match.

### Task 6: Prove provider-to-Qdrant retrieval end to end

**Files:**
- Modify: `app/Services/RagRetrievalService.php`
- Create: `tests/Services/RagProviderQdrantE2ETest.php`

- [ ] **Step 1: Write the failing end-to-end test**

Seed an active provider-backed vector config using Qdrant, a KB/dataset with bindings, an upload preview row, and a deterministic requester returning matching document/query vectors. Confirm the upload, process the provider job through `RagEmbeddingJobService`, and retrieve through an injected `RagRetrievalService` provider. Assert the expected external chunk ID is returned and its strategy contains a vector rank signal.

- [ ] **Step 2: Verify RED**

Run the test and confirm it fails before retrieval dependency injection and the unified contract are complete.

- [ ] **Step 3: Add narrow retrieval dependency injection**

Add an optional `RagEmbeddingProviderService` constructor dependency and use it for query embedding. Default construction remains unchanged for production callers.

- [ ] **Step 4: Verify GREEN**

Run the end-to-end test twice to prove Qdrant upsert idempotency and cleanup behavior.

### Task 7: Regression verification

**Files:**
- Modify only if a directly related regression is found.

- [ ] **Step 1: Run focused RAG tests**

Run provider, contract, routing, Qdrant E2E, hybrid, rewrite, evaluation, image, upload, deletion, and worker tests.

- [ ] **Step 2: Run the complete PHP suite**

Run: `docker compose exec -T php php tests/run.php`

Expected: no new RAG failures. The two pre-existing settings-center assertion lines may remain visible until their separate baseline issue is repaired.

- [ ] **Step 3: Run runtime checks**

Verify the PHP container source matches the workspace, the rebuilt Python worker source matches the workspace, the worker healthcheck passes, and a Qdrant filtered search returns the expected external dataset payload.

- [ ] **Step 4: Record the Git limitation**

Because `D:\mysoft\tokenhub\.git` is not recognized as a repository, do not create commits. Report the exact modified files and verification output instead.
