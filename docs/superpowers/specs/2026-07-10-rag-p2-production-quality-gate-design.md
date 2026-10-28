# RAG P2 Production Safety and Quality Gate Design

## Goal

Make retrieval safe under untrusted request parameters and operationally measurable without adding external reranking, schema migrations, or environment changes.

## Selected approach

P2 adds four tightly coupled safeguards to the existing retrieval seam:

1. Clamp public `rag.top_k` to a documented safe range before candidate expansion.
2. Keep optional RAG available when retrieval-log persistence fails: return an explicit fallback with `retrieval_id: null`, while required RAG remains strict.
3. Report retrieval quality from a bounded recent sample and label the scope, capacity, sampled count, and truncation status honestly.
4. Complete the evaluation gate CLI and read-only quality API using the same retrieval path as live traffic.

## Scope

### Request safety

`RagRetrievalService` accepts `rag.top_k` only in the range `1..20`; absent or invalid values use the current default of `8`. The normalized value is used for candidate expansion, response shaping, and log records. This prevents oversized vector/lexical retrieval work and makes the admin-trial and public paths consistent.

### Optional fallback contract

When optional RAG cannot write a retrieval log, it returns a normal fallback response with no sources, `fallback_used=true`, and `retrieval_id=null`. It must not convert a best-effort RAG enhancement into a request failure. Required RAG still returns its stable 424 no-context contract when accepted sources are absent.

### Bounded monitoring

`RagRetrievalQualityService` reads at most a configurable internal capacity (default 5,000 plus one sentinel row), ordered by newest IDs, then applies the requested time window inside that bounded set. Its payload explicitly states that rates and latency are `latest_sample` metrics, returns `capacity`, `sampled`, and `truncated`, and includes retrieval IDs and dataset IDs for each problem query. No claim is made that the summary is a whole-history aggregate.

### Evaluation gate and operations surfaces

The evaluation service calculates Recall@5 and MRR gates, persists the thresholds/result in evaluation-run metadata, and exposes them in returned runs. `bin/rag-eval-gate.php` accepts repeated dataset/case selectors and threshold overrides, prints exactly one JSON document, returns 0 on pass, 2 on a gate violation, and 1 on runtime errors. An admin-authenticated read-only endpoint returns the bounded quality summary.

## Non-goals

- No external Cross-Encoder, chat-model reranking, or new provider configuration.
- No migration or new database index in P2; monitoring is explicitly sampled.
- No automatic production corpus claim beyond evaluation cases supplied to the gate.
- No dashboard redesign.

## Verification

Use red-green tests for top-K normalization, optional log-write failure, bounded/truncated summaries, gate pass/fail persistence, CLI exit codes/JSON, and admin API authentication. Run focused RAG regressions and the complete PHP suite.
