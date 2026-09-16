<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class AddSlideTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'add_slide';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Append a slide.', [
            'title' => ['type' => 'string'],
            'bullets' => ['type' => 'array', 'items' => ['type' => 'string']],
            'titleSlide' => ['type' => 'boolean'],
        ], []);
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
        $deck->slides[] = [
            'title' => (string) ($input['title'] ?? ''),
            'bullets' => is_array($input['bullets'] ?? null) ? array_values(array_filter($input['bullets'], 'is_string')) : [],
            'titleSlide' => (bool) ($input['titleSlide'] ?? false),
            'imageReferences' => [],
        ];

        return DocumentToolResult::ok('Slide added', 'processing.documentStepAddSlide', ['index' => count($deck->slides) - 1]);
    }
}
