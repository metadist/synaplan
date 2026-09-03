<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\ChartModel;

final class AddChartTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'add_chart';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Add a bar, line or pie chart on a sheet.', [
            'sheet' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['bar', 'line', 'pie']],
            'categoriesRange' => ['type' => 'string'],
            'valuesRange' => ['type' => 'string'],
            'anchor' => ['type' => 'string'],
        ], ['sheet', 'categoriesRange', 'valuesRange']);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::XLSX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $sheet = $session->spreadsheet()?->sheet((string) ($input['sheet'] ?? ''));
        if (null === $sheet) {
            return DocumentToolResult::error('Unknown sheet', 'processing.documentStepUnknownSheet', ['sheet' => (string) ($input['sheet'] ?? '')]);
        }
        $cats = A1Helper::expandRange((string) ($input['categoriesRange'] ?? ''));
        $vals = A1Helper::expandRange((string) ($input['valuesRange'] ?? ''));
        if ([] === $cats || [] === $vals) {
            return DocumentToolResult::error('Invalid chart ranges', 'processing.documentStepInvalidRange');
        }
        $sheet->charts[] = new ChartModel(
            'c_'.substr(md5((string) ($input['title'] ?? microtime())), 0, 8),
            is_string($input['type'] ?? null) ? (string) $input['type'] : 'bar',
            (string) ($input['title'] ?? ''),
            strtoupper((string) $input['categoriesRange']),
            strtoupper((string) $input['valuesRange']),
            is_string($input['anchor'] ?? null) ? (string) $input['anchor'] : 'D2',
        );

        return DocumentToolResult::ok('Chart added', 'processing.documentStepAddChart', ['sheet' => $sheet->name]);
    }
}
