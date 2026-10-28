# RAG P1 Retrieval Quality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve Chinese and long-document retrieval precision with shared deterministic text analysis, semantic chunking, strategy-aware thresholds, evaluation gates, and operational quality metrics without adding external rerank calls.

**Architecture:** Introduce small pure services for text analysis, content chunking, and retrieval-profile validation. Keep `RagRetrievalService::searchSourcesForDatasets()` as the shared live/evaluation seam, applying confidence filtering after RRF/local rerank and before diversity selection. Reuse existing JSON profiles and evaluation metadata so no schema migration is required.

**Tech Stack:** PHP 8.2+, MySQL 8, existing TokenHub service/test harness, Docker-backed PHP tests, Qdrant regression E2E.

**Workspace note:** Git metadata is unavailable in the current checkout, so execution uses verified file checkpoints instead of commits. Do not modify `.env`, Docker Compose, Caddy, or Dockerfiles.

---

## File Map

- Create `app/Services/RagTextAnalyzerService.php`: shared Unicode normalization, Chinese bigrams, Latin/code terms, phrases, and universal rewrites.
- Create `app/Services/RagContentChunkerService.php`: pure semantic segmentation with Unicode offsets and overlap.
- Create `app/Services/RagRetrievalProfileService.php`: validated threshold defaults and knowledge-base overrides.
- Create `app/Services/RagRetrievalQualityService.php`: retrieval-log quality aggregation.
- Create `bin/rag-eval-gate.php`: evaluation plus machine-readable quality-gate exit status.
- Modify `app/Services/RagChunkingService.php`: deterministic multi-segment persistence and recovery.
- Modify `app/Services/RagPermissionService.php`: include KB chunking/retrieval profiles in resolved datasets.
- Modify `app/Services/RagUploadService.php`: load KB profiles on direct dataset lookup.
- Modify `app/Services/RagRetrievalService.php`: use shared analyzer, confidence thresholds, and required no-context behavior.
- Modify `app/Services/RagRetrievalEvaluationService.php`: calculate and persist gate result.
- Modify `app/Http/Controllers/RagController.php` and `app/Bootstrap/Kernel.php`: expose read-only retrieval-quality summary.
- Add focused tests under `tests/Services`, `tests/Console`, and `tests/Support`.

---

### Task 1: Shared Chinese and mixed-text analyzer

**Files:**
- Create: `app/Services/RagTextAnalyzerService.php`
- Create: `tests/Services/RagTextAnalyzerServiceTest.php`

- [ ] **Step 1: Write the failing analyzer contract test**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagTextAnalyzerService;

$analyzer = new RagTextAnalyzerService();
$profile = $analyzer->analyze('设备温度过高，ＰＬＣ－０２ 报警 E77 reset');

foreach (['设备', '温度', '过高', 'plc-02', '报警', 'e77', 'reset'] as $term) {
    assert_true(in_array($term, $profile['terms'], true), 'Expected analyzer term: ' . $term);
}
assert_true(in_array('e77', $profile['codes'], true), 'Error codes must remain atomic.');
assert_true(in_array('错误', $profile['rewrite_terms'], true), 'Universal Chinese alarm rewrite should be present.');
assert_same($profile, $analyzer->analyze('设备温度过高，ＰＬＣ－０２ 报警 E77 reset'), 'Analyzer output must be deterministic.');
```

- [ ] **Step 2: Run the test and verify RED**

Run: `docker compose exec -T php php tests/Services/RagTextAnalyzerServiceTest.php`

Expected: FAIL because `RagTextAnalyzerService` does not exist.

- [ ] **Step 3: Implement the analyzer**

Implement this public contract:

```php
final class RagTextAnalyzerService
{
    /** @return array{original:string,normalized:string,terms:list<string>,phrases:list<string>,codes:list<string>,rewrite_terms:list<string>} */
    public function analyze(string $text): array;
    public function normalize(string $text): string;
    /** @return list<string> */
    public function terms(string $text): array;
}
```

Implementation rules:

```php
$fullWidth = ['－' => '-', '，' => ',', '：' => ':', '（' => '(', '）' => ')'];
$normalized = mb_strtolower(strtr($text, $fullWidth));
$normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? '');
```

Extract `[a-z0-9]+(?:-[a-z0-9]+)*` as atomic Latin/code terms. For each `\p{Han}+` sequence, retain the full sequence and emit overlapping two-character terms using `mb_substr`. Generate adjacent-term phrases. Apply only these universal rewrites:

```php
[
    '故障' => ['错误', '报警'],
    '错误' => ['故障', '报警'],
    '报警' => ['错误', '故障'],
    '过热' => ['温度过高', '热过载'],
    '温度过高' => ['过热', '热过载'],
]
```

- [ ] **Step 4: Run analyzer test and existing rewrite regression**

Run:

```powershell
docker compose exec -T php php tests/Services/RagTextAnalyzerServiceTest.php
docker compose exec -T php php tests/Services/RagQueryRewriteRetrievalTest.php
```

Expected: both PASS.

- [ ] **Step 5: Checkpoint the analyzer files**

Run: `docker compose exec -T php php -l app/Services/RagTextAnalyzerService.php`

Expected: `No syntax errors detected`.

---

### Task 2: Pure content-aware chunker

**Files:**
- Create: `app/Services/RagContentChunkerService.php`
- Create: `tests/Services/RagContentChunkerServiceTest.php`

- [ ] **Step 1: Write failing semantic-boundary tests**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\RagContentChunkerService;

$chunker = new RagContentChunkerService();
$content = "# 冷却系统\n\n第一段说明设备温度。第二句解释热过载。\n\n" . str_repeat('过滤器需要清理后再启动。', 80);
$segments = $chunker->chunk($content, ['target_chars' => 120, 'max_chars' => 180, 'min_tail_chars' => 20, 'overlap_chars' => 24]);

assert_true(count($segments) > 2, 'Long content should produce multiple segments.');
foreach ($segments as $segment) {
    assert_true(mb_strlen($segment['content']) <= 180, 'Segments must respect max_chars.');
    assert_true($segment['start'] >= 0 && $segment['end'] > $segment['start'], 'Offsets must be valid.');
    assert_true(in_array($segment['boundary'], ['heading', 'paragraph', 'sentence', 'hard'], true), 'Boundary must be explicit.');
}
assert_true(str_contains($segments[1]['content'], mb_substr($segments[0]['content'], -12)), 'Adjacent segments should overlap.');
assert_same($segments, $chunker->chunk($content, ['target_chars' => 120, 'max_chars' => 180, 'min_tail_chars' => 20, 'overlap_chars' => 24]), 'Chunking must be deterministic.');
```

- [ ] **Step 2: Run the test and verify RED**

Run: `docker compose exec -T php php tests/Services/RagContentChunkerServiceTest.php`

Expected: FAIL because `RagContentChunkerService` does not exist.

- [ ] **Step 3: Implement profile validation and segmentation**

Expose:

```php
/** @return list<array{content:string,start:int,end:int,boundary:string}> */
public function chunk(string $content, array $profile = []): array;
/** @return array{target_chars:int,max_chars:int,min_tail_chars:int,overlap_chars:int,version:string,warnings:list<string>} */
public function profile(array $profile): array;
```

Clamp settings to safe ranges:

```php
target_chars: 200..4000, default 800
max_chars: target_chars..6000, default 1200
min_tail_chars: 0..target_chars, default 80
overlap_chars: 0..min(500, target_chars / 2), default 120
version: "p1-v1"
```

Scan Unicode text using `mb_*`. Prefer `\n#{1,6} ` or blank-line boundaries, then `。！？.!?`, then hard cuts. Merge a tail shorter than `min_tail_chars` into the preceding segment when the result stays within `max_chars`.

- [ ] **Step 4: Run chunker test**

Run: `docker compose exec -T php php tests/Services/RagContentChunkerServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Syntax checkpoint**

Run: `docker compose exec -T php php -l app/Services/RagContentChunkerService.php`

Expected: no syntax errors.

---

### Task 3: Deterministic multi-segment persistence and crash recovery

**Files:**
- Modify: `app/Services/RagChunkingService.php`
- Modify: `app/Services/RagPermissionService.php`
- Modify: `app/Services/RagUploadService.php`
- Create: `tests/Services/RagContentAwareChunkingIntegrationTest.php`
- Modify: `tests/Services/RagUploadConfirmQueuesVectorizationTest.php`

- [ ] **Step 1: Write failing integration test**

Create an upload with one long text preview item and KB `chunking_profile` set to `target_chars=100`, `max_chars=150`, `overlap_chars=20`. Confirm it, then assert:

```php
$chunks = $db->select('SELECT chunk_id, ordinal, content, metadata FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ? ORDER BY c.ordinal', [$uploadPk]);
assert_true(count($chunks) >= 3, 'Long preview content should create multiple chunks.');
$firstIds = array_column($chunks, 'chunk_id');
(new RagUploadService())->confirm($apiKey, $uploadId);
$secondIds = array_column($db->select('SELECT c.chunk_id FROM rag_chunks c JOIN rag_documents d ON d.id = c.document_id WHERE d.upload_id = ? ORDER BY c.ordinal', [$uploadPk]), 'chunk_id');
assert_same($firstIds, $secondIds, 'Repeated confirmation must preserve deterministic segment IDs.');
```

Delete the last chunk and its embedding job, confirm again, and assert that exactly the missing deterministic segment/job is restored.

- [ ] **Step 2: Run integration test and verify RED**

Run: `docker compose exec -T php php tests/Services/RagContentAwareChunkingIntegrationTest.php`

Expected: FAIL because current chunking persists one chunk per preview item.

- [ ] **Step 3: Propagate KB profiles with datasets**

Change all dataset-loading queries used by upload/retrieval from:

```sql
SELECT d.*, k.kb_id AS external_kb_id
```

to:

```sql
SELECT d.*, k.kb_id AS external_kb_id,
       k.chunking_profile AS kb_chunking_profile,
       k.retrieval_profile AS kb_retrieval_profile
```

Apply this consistently in `datasetByExternalId`, `datasetsByName`, `datasetsForKnowledgeBase`, `defaultDatasets`, and direct dataset lookups in `RagUploadService`.

- [ ] **Step 4: Persist deterministic segments**

In `RagChunkingService`, keep QA/image items atomic and call `RagContentChunkerService::chunk()` for text/table rows. Generate IDs with:

```php
$chunkId = 'chk_' . substr(hash('sha256', (string) $item['preview_item_id'] . ':' . $segmentOrdinal), 0, 24);
```

Store metadata:

```php
[
    'source_preview_item_id' => $item['preview_item_id'],
    'segment_ordinal' => $segmentOrdinal,
    'start' => $segment['start'],
    'end' => $segment['end'],
    'boundary' => $segment['boundary'],
    'chunking_profile' => $effectiveProfile,
]
```

Use `INSERT ... ON DUPLICATE KEY UPDATE content/content_hash/metadata/token_count` so partial recovery recreates missing segments and refreshes existing deterministic segments. Remove the current `SELECT ... LIMIT 1` early skip.

- [ ] **Step 5: Run chunking, upload, and image regressions**

Run:

```powershell
docker compose exec -T php php tests/Services/RagContentAwareChunkingIntegrationTest.php
docker compose exec -T php php tests/Services/RagUploadConfirmQueuesVectorizationTest.php
docker compose exec -T php php tests/Services/RagImagePackageUploadServiceTest.php
docker compose exec -T php php tests/Services/RagImageVectorizationServiceTest.php
```

Expected: all PASS.

---

### Task 4: Retrieval profiles and strategy-aware confidence filtering

**Files:**
- Create: `app/Services/RagRetrievalProfileService.php`
- Modify: `app/Services/RagRetrievalService.php`
- Create: `tests/Services/RagRetrievalThresholdServiceTest.php`
- Create: `tests/Services/RagChineseRetrievalServiceTest.php`

- [ ] **Step 1: Write failing profile/threshold tests**

Test profile validation:

```php
$profile = (new RagRetrievalProfileService())->resolve(['min_vector_score' => 9, 'min_hybrid_confidence' => -1]);
assert_same(1.0, $profile['min_vector_score']);
assert_same(0.0, $profile['min_hybrid_confidence']);
assert_true($profile['warnings'] !== []);
```

Create a dataset containing a Chinese expected chunk and a vector-only distractor. Search `设备温度过高 E77 如何复位`, then assert the Chinese/code chunk ranks first, includes `confidence_score`, and the unrelated vector candidate is absent when it fails its strategy threshold.

- [ ] **Step 2: Run tests and verify RED**

Run:

```powershell
docker compose exec -T php php tests/Services/RagRetrievalThresholdServiceTest.php
docker compose exec -T php php tests/Services/RagChineseRetrievalServiceTest.php
```

Expected: FAIL because profile/confidence services do not exist and Chinese terms are not shared.

- [ ] **Step 3: Implement profile validation**

Expose:

```php
/** @return array{min_vector_score:float,min_lexical_score:float,min_hybrid_confidence:float,exact_match_discount:float,warnings:list<string>,name:string} */
public function resolve(array|string|null $profile): array;
```

Defaults: `0.35`, `0.08`, `0.20`, and `0.10`. Clamp all scores to `0..1`, cap discount at `0.25`, and return `name=default` or `name=kb_override`.

- [ ] **Step 4: Replace private tokenization with shared analyzer**

Inject optional `RagTextAnalyzerService` and `RagRetrievalProfileService` dependencies into `RagRetrievalService`. Make query profile, lexical score, local rerank, important phrases, and Jaccard terms use the analyzer output. Preserve existing English rewrite behavior by merging current English rewrites with analyzer rewrites.

- [ ] **Step 5: Calculate confidence and filter before MMR**

After `rerankSources()` and before `diverseTopK()`:

```php
$sources = $this->applyConfidenceThresholds($sources, $datasets, $queryProfile);
$sources = $this->diverseTopK($sources, $topK);
```

Rules:

```php
vector-only accepted when vector_score >= min_vector_score
lexical-only accepted when min(1, 1 - exp(-lexical_score)) >= min_lexical_score
hybrid confidence = 0.55 * max(0, vector_score)
                  + 0.35 * min(1, 1 - exp(-lexical_score))
                  + 0.10 * min(1, rerank_score)
hybrid accepted when confidence >= min_hybrid_confidence
```

Apply `exact_match_discount` only when analyzer exact phrase or code evidence is true. Add all threshold evidence to `rank_signals` and set top-level `confidence_score`.

- [ ] **Step 6: Run threshold, Chinese, hybrid, and rewrite tests**

Run the two new tests plus:

```powershell
docker compose exec -T php php tests/Services/RagHybridRetrievalServiceTest.php
docker compose exec -T php php tests/Services/RagQueryRewriteRetrievalTest.php
docker compose exec -T php php tests/Services/RagRetrievalImageSourcesTest.php
```

Expected: all PASS.

---

### Task 5: Required no-context behavior and retrieval quality logging

**Files:**
- Modify: `app/Services/RagRetrievalService.php`
- Create: `app/Services/RagRetrievalQualityService.php`
- Create: `tests/Support/RagRequiredNoContextContractTest.php`
- Create: `tests/Services/RagRetrievalQualityServiceTest.php`

- [ ] **Step 1: Write failing required-context contract and optional behavior tests**

Because `abort_json()` terminates the PHP process, use a source contract for the required branch and a live service assertion for the optional branch. The contract reads `RagRetrievalService.php` and asserts:

```php
$source = file_get_contents(__DIR__ . '/../../app/Services/RagRetrievalService.php') ?: '';
assert_true(str_contains($source, "abort_json('RAG_REQUIRED_NO_CONTEXT'"), 'Required empty context must abort with the stable contract code.');
assert_true(str_contains($source, 'if ($required)'), 'Required behavior must be explicitly gated.');
```

In `RagRetrievalQualityServiceTest.php`, create an authorized dataset whose candidates are below threshold and call optional retrieval. Assert `sources=[]`, `fallback_used=true`, and a retrieval log with `hit_count=0` and `fallback_used=1`.

- [ ] **Step 2: Write failing quality-summary test**

Insert hit, no-hit, low-confidence, and fallback retrieval logs. Assert `summary(24)` returns exact totals/rates and p95 latency using nearest-rank selection.

- [ ] **Step 3: Run both tests and verify RED**

Run both new service tests. Expected: required empty context currently proceeds and quality service is missing.

- [ ] **Step 4: Implement no-context behavior**

When filtered sources are empty:

```php
if ($required) {
    abort_json('RAG_REQUIRED_NO_CONTEXT', 'Required RAG context did not meet retrieval thresholds', 424);
}
$retrievalId = $this->record(..., sources: [], fallback: true, ...);
return ['enabled' => true, 'payload' => $payload, 'sources' => [], 'retrieval_id' => $retrievalId, 'fallback_used' => true];
```

- [ ] **Step 5: Implement quality aggregation**

`RagRetrievalQualityService::summary(int $windowHours = 24, float $lowConfidence = 0.35)` loads bounded rows for the window, parses source confidence, and returns totals, no-hit/fallback/low-confidence counts and rates, average/p95 latency, plus the 20 most recent problem queries. Clamp window to `1..720` and threshold to `0..1`.

- [ ] **Step 6: Run focused tests**

Expected: both tests PASS, plus `RagFeedbackEvalCandidateTest.php` remains green.

---

### Task 6: Evaluation quality gate and CLI

**Files:**
- Modify: `app/Services/RagRetrievalEvaluationService.php`
- Create: `bin/rag-eval-gate.php`
- Create: `tests/Services/RagRetrievalQualityGateTest.php`
- Create: `tests/Console/RagEvaluationGateCliTest.php`

- [ ] **Step 1: Write failing gate service test**

Run seeded cases and pass gate options:

```php
$result = $service->run([
    'case_ids' => $caseIds,
    'top_k_values' => [1, 5, 10, 20],
    'quality_gate' => ['min_recall_at_5' => 95.0, 'min_mrr' => 0.80],
]);
assert_same(true, $result['quality_gate_passed']);
assert_same([], $result['quality_gate_violations']);
```

Run a deliberately failing corpus and assert violations name `recall_at_5` and `mrr`.

- [ ] **Step 2: Run gate test and verify RED**

Expected: gate keys are absent.

- [ ] **Step 3: Implement gate calculation and persistence**

Add:

```php
/** @return array{passed:bool,thresholds:array<string,float>,violations:list<array{metric:string,actual:float,required:float}>} */
public function qualityGate(array $metrics, array $options = []): array;
```

Defaults are Recall@5 `95.0` and MRR `0.80`. Persist gate thresholds/result by merging them into `rag_eval_runs.metadata`, and return `quality_gate_passed`, `quality_gate_thresholds`, and `quality_gate_violations` from `run()`.

- [ ] **Step 4: Write and run failing CLI contract test**

The test reads `bin/rag-eval-gate.php` and requires `RagRetrievalEvaluationService`, `quality_gate`, `JSON_UNESCAPED_UNICODE`, and non-zero exit on gate failure.

- [ ] **Step 5: Implement CLI**

Support:

```text
--dataset=ds_id (repeatable)
--case=case_id (repeatable)
--min-recall-at-5=95
--min-mrr=0.80
```

Load the application container, call `run()`, print one JSON object, exit `0` on pass, `2` on gate failure, and `1` on runtime error without printing secrets.

- [ ] **Step 6: Run evaluation service/CLI tests**

Run new tests plus existing `RagRetrievalEvaluationServiceTest.php` and `RagEvaluationHybridRetrievalTest.php`. Expected: all PASS.

---

### Task 7: Read-only retrieval quality API

**Files:**
- Modify: `app/Http/Controllers/RagController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `tests/Support/RagApiSurfaceContractTest.php`
- Create: `tests/Services/RagRetrievalQualityApiTest.php`

- [ ] **Step 1: Add failing route contract assertions**

Require:

```php
'/admin-api/rag/retrieval-quality'
'retrievalQuality'
'RagRetrievalQualityService'
```

- [ ] **Step 2: Run route contract and verify RED**

Run: `docker compose exec -T php php tests/Support/RagApiSurfaceContractTest.php`

Expected: FAIL because the route/controller method is absent.

- [ ] **Step 3: Implement authenticated read-only endpoint**

Add admin-authenticated GET route and controller method:

```php
public function retrievalQuality(Request $request): Response
{
    return Response::ok((new RagRetrievalQualityService())->summary(
        (int) ($request->query['window_hours'] ?? 24),
        (float) ($request->query['low_confidence'] ?? 0.35),
    ));
}
```

- [ ] **Step 4: Verify API auth and payload**

The service API test must prove an unauthenticated request is rejected and an authenticated admin receives the expected summary keys. Run both route and API tests; expected PASS.

---

### Task 8: Final quality corpus and regression verification

**Files:**
- Create: `tests/Services/RagP1QualityCorpusTest.php`
- Modify: `tests/bootstrap.php` only if the new test prefixes need cleanup registration
- Modify: `docs/superpowers/specs/2026-07-10-rag-p1-retrieval-quality-design.md` only if implementation reveals a contract correction

- [ ] **Step 1: Seed the final P1 corpus test**

Create at least five active evaluation cases covering Chinese no-whitespace retrieval, Chinese/English device codes, long-boundary content, vector distraction, and exact error-code rescue. Run the evaluation gate and assert:

```php
assert_true((float) $result['recall_at_5'] >= 95.0);
assert_true((float) $result['mrr'] >= 0.80);
assert_same(true, $result['quality_gate_passed']);
```

- [ ] **Step 2: Verify the corpus test fails before all P1 pieces are integrated**

Run: `docker compose exec -T php php tests/Services/RagP1QualityCorpusTest.php`

Expected before integration completion: FAIL on Chinese, long-boundary, or gate assertions. After Tasks 1-7, rerun and require PASS.

- [ ] **Step 3: Run all focused RAG tests**

Run:

```powershell
docker compose exec -T php sh -lc 'set -e
for f in tests/Services/Rag*Test.php tests/Console/Rag*Test.php tests/Console/PythonRagWorkerDeploymentTest.php tests/Console/QdrantDeploymentConfigTest.php tests/Support/Rag*Test.php; do php "$f"; done'
```

Expected: exit `0`.

- [ ] **Step 4: Run syntax checks for every modified production PHP file**

Run `php -l` inside the PHP container for the four new services, `RagChunkingService`, `RagPermissionService`, `RagUploadService`, `RagRetrievalService`, `RagRetrievalEvaluationService`, `RagController`, `Kernel`, and the CLI. Expected: no syntax errors.

- [ ] **Step 5: Run the complete PHP suite**

Run: `docker compose exec -T php php tests/run.php`

Expected final line: `All tests passed` and exit `0`.

- [ ] **Step 6: Verify runtime state remains clean**

Run:

```powershell
docker compose exec -T php php bin/migrate.php
docker compose ps php mysql redis qdrant rag-worker
```

Expected: `Applied 0 migration(s)` and every listed service healthy. Confirm `.env`, Docker Compose, Caddy, and Dockerfiles were not modified.
