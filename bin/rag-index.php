<?php

declare(strict_types=1);

/*
 * RAG index operations (RAG-030 / RAG-032 / RAG-042).
 *
 *   php bin/rag-index.php health [--dataset=ds_x]
 *   php bin/rag-index.php reconcile [--dataset=ds_x] [--fix]
 *   php bin/rag-index.php backfill-lexical --dataset=ds_x | --all
 *   php bin/rag-index.php reindex --dataset=ds_x [--config=<vector config id>] [--skip-gate]
 *                                 [--max-recall-drop=2] [--max-mrr-drop=0.02] [--dry-run]
 *   php bin/rag-index.php remap-eval [--dataset=ds_x]    re-point evaluation cases by their anchors
 *   php bin/rag-index.php calibrate --dataset=ds_x [--target-recall=0.95] [--apply]
 *                                 suggest (and with --apply store) the embedding model's vector threshold
 *   php bin/rag-index.php compact-vectors [--batch=500]   rewrite stored vectors as compact float32
 *
 * reindex builds a new Qdrant collection next to the live one and swaps the alias only after
 * the evaluation gate passes; --config switches the dataset to another embedding model.
 * Output is JSON. Exit code 2 when the gate blocked the switch, 1 on errors.
 */

use TokenHub\Services\RagIndexMaintenanceService;
use TokenHub\Support\App;

require_once __DIR__ . '/../app/Bootstrap/autoload.php';

$command = $argv[1] ?? 'help';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m) === 1) {
        $options[$m[1]] = $m[2] ?? true;
    }
}
$datasetPk = static function (string $externalId): int {
    $row = App::db()->first('SELECT id FROM rag_vector_datasets WHERE dataset_id = ? AND deleted_at IS NULL', [$externalId]);
    if ($row === null) {
        throw new InvalidArgumentException('Unknown dataset ' . $externalId);
    }
    return (int) $row['id'];
};

try {
    $service = new RagIndexMaintenanceService();
    $result = match ($command) {
        'health' => $service->health(isset($options['dataset']) ? [$datasetPk((string) $options['dataset'])] : null),
        'reconcile' => $service->reconcile(isset($options['dataset']) ? $datasetPk((string) $options['dataset']) : null, isset($options['fix'])),
        'backfill-lexical' => isset($options['all'])
            ? array_map(static fn (array $row): array => $service->backfillLexical((int) $row['id']), App::db()->select('SELECT id FROM rag_vector_datasets WHERE deleted_at IS NULL AND lexical_index_status <> "ready"'))
            : $service->backfillLexical($datasetPk((string) ($options['dataset'] ?? ''))),
        'reindex' => $service->reindex(
            $datasetPk((string) ($options['dataset'] ?? '')),
            isset($options['config']) ? (int) $options['config'] : null,
            isset($options['skip-gate']),
            (float) ($options['max-recall-drop'] ?? 2.0),
            (float) ($options['max-mrr-drop'] ?? 0.02),
            isset($options['dry-run']),
        ),
        'remap-eval' => (new \TokenHub\Services\RagEvaluationAnchorService())->remap(isset($options['dataset']) ? $datasetPk((string) $options['dataset']) : null),
        'calibrate' => $service->calibrate($datasetPk((string) ($options['dataset'] ?? '')), (float) ($options['target-recall'] ?? 0.95), isset($options['apply'])),
        'compact-vectors' => \TokenHub\Services\RagVectorCodec::compactStoredVectors((int) ($options['batch'] ?? 500)),
        default => throw new InvalidArgumentException('Usage: php bin/rag-index.php health|reconcile|backfill-lexical|reindex|remap-eval|calibrate|compact-vectors [options]'),
    };
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit($command === 'reindex' && ($result['gate']['passed'] ?? true) === false ? 2 : 0);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
