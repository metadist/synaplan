<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;

final class AddSheetTool extends AbstractDocumentTool
{
    public function name(): string
    {
        return 'add_sheet';
    }

    public function declaration(): array
    {
        return $this->fn($this->name(), 'Add a worksheet.', [
            'name' => ['type' => 'string'],
        ], ['name']);
    }

    public function appliesTo(): array
    {
        return [DocumentKind::XLSX];
    }

    public function execute(DocumentSession $session, array $input): DocumentToolResult
    {
        $book = $session->spreadsheet();
        if (null === $book) {
            return DocumentToolResult::error('Not a spreadsheet', 'processing.documentStepWrongKind');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ('' === $name) {
            return DocumentToolResult::error('Sheet name required', 'processing.documentStepInvalidSheetName');
        }
        try {
            $book->addSheet($name);
        } catch (\InvalidArgumentException $e) {
            return DocumentToolResult::error($e->getMessage(), 'processing.documentStepSheetExists', ['sheet' => $name]);
        }

        return DocumentToolResult::ok('Added sheet '.$name, 'processing.documentStepAddSheet', ['sheet' => $name]);
    }
}
