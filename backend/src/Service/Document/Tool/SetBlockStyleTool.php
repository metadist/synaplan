<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class SetBlockStyleTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_block_style';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Set heading level or emphasis on a Word block.', [
            'id' => ['type' => 'string'],
            'level' => ['type' => 'integer'],
            'bold' => ['type' => 'boolean'],
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
        if (isset($input['level'])) {
            $block->payload['level'] = (int) $input['level'];
        }
        if (isset($input['bold'])) {
            $block->payload['bold'] = (bool) $input['bold'];
        }

        return DocumentToolResult::ok('Style updated', 'processing.documentStepSetBlockStyle', ['id' => $block->id]);
    }
}
