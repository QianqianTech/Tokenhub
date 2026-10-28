<?php

declare(strict_types=1);

namespace TokenHub\Services;

final class RagExcelImportService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $path, string $template): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'csv' || !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return $this->parseCsv($path, $template);
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if ($rows === []) {
            abort_json('RAG_EXCEL_EMPTY', 'Excel has no rows', 422);
        }
        $headers = $this->headers(array_shift($rows) ?: []);

        return $this->items($headers, $rows, $template);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsv(string $path, string $template): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            abort_json('RAG_INVALID_PAYLOAD', 'Upload file is not readable', 422);
        }
        $first = fgetcsv($handle, 0, ',', '"', '\\');
        if ($first === false) {
            abort_json('RAG_EXCEL_EMPTY', 'Excel has no rows', 422);
        }
        $headers = $this->headers($first);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $this->items($headers, $rows, $template);
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array<int|string, string>
     */
    private function headers(array $row): array
    {
        $headers = [];
        foreach ($row as $key => $value) {
            $headers[$key] = rtrim(trim((string) $value), '*');
        }
        return $headers;
    }

    /**
     * @param array<int|string, string> $headers
     * @param list<array<int|string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function items(array $headers, array $rows, string $template): array
    {
        $required = match ($template) {
            'qa' => ['question', 'answer'],
            'image' => ['image', 'purpose', 'description'],
            default => ['title', 'content'],
        };
        foreach ($required as $column) {
            if (!in_array($column, $headers, true)) {
                abort_json('RAG_EXCEL_MISSING_COLUMN', 'Missing required column: ' . $column, 422);
            }
        }

        $items = [];
        foreach ($rows as $offset => $row) {
            $data = [];
            foreach ($headers as $key => $name) {
                if ($name !== '') {
                    $data[$name] = isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';
                }
            }
            if (implode('', $data) === '') {
                continue;
            }
            $items[] = $this->item($data, $template, $offset + 2);
        }

        return $items;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private function item(array $data, string $template, int $row): array
    {
        $errors = [];
        if ($template === 'qa') {
            foreach (['question', 'answer'] as $required) {
                if (($data[$required] ?? '') === '') {
                    $errors[] = $required . ' is required';
                }
            }
            return [
                'item_type' => 'qa',
                'title' => $data['question'] ?? 'QA',
                'question' => $data['question'] ?? '',
                'answer' => $data['answer'] ?? '',
                'content' => '问题：' . ($data['question'] ?? '') . "\n答案：" . ($data['answer'] ?? ''),
                'metadata' => ['similar_questions' => $data['similar_questions'] ?? '', 'category' => $data['category'] ?? '', 'tags' => $data['tags'] ?? ''],
                'row_number' => $row,
                'validation_status' => $errors === [] ? 'valid' : 'error',
                'validation_errors' => $errors,
            ];
        }
        if ($template === 'image') {
            foreach (['image', 'purpose', 'description'] as $required) {
                if (($data[$required] ?? '') === '') {
                    $errors[] = $required . ' is required';
                }
            }
            $metadata = [
                'image' => $data['image'] ?? '',
                'category' => $data['category'] ?? '',
                'tags' => $data['tags'] ?? '',
                'objects' => $data['objects'] ?? '',
                'source_uri' => $data['source_uri'] ?? '',
                'language' => $data['language'] ?? '',
                'enabled' => $data['enabled'] ?? '',
                'human_annotation' => [
                    'title' => $data['title'] ?? '',
                    'purpose' => $data['purpose'] ?? '',
                    'description' => $data['description'] ?? '',
                    'detailed_description' => $data['detailed_description'] ?? '',
                    'objects' => $data['objects'] ?? '',
                    'tags' => $data['tags'] ?? '',
                ],
            ];
            $contentParts = [
                'Image purpose: ' . ($data['purpose'] ?? ''),
                'Image description: ' . ($data['description'] ?? ''),
                'Detailed description: ' . ($data['detailed_description'] ?? ''),
                'Objects: ' . ($data['objects'] ?? ''),
                'Tags: ' . ($data['tags'] ?? ''),
            ];
            return [
                'item_type' => 'image',
                'title' => $data['title'] ?? ($data['image'] ?? 'image'),
                'content' => trim(implode("\n", array_filter($contentParts, static fn (string $part): bool => trim(substr($part, (int) strpos($part, ':') + 1)) !== ''))),
                'purpose' => $data['purpose'] ?? '',
                'description' => $data['description'] ?? '',
                'detailed_description' => $data['detailed_description'] ?? '',
                'metadata' => $metadata,
                'row_number' => $row,
                'validation_status' => $errors === [] ? 'valid' : 'error',
                'validation_errors' => $errors,
            ];
        }

        foreach (['title', 'content'] as $required) {
            if (($data[$required] ?? '') === '') {
                $errors[] = $required . ' is required';
            }
        }
        return [
            'item_type' => 'text',
            'title' => $data['title'] ?? 'text',
            'content' => $data['content'] ?? '',
            'metadata' => ['category' => $data['category'] ?? '', 'tags' => $data['tags'] ?? '', 'language' => $data['language'] ?? ''],
            'row_number' => $row,
            'validation_status' => $errors === [] ? 'valid' : 'error',
            'validation_errors' => $errors,
        ];
    }
}
