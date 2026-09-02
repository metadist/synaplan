<?php

declare(strict_types=1);

namespace App\Service\Document\Render;

use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;
use App\Service\File\Office\DocxStyleSheet;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Deterministic DOCX writer. Reuses {@see DocxStyleSheet} from A5.
 */
final class DocxRenderer
{
    public function render(WordModel $model, string $absolutePath): void
    {
        $phpWord = new PhpWord();
        DocxStyleSheet::apply($phpWord);
        $section = $phpWord->addSection();

        foreach ($model->blocks as $block) {
            $this->writeBlock($section, $block);
        }

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        IOFactory::createWriter($phpWord, 'Word2007')->save($absolutePath);
    }

    private function writeBlock(\PhpOffice\PhpWord\Element\Section $section, WordBlock $block): void
    {
        $payload = $block->payload;
        match ($block->type) {
            WordBlock::TYPE_HEADING => $section->addTitle(
                (string) ($payload['text'] ?? ''),
                max(1, min(6, (int) ($payload['level'] ?? 1))),
            ),
            WordBlock::TYPE_PARAGRAPH => $section->addText((string) ($payload['text'] ?? '')),
            WordBlock::TYPE_PAGE_BREAK => $section->addPageBreak(),
            WordBlock::TYPE_TOC => $section->addTOC(),
            WordBlock::TYPE_TABLE => $this->writeTable($section, $payload),
            WordBlock::TYPE_IMAGE => $this->writeImage($section, $payload),
            default => $section->addText((string) ($payload['text'] ?? '')),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeTable(\PhpOffice\PhpWord\Element\Section $section, array $payload): void
    {
        $rows = $payload['rows'] ?? [];
        if (!is_array($rows) || [] === $rows) {
            return;
        }
        $table = $section->addTable(DocxStyleSheet::TABLE_STYLE);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $table->addRow();
            foreach ($row as $cell) {
                $table->addCell(2000)->addText((string) $cell);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeImage(\PhpOffice\PhpWord\Element\Section $section, array $payload): void
    {
        $path = $payload['path'] ?? null;
        if (!is_string($path) || !is_file($path)) {
            return;
        }
        $section->addImage($path, ['width' => 400]);
    }
}
