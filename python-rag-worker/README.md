# TokenHub Python RAG Worker

This worker consumes `rag_embedding_jobs` from the TokenHub database and writes results back to the real RAG tables. It is intentionally API-key agnostic: PHP remains the main system for auth, admin pages, uploads, and external API access.

The Compose service mounts `./storage` so image files and generated thumbnails are resolved from the same persisted storage path used by PHP. Qdrant is used when `QDRANT_URL` is reachable; deterministic local embeddings are kept as a fallback for development and smoke tests.

Run with:

```bash
docker compose up -d rag-worker
```
