<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\WordBlock;

final class InsertTocTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'insert_toc';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Insert a table of contents block at the start of the document.', []);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::DOCX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        unset($input);
        $word = $session->word();
        if (null === $word) {
            return DocumentToolResult::error('Not a Word document', 'processing.documentStepWrongKind');
        }
        array_unshift($word->blocks, new WordBlock(uniqid('toc_', true), WordBlock::TYPE_TOC));

        return DocumentToolResult::ok('Table of contents inserted', 'processing.documentStepInsertToc');
    }
}
