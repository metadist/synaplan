<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\CellModel;

final class SetCellsTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_cells';
    }

    public function declaration(): array
    {
        return $this->fn(
            $this->name(),
            'Set one or more cell values. Each item is {address, value, type?}.',
            [
                'sheet' => ['type' => 'string'],
                'cells' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
            ['sheet', 'cells'],
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
        $cells = $input['cells'] ?? [];
        if (!is_array($cells) || [] === $cells) {
            return DocumentToolResult::error('cells must be a non-empty array', 'processing.documentStepInvalidCells');
        }
        $count = 0;
        foreach ($cells as $row) {
            if (!is_array($row) || !isset($row['address'])) {
                continue;
            }
            $address = strtoupper((string) $row['address']);
            if (null === A1Helper::split($address)) {
                return DocumentToolResult::error('Invalid address '.$address, 'processing.documentStepInvalidRange');
            }
            $existing = $sheet->getCell($address);
            $type = is_string($row['type'] ?? null) ? $row['type'] : (is_numeric($row['value'] ?? null) ? 'number' : 'string');
            $sheet->setCell($address, new CellModel(
                $row['value'] ?? null,
                $type,
                $existing?->formula,
                $existing?->numberFormat,
                $existing?->style,
            ));
            ++$count;
        }

        return DocumentToolResult::ok(sprintf('Updated %d cells', $count), 'processing.documentStepSetCells', ['count' => $count]);
    }
}
