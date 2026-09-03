<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class SetThemeTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'set_theme';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Set the presentation theme (default, ocean, midnight, sunset, forest, mono).', [
            'theme' => ['type' => 'string'],
            'transition' => ['type' => 'string'],
        ], ['theme']);
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
        $deck->theme = (string) ($input['theme'] ?? $deck->theme);
        if (isset($input['transition']) && is_string($input['transition'])) {
            $deck->transition = $input['transition'];
        }

        return DocumentToolResult::ok('Theme updated', 'processing.documentStepSetTheme', ['theme' => $deck->theme]);
    }
}
