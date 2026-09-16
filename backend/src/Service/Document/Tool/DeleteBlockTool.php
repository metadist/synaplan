<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class DeleteBlockTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'delete_block';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Delete a Word block by id.', [
            'id' => ['type' => 'string'],
        ], ['id']);
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
        $idx = $word->findBlockIndex((string) ($input['id'] ?? ''));
        if (null === $idx) {
            return DocumentToolResult::error('Unknown block', 'processing.documentStepUnknownBlock');
        }
        array_splice($word->blocks, $idx, 1);

        return DocumentToolResult::ok('Block deleted', 'processing.documentStepDeleteBlock');
    }
}
