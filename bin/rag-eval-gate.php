<?php

declare(strict_types=1);

use TokenHub\Services\RagRetrievalEvaluationService;

require_once __DIR__ . '/../app/Bootstrap/autoload.php';

try {
    $options = [];
    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];
        [$name, $value] = str_contains($argument, '=')
            ? explode('=', $argument, 2)
            : [$argument, null];
        if (in_array($name, ['--dataset', '--case', '--min-recall-at-5', '--min-mrr'], true) && $value === null) {
            $value = $argv[++$index] ?? null;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('An option value is required.');
        }

        switch ($name) {
            case '--dataset':
                $options['dataset_ids'][] = $value;
                break;
            case '--case':
                $options['case_ids'][] = $value;
                break;
            case '--min-recall-at-5':
                $options['min_recall_at_5'] = gateNumber($value, 100.0);
                break;
            case '--min-mrr':
                $options['min_mrr'] = gateNumber($value, 1.0);
                break;
            default:
                throw new InvalidArgumentException('Unknown option.');
        }
    }

    $result = (new RagRetrievalEvaluationService())->runCli($options);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit($result['quality_gate_passed'] ? 0 : 2);
} catch (Throwable) {
    fwrite(STDOUT, json_encode([
        'error' => 'RAG_EVAL_GATE_RUNTIME_ERROR',
        'message' => 'RAG evaluation gate could not run.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

function gateNumber(string $value, float $maximum): float
{
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > $maximum) {
        throw new InvalidArgumentException('The threshold must be numeric.');
    }

    return (float) $value;
}
