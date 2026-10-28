<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class RagTemplateService
{
    private const TEMPLATES = [
        'tpl_text_v1' => [
            'template_id' => 'tpl_text_v1',
            'name' => '文本知识导入模板',
            'template_type' => 'text',
            'version' => '1.0',
            'filename' => 'tokenhub-rag-text-template.xlsx',
            'required_columns' => ['title', 'content'],
            'optional_columns' => ['category', 'tags', 'source_uri', 'language', 'enabled'],
        ],
        'tpl_qa_v1' => [
            'template_id' => 'tpl_qa_v1',
            'name' => '一问一答导入模板',
            'template_type' => 'qa',
            'version' => '1.0',
            'filename' => 'tokenhub-rag-qa-template.xlsx',
            'required_columns' => ['question', 'answer'],
            'optional_columns' => ['similar_questions', 'category', 'tags', 'source_uri', 'priority', 'enabled'],
        ],
        'tpl_image_v1' => [
            'template_id' => 'tpl_image_v1',
            'name' => '图片知识导入模板',
            'template_type' => 'image',
            'version' => '1.0',
            'filename' => 'tokenhub-rag-image-template.xlsx',
            'required_columns' => ['image', 'purpose', 'description'],
            'optional_columns' => ['title', 'detailed_description', 'tags', 'category', 'source_uri', 'enabled'],
        ],
        'tpl_image_package_v1' => [
            'template_id' => 'tpl_image_package_v1',
            'name' => 'RAG image package manifest template',
            'template_type' => 'image_package',
            'db_template_type' => 'image',
            'package_mode' => 'manifest_zip',
            'version' => '1.0',
            'filename' => 'tokenhub-rag-image-package-manifest.xlsx',
            'required_columns' => ['image', 'purpose', 'description'],
            'optional_columns' => ['title', 'detailed_description', 'objects', 'tags', 'category', 'source_uri', 'language', 'enabled'],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $this->ensureSeeded();
        return [
            'templates' => array_values(array_map(static fn (array $template): array => [
                ...$template,
                'download_url' => '/v1/rag/import-templates/' . $template['template_id'] . '/download',
            ], self::TEMPLATES)),
        ];
    }

    /**
     * @return array{filename: string, mime_type: string, body: string}
     */
    public function download(string $templateId): array
    {
        $template = self::TEMPLATES[$templateId] ?? null;
        if ($template === null) {
            abort_json('RAG_TEMPLATE_NOT_FOUND', 'Import template not found', 404);
        }

        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return [
                'filename' => $template['filename'],
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'body' => $this->xlsx($template),
            ];
        }

        return [
            'filename' => str_replace('.xlsx', '.csv', $template['filename']),
            'mime_type' => 'text/csv; charset=utf-8',
            'body' => $this->csv($template),
        ];
    }

    private function ensureSeeded(): void
    {
        foreach (self::TEMPLATES as $template) {
            App::db()->execute(
                'INSERT INTO rag_import_templates (template_id, name, template_type, version, filename, required_columns, optional_columns, style_profile, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), filename = VALUES(filename), required_columns = VALUES(required_columns), optional_columns = VALUES(optional_columns), enabled = 1',
                [
                    $template['template_id'],
                    $template['name'],
                    $template['db_template_type'] ?? $template['template_type'],
                    $template['version'],
                    $template['filename'],
                    json_encode($template['required_columns'], JSON_UNESCAPED_UNICODE),
                    json_encode($template['optional_columns'], JSON_UNESCAPED_UNICODE),
                    json_encode(['header' => 'required columns are highlighted', 'freeze' => 'A2'], JSON_UNESCAPED_UNICODE),
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $template
     */
    private function csv(array $template): string
    {
        $columns = [...$template['required_columns'], ...$template['optional_columns']];
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns);
        fputcsv($out, $this->sampleRow((string) $template['template_type'], $columns));
        rewind($out);
        return stream_get_contents($out) ?: '';
    }

    /**
     * @param array<string, mixed> $template
     */
    private function xlsx(array $template): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('导入数据');
        $columns = [...$template['required_columns'], ...$template['optional_columns']];
        $sample = $this->sampleRow((string) $template['template_type'], $columns);
        foreach ($columns as $index => $column) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . '1';
            $sheet->setCellValue($cell, in_array($column, $template['required_columns'], true) ? $column . '*' : $column);
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1))->setWidth(24);
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . '2', $sample[$column] ?? '');
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns)) . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($template['required_columns'])) . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');

        $info = $spreadsheet->createSheet();
        $info->setTitle('说明');
        $info->setCellValue('A1', '上传后先预览，确认后才批量向量化。必填列带 *，不要修改表头。');
        $info->setCellValue('A2', '图片列支持 URL、文件名、base64 或 Excel 内嵌图片。');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return (string) ob_get_clean();
    }

    /**
     * @param list<string> $columns
     * @return array<string, string>
     */
    private function sampleRow(string $type, array $columns): array
    {
        $samples = match ($type) {
            'image_package' => ['image' => 'images/product-error-e03.png', 'title' => 'Device E03 fault image', 'purpose' => 'Fault recognition', 'description' => 'Device screen shows E03.', 'detailed_description' => 'Red indicator is on and the screen code is E03.', 'objects' => 'screen|error_code|indicator', 'tags' => 'device,E03', 'category' => 'troubleshooting', 'source_uri' => 'manual://e03', 'language' => 'zh-CN', 'enabled' => '1'],
            'qa' => ['question' => '什么情况下可以退款？', 'answer' => '订单未发货时可以申请退款。', 'similar_questions' => '未发货能退吗|订单退款规则', 'category' => '售后', 'tags' => '退款,订单', 'enabled' => '1'],
            'image' => ['image' => 'product-error-e03.png', 'title' => '设备 E03 故障图', 'purpose' => '故障识别', 'description' => '设备屏幕显示 E03。', 'detailed_description' => '红色指示灯亮起，通常表示温度传感器异常。', 'tags' => '设备,E03', 'enabled' => '1'],
            default => ['title' => '退款流程', 'content' => '订单未发货时可以申请退款，发货后按售后流程处理。', 'category' => '售后', 'tags' => '退款,流程', 'language' => 'zh-CN', 'enabled' => '1'],
        };
        return array_intersect_key($samples, array_flip($columns));
    }
}
