<?php

declare(strict_types=1);

namespace App\Service\Document\Render;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordModel;

final readonly class DocumentRenderer
{
    public function __construct(
        private XlsxRenderer $xlsxRenderer,
        private DocxRenderer $docxRenderer,
        private PptxRendererAdapter $pptxRendererAdapter,
    ) {
    }

    public function render(SpreadsheetModel|WordModel|DeckModel $model, string $absolutePath): string
    {
        if ($model instanceof SpreadsheetModel) {
            $this->xlsxRenderer->render($model, $absolutePath);

            return DocumentKind::XLSX;
        }
        if ($model instanceof WordModel) {
            $this->docxRenderer->render($model, $absolutePath);

            return DocumentKind::DOCX;
        }
        $this->pptxRendererAdapter->render($model, $absolutePath);

        return DocumentKind::PPTX;
    }
}
