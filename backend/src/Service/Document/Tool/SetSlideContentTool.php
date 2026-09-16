<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class SetSlideContentTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_slide_content';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Replace title and bullets on a slide.', [
            'index' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'bullets' => ['type' => 'array', 'items' => ['type' => 'string']],
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
        if (isset($input['title'])) {
            $deck->slides[$index]['title'] = (string) $input['title'];
        }
        if (isset($input['bullets']) && is_array($input['bullets'])) {
            $deck->slides[$index]['bullets'] = array_values(array_filter($input['bullets'], 'is_string'));
        }

        return DocumentToolResult::ok('Slide updated', 'processing.documentStepSetSlideContent', ['index' => $index]);
    }
}
