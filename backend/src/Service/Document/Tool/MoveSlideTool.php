<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class MoveSlideTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'move_slide';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Move a slide to a new index.', [
            'from' => ['type' => 'integer'],
            'to' => ['type' => 'integer'],
        ], ['from', 'to']);
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
        $from = (int) ($input['from'] ?? -1);
        $to = (int) ($input['to'] ?? -1);
        if (!isset($deck->slides[$from]) || $to < 0 || $to > count($deck->slides)) {
            return DocumentToolResult::error('Invalid slide index', 'processing.documentStepUnknownSlide');
        }
        $slide = $deck->slides[$from];
        array_splice($deck->slides, $from, 1);
        array_splice($deck->slides, min($to, count($deck->slides)), 0, [$slide]);

        return DocumentToolResult::ok('Slide moved', 'processing.documentStepMoveSlide');
    }
}
