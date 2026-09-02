<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\WordBlock;

final class SetTableCellTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_table_cell';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Set one cell in a Word table block (0-based row/col).', [
            'id' => ['type' => 'string'],
            'row' => ['type' => 'integer'],
            'col' => ['type' => 'integer'],
            'text' => ['type' => 'string'],
        ], ['id', 'row', 'col', 'text']);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::DOCX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $block = $session->word()?->findBlock((string) ($input['id'] ?? ''));
        if (null === $block || WordBlock::TYPE_TABLE !== $block->type) {
            return DocumentToolResult::error('Not a table block', 'processing.documentStepUnknownBlock');
        }
        $row = (int) ($input['row'] ?? -1);
        $col = (int) ($input['col'] ?? -1);
        $rows = $block->payload['rows'] ?? [];
        if (!is_array($rows) || !isset($rows[$row]) || !is_array($rows[$row])) {
            return DocumentToolResult::error('Cell out of range', 'processing.documentStepInvalidRange');
        }
        $rows[$row][$col] = (string) ($input['text'] ?? '');
        $block->payload['rows'] = $rows;

        return DocumentToolResult::ok('Table cell updated', 'processing.documentStepSetTableCell');
    }
}
