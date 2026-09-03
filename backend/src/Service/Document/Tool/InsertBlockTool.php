<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\WordBlock;

final class InsertBlockTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'insert_block';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Insert a Word block. Types: heading, paragraph, table, image, toc, pagebreak.', [
            'type' => ['type' => 'string'],
            'text' => ['type' => 'string'],
            'level' => ['type' => 'integer'],
            'afterId' => ['type' => 'string'],
            'rows' => ['type' => 'array'],
        ], ['type']);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::DOCX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $word = $session->word();
        if (null === $word) {
            return DocumentToolResult::error('Not a Word document', 'processing.documentStepWrongKind');
        }
        $type = (string) ($input['type'] ?? WordBlock::TYPE_PARAGRAPH);
        $block = new WordBlock(uniqid('blk_', true), $type, [
            'text' => (string) ($input['text'] ?? ''),
            'level' => (int) ($input['level'] ?? 1),
            'rows' => $input['rows'] ?? [],
            'path' => $input['path'] ?? null,
        ]);
        $after = isset($input['afterId']) ? (string) $input['afterId'] : '';
        if ('' === $after) {
            $word->blocks[] = $block;
        } else {
            $idx = $word->findBlockIndex($after);
            if (null === $idx) {
                return DocumentToolResult::error('Unknown block', 'processing.documentStepUnknownBlock');
            }
            array_splice($word->blocks, $idx + 1, 0, [$block]);
        }

        return DocumentToolResult::ok('Inserted '.$block->id, 'processing.documentStepInsertBlock', ['type' => $type]);
    }
}
