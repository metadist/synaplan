<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class ReadRangeTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'read_range';
    }

    public function declaration(): array
    {
        return $this->fn(
            $this->name(),
            'Read cells in an A1 range on a sheet. Prefer ranges over whole sheets.',
            [
                'sheet' => ['type' => 'string'],
                'range' => ['type' => 'string', 'description' => 'A1 range such as B2:D20'],
            ],
            ['sheet', 'range'],
        );
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
        $addresses = A1Helper::expandRange((string) ($input['range'] ?? ''));
        if ([] === $addresses) {
            return DocumentToolResult::error('Invalid range', 'processing.documentStepInvalidRange');
        }
        $rows = [];
        foreach ($addresses as $address) {
            $cell = $sheet->getCell($address);
            $rows[$address] = null === $cell ? null : [
                'value' => $cell->value,
                'formula' => $cell->formula,
                'numberFormat' => $cell->numberFormat,
            ];
        }

        return DocumentToolResult::read(json_encode($rows, JSON_THROW_ON_ERROR), 'processing.documentStepReadRange', ['range' => (string) $input['range']]);
    }
}
