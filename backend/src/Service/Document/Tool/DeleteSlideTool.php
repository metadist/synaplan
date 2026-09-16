<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class DeleteSlideTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'delete_slide';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Delete a slide by index.', [
            'index' => ['type' => 'integer'],
        ], ['index']);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::PPTX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $deck = $session->deck();
        if (null === $deck) {
            return DocumentToolResult::error('Not a presentation', 'processing.documentStepWrongKind');
        }
        $index = (int) ($input['index'] ?? -1);
        if (!isset($deck->slides[$index])) {
            return DocumentToolResult::error('Unknown slide', 'processing.documentStepUnknownSlide');
        }
        array_splice($deck->slides, $index, 1);

        return DocumentToolResult::ok('Slide deleted', 'processing.documentStepDeleteSlide');
    }
}
