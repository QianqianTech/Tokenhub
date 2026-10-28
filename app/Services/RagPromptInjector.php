<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class RagPromptInjector
{
    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $sources
     * @return array<string, mixed>
     */
    public function inject(array $payload, array $sources): array
    {
        unset($payload['rag']);
        if ($sources === []) {
            return $payload;
        }

        $context = $this->contextBlock($sources);
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            $payload['messages'] = $this->injectMessages($payload['messages'], $context);
            return $payload;
        }

        if (array_key_exists('input', $payload)) {
            $payload['input'] = $this->injectResponsesInput($payload['input'], $context);
            return $payload;
        }

        $payload['messages'] = [
            ['role' => 'system', 'content' => $context],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function injectMessages(array $messages, string $context): array
    {
        $firstRole = isset($messages[0]['role']) && is_scalar($messages[0]['role']) ? (string) $messages[0]['role'] : '';
        if ($firstRole === 'system') {
            $messages[0]['content'] = trim((string) ($messages[0]['content'] ?? '') . "\n\n" . $context);
            return $messages;
        }

        if (isset($messages[0]['role']) && (string) $messages[0]['role'] === 'developer') {
            array_splice($messages, 1, 0, [['role' => 'system', 'content' => $context]]);
            return $messages;
        }

        array_unshift($messages, ['role' => 'system', 'content' => $context]);
        return $messages;
    }

    private function injectResponsesInput(mixed $input, string $context): mixed
    {
        if (is_string($input)) {
            return $context . "\n\n" . $input;
        }

        if (is_array($input)) {
            array_unshift($input, [
                'role' => 'system',
                'content' => [['type' => 'input_text', 'text' => $context]],
            ]);
            return $input;
        }

        return $context;
    }

    /**
     * @param list<array<string, mixed>> $sources
     */
    private function contextBlock(array $sources): string
    {
        $lines = [
            'Use the following TokenHub knowledge base context when it is relevant. If the context is insufficient or conflicts with the user request, say so instead of inventing facts.',
            'Cite source_id values when answering factual questions.',
        ];
        foreach ($sources as $source) {
            $sourceId = (string) ($source['source_id'] ?? 'source');
            $title = trim((string) ($source['title'] ?? ''));
            $content = trim((string) ($source['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $modality = (string) ($source['modality'] ?? 'text');
            if ($modality === 'image' || $modality === 'multimodal') {
                $details = [];
                if (!empty($source['source_uri'])) {
                    $details[] = 'source_uri=' . (string) $source['source_uri'];
                }
                if (!empty($source['image_id'])) {
                    $details[] = 'image_id=' . (string) $source['image_id'];
                }
                $ai = is_array($source['ai_annotation'] ?? null) ? $source['ai_annotation'] : [];
                if (!empty($ai['short_description'])) {
                    $details[] = 'ai=' . (string) $ai['short_description'];
                }
                $content = '[image source] ' . $content . ($details === [] ? '' : ' (' . implode('; ', $details) . ')');
            }
            $prefix = $title !== '' ? "{$sourceId} {$title}" : $sourceId;
            $lines[] = '[' . $prefix . '] ' . $content;
        }

        return implode("\n", $lines);
    }
}
