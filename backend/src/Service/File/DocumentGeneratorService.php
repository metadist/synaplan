<?php

declare(strict_types=1);

namespace App\Service\File;

use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as WordSettings;
use PhpOffice\PhpWord\Shared\Html as WordHtml;
use Psr\Log\LoggerInterface;

/**
 * Generates real, openable office documents from AI-generated content.
 *
 * AI file generation returns the document body as plain text/markdown. For
 * text formats (csv, txt, md, …) that content can be written verbatim, but
 * office formats (docx, xlsx, pptx) are OOXML/ZIP containers. Writing raw text
 * with an office extension produces a corrupt file that Word/Excel refuse to
 * open ("unreadable content"). This service builds valid OOXML files using the
 * PhpOffice libraries instead.
 */
final readonly class DocumentGeneratorService
{
    /** Office formats that must be produced as real OOXML binaries. */
    private const BINARY_FORMATS = ['docx', 'xlsx', 'pptx'];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the given extension requires real binary OOXML generation.
     */
    public function isBinaryFormat(string $extension): bool
    {
        return in_array(strtolower($extension), self::BINARY_FORMATS, true);
    }

    /**
     * Write content to disk using the correct encoding for the extension.
     *
     * Office formats are rendered as valid OOXML; all other formats are
     * written as UTF-8 text.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function write(string $content, string $extension, string $absolutePath): void
    {
        switch (strtolower($extension)) {
            case 'docx':
                $this->writeDocx($content, $absolutePath);
                break;
            case 'xlsx':
                $this->writeXlsx($content, $absolutePath);
                break;
            case 'pptx':
                $this->writePptx($content, $absolutePath);
                break;
            default:
                if (false === file_put_contents($absolutePath, $content)) {
                    throw new \RuntimeException('Failed to write file: '.$absolutePath);
                }
        }
    }

    /**
     * Build a Word document. The AI content is treated as markdown and
     * converted to HTML so headings, lists, bold text and tables are kept.
     * If HTML parsing fails, fall back to plain paragraphs so the file is
     * still valid and openable.
     */
    private function writeDocx(string $content, string $absolutePath): void
    {
        $html = (new \Parsedown())->text($content);

        // Ensure special characters (like '&', '<', '>') are escaped in the XML to prevent document corruption.
        WordSettings::setOutputEscapingEnabled(true);

        try {
            $phpWord = new PhpWord();
            $section = $phpWord->addSection();
            WordHtml::addHtml($section, $html, false, false);
        } catch (\Throwable $e) {
            $this->logger->warning('DocumentGeneratorService: DOCX HTML parsing failed, using plain text fallback', [
                'error' => $e->getMessage(),
            ]);

            $phpWord = new PhpWord();
            $section = $phpWord->addSection();
            foreach ($this->splitLines($content) as $line) {
                if ('' === trim($line)) {
                    $section->addTextBreak();
                } else {
                    $section->addText($line);
                }
            }
        }

        WordIOFactory::createWriter($phpWord, 'Word2007')->save($absolutePath);
    }

    /**
     * Build an Excel workbook. CSV-style content is split into rows/columns,
     * otherwise each line becomes a single cell in column A.
     */
    private function writeXlsx(string $content, string $absolutePath): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $lines = $this->splitLines(trim($content));
        $looksLikeCsv = $this->looksLikeCsv($lines);

        $rowIndex = 1;
        foreach ($lines as $line) {
            if ($looksLikeCsv) {
                $cells = str_getcsv($line, ',', '"', '');
                $colIndex = 1;
                foreach ($cells as $cell) {
                    $sheet->setCellValueExplicit(
                        [$colIndex, $rowIndex],
                        (string) $cell,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    );
                    ++$colIndex;
                }
            } else {
                $sheet->setCellValueExplicit(
                    [1, $rowIndex],
                    $line,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
            ++$rowIndex;
        }

        (new XlsxWriter($spreadsheet))->save($absolutePath);
    }

    /**
     * Build a PowerPoint presentation. The content is split into slides on
     * markdown headings; each slide gets a single text box with the (lightly
     * de-marked) lines of that section.
     */
    private function writePptx(string $content, string $absolutePath): void
    {
        $presentation = new PhpPresentation();
        $slides = $this->splitIntoSlides($content);

        $isFirst = true;
        foreach ($slides as $slideText) {
            $slide = $isFirst ? $presentation->getActiveSlide() : $presentation->createSlide();
            $isFirst = false;

            $shape = $slide->createRichTextShape();
            $shape->setHeight(450)->setWidth(900)->setOffsetX(30)->setOffsetY(30);

            $lines = $this->splitLines(trim($slideText));
            foreach ($lines as $index => $line) {
                $paragraph = 0 === $index ? $shape->getActiveParagraph() : $shape->createParagraph();
                $clean = $this->stripMarkdown($line);
                $paragraph->createTextRun('' === $clean ? ' ' : $clean);
            }
        }

        PresentationIOFactory::createWriter($presentation, 'PowerPoint2007')->save($absolutePath);
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $content): array
    {
        return preg_split('/\R/', $content) ?: [];
    }

    /**
     * Heuristic: treat content as CSV when most non-empty lines contain a comma.
     *
     * @param list<string> $lines
     */
    private function looksLikeCsv(array $lines): bool
    {
        $nonEmpty = array_filter($lines, static fn (string $line): bool => '' !== trim($line));
        if ([] === $nonEmpty) {
            return false;
        }

        $withComma = array_filter($nonEmpty, static fn (string $line): bool => str_contains($line, ','));

        return count($withComma) >= (count($nonEmpty) / 2);
    }

    /**
     * Split markdown content into slide chunks on level 1-3 headings.
     *
     * @return list<string>
     */
    private function splitIntoSlides(string $content): array
    {
        $content = trim($content);
        if ('' === $content) {
            return [' '];
        }

        $parts = preg_split('/\n(?=#{1,3}\s)/', $content) ?: [];
        $parts = array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => '' !== $part
        ));

        return [] === $parts ? [$content] : $parts;
    }

    /**
     * Remove common inline markdown markers for plain-text rendering (pptx).
     */
    private function stripMarkdown(string $line): string
    {
        $line = preg_replace('/^#{1,6}\s*/', '', $line) ?? $line;
        $line = preg_replace('/(\*\*|__|\*|_|`)/', '', $line) ?? $line;

        return trim($line);
    }
}
