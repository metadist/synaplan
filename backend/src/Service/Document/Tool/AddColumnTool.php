<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class AddColumnTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'add_column';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Insert an empty column, shifting existing columns right.', [
            'sheet' => ['type' => 'string'],
            'column' => ['type' => 'string', 'description' => 'Column letter to insert at, e.g. D'],
        ], ['sheet', 'column']);
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
        $col = strtoupper(preg_replace('/[^A-Z]/', '', (string) ($input['column'] ?? '')) ?? '');
        if ('' === $col) {
            return DocumentToolResult::error('Invalid column', 'processing.documentStepInvalidRange');
        }
        $at = A1Helper::columnIndex($col);
        $moved = [];
        foreach ($sheet->cells as $address => $cell) {
            $parts = A1Helper::split($address);
            if (null === $parts) {
                continue;
            }
            $idx = A1Helper::columnIndex($parts[0]);
            $letter = $idx >= $at ? A1Helper::columnLetter($idx + 1) : $parts[0];
            $moved[$letter.$parts[1]] = $cell;
        }
        $sheet->cells = $moved;

        return DocumentToolResult::ok('Column inserted', 'processing.documentStepAddColumn', ['column' => $col]);
    }
}
