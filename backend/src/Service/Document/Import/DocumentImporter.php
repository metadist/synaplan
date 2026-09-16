<?php

declare(strict_types=1);

namespace App\Service\Document\Import;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordModel;

final readonly class DocumentImporter
{
    public function __construct(
        private SpreadsheetImporter $spreadsheets,
        private WordImporter $words,
        private DeckImporter $decks,
    ) {
    }

    /**
     * @return array{model: SpreadsheetModel|WordModel|DeckModel, report: ImportFidelityReport}|null
     */
    public function import(string $absolutePath, string $extension): ?array
    {
        $kind = DocumentKind::fromExtension($extension);
        if (null === $kind || !is_file($absolutePath)) {
            return null;
        }
        try {
            return match ($kind) {
                DocumentKind::XLSX => $this->spreadsheets->import($absolutePath),
                DocumentKind::DOCX => $this->words->import($absolutePath),
                DocumentKind::PPTX => $this->decks->import($absolutePath),
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
