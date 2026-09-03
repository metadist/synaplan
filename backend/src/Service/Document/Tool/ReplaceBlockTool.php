<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class ReplaceBlockTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'replace_block';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Replace the text or table rows of a Word block.', [
            'id' => ['type' => 'string'],
            'text' => ['type' => 'string'],
            'rows' => ['type' => 'array'],
        ], ['id']);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::DOCX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $block = $session->word()?->findBlock((string) ($input['id'] ?? ''));
        if (null === $block) {
            return DocumentToolResult::error('Unknown block', 'processing.documentStepUnknownBlock');
        }
        if (isset($input['text'])) {
            $block->payload['text'] = (string) $input['text'];
        }
        if (isset($input['rows']) && is_array($input['rows'])) {
            $block->payload['rows'] = $input['rows'];
        }

        return DocumentToolResult::ok('Block updated', 'processing.documentStepReplaceBlock', ['id' => $block->id]);
    }
}
