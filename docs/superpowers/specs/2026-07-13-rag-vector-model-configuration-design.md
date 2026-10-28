# RAG Vector Model Configuration Design

## Goal

Make a configured embedding model usable by a knowledge base without direct database edits: administrators can create and validate vector configurations, select them for a dataset or as knowledge-base defaults, and RAG consistently resolves the intended configuration.

## Selected Approach

Retain the existing `rag_vector_configs` table and its generic administrator CRUD routes. Add a focused RAG configuration service that validates model references and resolves the effective configuration. Add a dedicated administrator probe endpoint and complete the existing management UI forms.

This avoids a parallel storage and permission model while closing the current gaps: stale UI fields, missing editor forms, delayed validation, and knowledge-base defaults that are not read at runtime.

## Scope

### Effective configuration priority

For each requested modality, resolve an enabled vector configuration in this order:

1. the explicit dataset configuration (`text_vector_config_id`, `image_vector_config_id`, or `multimodal_vector_config_id`);
2. the owning knowledge-base default (`default_text_vector_config_id`, `default_image_vector_config_id`, or `default_multimodal_vector_config_id`);
3. the first enabled configuration of the matching modality, preserving the existing fallback behavior;
4. for text only, the existing local fallback descriptor.

An explicit but disabled, missing, or mismatched-modality reference is not treated as valid. Resolution continues to the next level and exposes no new external API behavior.

### Administrator contract

`POST` and `PUT /admin-api/rag-vector-configs` must reject a provider-backed configuration unless:

- both `provider_id` and `model_id` are supplied together;
- the model belongs to that provider;
- the model capability is `embedding` and both model and provider are active;
- `dimension` is a positive integer;
- the modality, vector store, distance metric, and collection name are valid;
- the persisted `model_name` is the selected model's external name, falling back to its display name.

Local fallback remains explicit: it has neither provider nor model IDs and requires `config_json.allow_local_fallback=true`. It is not selectable as an accidental partial provider configuration.

Add `POST /admin-api/rag-vector-configs/test` for super administrators. It accepts an unsaved or saved provider-backed vector configuration, issues one fixed probe text through the same `RagEmbeddingProviderService` path, and returns the resolved provider/model name and actual vector dimension. It never stores a vector or changes vector collections.

### Administrator UI

Replace the stale `rag-vector-configs` resource metadata with real schema fields and a form for configuration name, modality, Provider, embedding model, dimension, distance metric, vector store, collection name, batch size, local fallback JSON, and enabled status.

Extend the knowledge-base editor with default text/image/multimodal vector-config selectors. Extend the vector-dataset editor with override selectors for the same three modalities. The UI submits IDs only; server validation remains authoritative.

The configuration editor includes a test action that displays the returned dimension or the normalized provider error without saving the draft.

## Components

- `RagVectorConfigService`: effective-resolution logic and configuration validation helpers.
- `CrudService`: delegates writes for `rag_vector_configs`, `rag_knowledge_bases`, and `rag_vector_datasets` to the focused validation logic.
- `RagController` and `Kernel`: authenticated administrator probe endpoint.
- `resources.js` and `table.js`: schema-accurate forms, selectors, and probe action.
- Focused service, API, and frontend contract tests.

## Error Handling

- Invalid provider/model pair: `RAG_VECTOR_CONFIG_INVALID` with a precise validation message.
- Wrong capability, disabled model, or disabled provider: `RAG_MODEL_NOT_CONFIGURED`.
- Probe request failure: return the normalized upstream error without persisting anything.
- Probe vector with a dimension different from the configured dimension: `RAG_EMBEDDING_DIMENSION_MISMATCH`.
- Existing retrieval behavior remains resilient: optional RAG can use lexical fallback when vector retrieval is unavailable; required RAG retains its strict existing error contract.

## Verification

Implementation follows red-green TDD and proves:

1. invalid or inactive provider/model pairs cannot be saved;
2. a valid embedding model is normalized and can be probed through `/v1/embeddings`;
3. dataset configuration overrides the knowledge-base default, which overrides global modality fallback;
4. image and multimodal references cannot be selected for a text configuration;
5. management-resource metadata has complete, schema-accurate editor fields;
6. the existing provider vectorization and retrieval tests continue to pass.

## Non-goals

- No change to external API-key authentication or exposure of administrator configuration routes under `/v1`.
- No automatic re-vectorization of historical documents after changing a configuration.
- No changes to `.env`, Docker Compose, Caddy, or Dockerfiles.
