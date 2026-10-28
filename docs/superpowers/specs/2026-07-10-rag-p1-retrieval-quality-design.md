# RAG P1 Retrieval Quality Design

## Goal

Improve retrieval success and precision for Chinese, mixed Chinese/English, error-code, and long-document queries without adding another external model call. P1 must make quality measurable through evaluation gates rather than relying on ranking intuition.

## Selected Approach

Use a deterministic local quality pipeline:

1. A shared text analyzer produces stable terms for Chinese, Latin text, numbers, and device/error codes.
2. A content-aware chunker preserves QA and image records while splitting long text at semantic boundaries with bounded overlap.
3. Retrieval keeps the existing vector + lexical + RRF + local rerank pipeline, then applies strategy-aware confidence thresholds before diversity selection.
4. Evaluation runs produce a pass/fail quality gate from Recall@K and MRR.
5. Retrieval logs expose no-hit, low-confidence, and fallback rates for operational tuning.

This approach is preferred over two alternatives:

- External Cross-Encoder reranking could improve ordering, but adds provider cost, latency, protocol work, and a new failure path. It is deferred to P1.1.
- Weight-only tuning is fast, but it cannot repair Chinese token boundaries or long-document semantic truncation and is therefore insufficient.

## Scope

### Shared text analysis

Create a focused text analyzer used by lexical recall, local reranking, near-duplicate detection, and query profiling.

- Normalize case, repeated whitespace, full-width ASCII, and common punctuation without removing error-code structure.
- Preserve Latin/alphanumeric terms such as `E77`, `C19`, `PLC-02`, and `reset`.
- Split continuous Han text into overlapping two-character terms, retaining the original Han sequence for exact-phrase matching.
- Keep deterministic output and avoid new PHP extensions, dictionaries, network calls, or environment variables.
- Continue the existing deterministic rewrite rules and add only universal Chinese variants such as `故障/错误/报警` and `过热/温度过高`; product-specific terminology belongs in knowledge content or a future managed synonym dictionary.

### Content-aware chunking

QA and image preview items remain atomic. Text and table-row content use the knowledge base `chunking_profile`, with conservative defaults:

- target size: 800 Unicode characters;
- maximum size: 1,200 Unicode characters;
- minimum useful tail: 80 Unicode characters;
- overlap: 120 Unicode characters.

The chunker prefers boundaries in this order: headings and blank lines, paragraph endings, Chinese/English sentence punctuation, then a hard Unicode-safe cut. Overlap is taken from the preceding semantic segment where possible.

Each generated segment receives a deterministic external chunk ID derived from the preview item ID and segment ordinal. Re-running confirmation or recovering from a crash updates or reuses the same segments instead of duplicating them. Segment metadata records the source preview item, segment ordinal, character offsets, boundary type, and chunking profile version.

P1 applies the new strategy to newly confirmed or explicitly reprocessed uploads. It does not automatically rewrite all historical chunks.

### Strategy-aware confidence thresholds

Thresholding occurs after RRF and local reranking but before near-duplicate suppression and Top K truncation. RRF score is not treated as a cosine score.

For every candidate, expose a normalized `confidence_score` plus the evidence used:

- `vector_score`: raw vector-store score;
- `lexical_score`: existing lexical score;
- `rerank_score`: deterministic local rerank contribution;
- `exact_phrase_match` and `code_match` flags;
- `threshold`, `threshold_profile`, and `accepted`.

Default strategy thresholds are intentionally conservative:

- vector-only: raw vector score at least `0.35`;
- lexical-only: lexical score at least `0.08`;
- hybrid: normalized confidence at least `0.20`;
- an exact phrase or exact device/error-code match may reduce the applicable threshold by at most `0.10`.

Knowledge-base `retrieval_profile` may override these values within safe ranges. Invalid profiles fall back to defaults and are reported in rank signals; they do not disable filtering.

If optional RAG produces no accepted sources, the request continues with `fallback_used=true`. If `rag.required=true`, zero accepted sources returns `RAG_REQUIRED_NO_CONTEXT` rather than silently calling the model without required context.

### Evaluation quality gate

Extend the existing evaluation service instead of creating a parallel evaluator.

The default gate is:

- Recall@5 at least 95%;
- MRR at least 0.80;
- evaluation run completed successfully;
- at least one active evaluation case.

Gate thresholds are explicit inputs and are persisted in evaluation-run metadata. The result includes `quality_gate_passed` and a list of violations. A CLI entry point runs selected datasets/cases, prints machine-readable JSON, and exits non-zero when the gate fails.

The regression corpus must cover:

- Chinese query and Chinese answer with no whitespace token boundaries;
- mixed Chinese/English device code queries;
- a relevant sentence located across a long-document chunk boundary;
- a high vector-score but lexically unrelated distractor;
- exact error-code rescue;
- no-context filtering;
- per-knowledge-base threshold overrides.

### Retrieval quality monitoring

Add a read-only quality summary over `rag_retrieval_logs`:

- total retrievals;
- no-hit count and rate;
- fallback count and rate;
- low-confidence count and rate;
- average and p95 latency;
- recent no-hit/low-confidence queries with dataset IDs and retrieval IDs.

No new admin UI is required in P1. The service/API payload is sufficient for later dashboard work.

## Components

- `RagTextAnalyzerService`: normalization, Chinese/Latin/code term generation, phrase and code extraction.
- `RagContentChunkerService`: pure segmentation with offsets and boundary metadata.
- `RagChunkingService`: persistence, deterministic IDs, recovery, and upload-level locking.
- `RagRetrievalProfileService`: safe defaults and validated knowledge-base overrides.
- `RagRetrievalService`: shared analyzer, confidence calculation, threshold filtering, required-context behavior, and rank signals.
- `RagRetrievalEvaluationService`: gate calculation and persistence.
- `RagRetrievalQualityService`: operational quality summary.
- `bin/rag-eval-gate.php`: machine-readable evaluation gate command.

## Data Flow

1. Upload confirmation loads the knowledge-base chunking profile.
2. Each preview item is analyzed and segmented deterministically.
3. Segments are persisted with stable IDs and queued through the P0 embedding ownership contract.
4. Retrieval analyzes the query once and uses the same terms for lexical recall, reranking, phrase detection, and duplicate suppression.
5. Vector and lexical candidates are fused and reranked.
6. Confidence is calculated from strategy-specific evidence and filtered using the validated retrieval profile.
7. Accepted candidates pass through diversity selection and prompt injection.
8. Retrieval logs persist accepted sources and enough rank signals for evaluation and monitoring.
9. Evaluation runs call the same retrieval seam and enforce Recall@5/MRR gates.

## Failure Handling

- Invalid chunking profiles use defaults and record the effective profile in chunk metadata.
- Chunking remains idempotent after partial crashes through deterministic segment IDs and upload locking.
- Invalid retrieval profiles use safe defaults and expose a profile warning.
- Vector retrieval failure continues through lexical recall as today.
- Threshold filtering never promotes rejected candidates merely to fill Top K.
- Required RAG with zero accepted context returns `RAG_REQUIRED_NO_CONTEXT`.
- Evaluation with no active cases returns `RAG_EVAL_NO_CASES` and cannot pass the gate.
- Gate CLI exits non-zero on a metric violation or runtime failure.

## Verification

Implementation follows red-green TDD with focused service tests, then runs:

1. Chinese analyzer contract tests.
2. Content-aware chunk boundary, overlap, deterministic ID, and recovery tests.
3. Dynamic-threshold and required-context tests.
4. Existing hybrid, rewrite/rerank, image, deletion, and provider-Qdrant E2E tests.
5. Evaluation gate pass/fail and CLI contract tests.
6. Retrieval-quality summary tests.
7. The complete PHP suite.

The P1 change is accepted only when the seeded quality corpus passes Recall@5 >= 95% and MRR >= 0.80, all existing tests remain green, and no `.env`, Docker Compose, Caddy, or Dockerfile changes are required.

## Non-goals

- No external Cross-Encoder or chat-model rerank call.
- No automatic reindex of all historical knowledge bases.
- No provider-native image embedding protocol changes.
- No admin dashboard redesign.
- No managed synonym-dictionary UI.
- No claim of 99% production recall without a representative production evaluation corpus.
