<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class ReadDeckTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'read_deck';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'List slides with titles and a short body preview.', []);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::PPTX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        unset($input);
        $deck = $session->deck();
        if (null === $deck) {
            return DocumentToolResult::error('Not a presentation', 'processing.documentStepWrongKind');
        }
        $slides = [];
        foreach ($deck->slides as $i => $slide) {
            $slides[] = [
                'index' => $i,
                'title' => $slide['title'] ?? null,
                'bullets' => $slide['bullets'] ?? [],
            ];
        }

        return DocumentToolResult::read(json_encode([
            'theme' => $deck->theme,
            'slides' => $slides,
        ], JSON_THROW_ON_ERROR), 'processing.documentStepReadDeck');
    }
}
