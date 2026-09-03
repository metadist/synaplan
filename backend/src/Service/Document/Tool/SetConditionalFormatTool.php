<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class SetConditionalFormatTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_conditional_format';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Remember a conditional format rule on a range (rendered as metadata).', [
            'sheet' => ['type' => 'string'],
            'range' => ['type' => 'string'],
            'rule' => ['type' => 'string', 'description' => 'Human-readable rule, e.g. greaterThan 1000'],
        ], ['sheet', 'range', 'rule']);
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
        $range = (string) ($input['range'] ?? '');
        if ([] === A1Helper::expandRange($range)) {
            return DocumentToolResult::error('Invalid range', 'processing.documentStepInvalidRange');
        }
        $sheet->conditionalFormats[] = [
            'range' => strtoupper($range),
            'rule' => (string) ($input['rule'] ?? ''),
        ];

        return DocumentToolResult::ok('Conditional format stored', 'processing.documentStepSetConditionalFormat', ['range' => $range]);
    }
}
