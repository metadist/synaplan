<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\WordBlock;

final class InsertImageTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'insert_image';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Insert an image. Word: a block. Slides: image reference on a slide.', [
            'path' => ['type' => 'string'],
            'reference' => ['type' => 'string', 'description' => 'file:ID marker for slides'],
            'slide' => ['type' => 'integer'],
        ], []);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::DOCX, DocumentKind::PPTX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        if (null !== $session->word()) {
            $session->word()->blocks[] = new WordBlock(uniqid('img_', true), WordBlock::TYPE_IMAGE, [
                'path' => (string) ($input['path'] ?? ''),
            ]);

            return DocumentToolResult::ok('Image block added', 'processing.documentStepInsertImage');
        }
        $deck = $session->deck();
        if (null === $deck) {
            return DocumentToolResult::error('Not a document that accepts images', 'processing.documentStepWrongKind');
        }
        $index = (int) ($input['slide'] ?? 0);
        if (!isset($deck->slides[$index])) {
            return DocumentToolResult::error('Unknown slide', 'processing.documentStepUnknownSlide');
        }
        $refs = $deck->slides[$index]['imageReferences'] ?? [];
        if (!is_array($refs)) {
            $refs = [];
        }
        $refs[] = (string) ($input['reference'] ?? $input['path'] ?? '');
        $deck->slides[$index]['imageReferences'] = $refs;

        return DocumentToolResult::ok('Image added to slide', 'processing.documentStepInsertImage');
    }
}
