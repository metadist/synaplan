<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\WordBlock;

final class ReadOutlineTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'read_outline';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'List Word blocks (id, type, heading/paragraph preview).', []);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::DOCX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        unset($input);
        $word = $session->word();
        if (null === $word) {
            return DocumentToolResult::error('Not a Word document', 'processing.documentStepWrongKind');
        }
        $outline = [];
        foreach ($word->blocks as $block) {
            $text = (string) ($block->payload['text'] ?? '');
            $outline[] = [
                'id' => $block->id,
                'type' => $block->type,
                'preview' => mb_substr($text, 0, 120),
                'level' => $block->payload['level'] ?? (WordBlock::TYPE_HEADING === $block->type ? 1 : null),
            ];
        }

        return DocumentToolResult::read(json_encode($outline, JSON_THROW_ON_ERROR), 'processing.documentStepReadOutline');
    }
}
