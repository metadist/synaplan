<?php

declare(strict_types=1);

namespace App\Service\Document\Import;

use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

final class WordImporter
{
    /**
     * Best-effort DOCX import. Formatting beyond headings/paragraphs is dropped.
     *
     * @return array{model: WordModel, report: ImportFidelityReport}
     */
    public function import(string $absolutePath): array
    {
        $phpWord = IOFactory::load($absolutePath);
        $blocks = [];
        $notes = ['Images, headers, footers and most styles are not imported.'];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $block = $this->fromElement($element);
                if (null !== $block) {
                    $blocks[] = $block;
                }
            }
        }

        return [
            'model' => new WordModel($blocks),
            'report' => ImportFidelityReport::lossy($notes),
        ];
    }

    private function fromElement(AbstractElement $element): ?WordBlock
    {
        if ($element instanceof Title) {
            return new WordBlock(uniqid('h_', true), WordBlock::TYPE_HEADING, [
                'text' => $element->getText(),
                'level' => (int) $element->getDepth(),
            ]);
        }
        if ($element instanceof Text) {
            return new WordBlock(uniqid('p_', true), WordBlock::TYPE_PARAGRAPH, [
                'text' => $element->getText(),
            ]);
        }
        if ($element instanceof TextRun) {
            $text = '';
            foreach ($element->getElements() as $child) {
                if ($child instanceof Text) {
                    $text .= $child->getText();
                }
            }

            return new WordBlock(uniqid('p_', true), WordBlock::TYPE_PARAGRAPH, ['text' => $text]);
        }
        if ($element instanceof TextBreak) {
            return null;
        }

        return null;
    }
}
