<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class AddRowTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'add_row';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Insert an empty row, shifting existing rows down.', [
            'sheet' => ['type' => 'string'],
            'row' => ['type' => 'integer'],
        ], ['sheet', 'row']);
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
        $row = (int) ($input['row'] ?? 0);
        if ($row < 1) {
            return DocumentToolResult::error('row must be >= 1', 'processing.documentStepInvalidRange');
        }
        $moved = [];
        foreach ($sheet->cells as $address => $cell) {
            $parts = A1Helper::split($address);
            if (null === $parts) {
                continue;
            }
            if ($parts[1] >= $row) {
                $moved[A1Helper::columnLetter(A1Helper::columnIndex($parts[0])).($parts[1] + 1)] = $cell;
            } else {
                $moved[$address] = $cell;
            }
        }
        $sheet->cells = $moved;

        return DocumentToolResult::ok('Row inserted', 'processing.documentStepAddRow', ['row' => $row]);
    }
}
