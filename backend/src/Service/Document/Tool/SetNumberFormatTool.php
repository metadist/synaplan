<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\CellModel;

final class SetNumberFormatTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_number_format';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Apply a number format to a range (for example currency).', [
            'sheet' => ['type' => 'string'],
            'range' => ['type' => 'string'],
            'format' => ['type' => 'string', 'description' => 'Excel format code such as "$"#,##0.00'],
        ], ['sheet', 'range', 'format']);
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
        $format = (string) ($input['format'] ?? '');
        $addresses = A1Helper::expandRange((string) ($input['range'] ?? ''));
        if ([] === $addresses) {
            return DocumentToolResult::error('Invalid range', 'processing.documentStepInvalidRange');
        }
        foreach ($addresses as $address) {
            $existing = $sheet->getCell($address) ?? new CellModel();
            $sheet->setCell($address, new CellModel($existing->value, $existing->type, $existing->formula, $format, $existing->style));
        }

        return DocumentToolResult::ok('Format applied', 'processing.documentStepSetNumberFormat', ['range' => (string) $input['range']]);
    }
}
