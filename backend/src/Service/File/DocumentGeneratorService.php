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
        if ('' === trim($content)) {
            throw new \RuntimeException('Cannot generate DOCX from empty content');
        }

        $html = (new \Parsedown())->text($content);

        // PhpWord parses the HTML with DOMDocument::loadXML() (XHTML), which
        // rejects unclosed void tags. LLMs routinely emit bare `<br>` (and
        // sometimes `<hr>` / `<img>`) inside markdown table cells; Parsedown
        // passes those through verbatim, the XML parse then fails mid-table and
        // PhpWord silently produces a structurally valid but EMPTY document
        // (no <w:t> runs). Self-closing the void tags first keeps the table
        // content intact (issue #1196).
        $html = $this->normalizeVoidTags($html);

        // Ensure special characters (like '&', '<', '>') are escaped in the XML to prevent document corruption.
        WordSettings::setOutputEscapingEnabled(true);

        $usedFallback = false;
        try {
            $phpWord = new PhpWord();
            $section = $phpWord->addSection();
            WordHtml::addHtml($section, $html, false, false);
        } catch (\Throwable $e) {
            $this->logger->warning('DocumentGeneratorService: DOCX HTML parsing failed, using plain text fallback', [
                'error' => $e->getMessage(),
            ]);

            $phpWord = $this->buildPlainTextDocx($content);
            $usedFallback = true;
        }

        WordIOFactory::createWriter($phpWord, 'Word2007')->save($absolutePath);

        // Defense in depth: even when addHtml() does not throw, a malformed
        // fragment can leave the body without a single text run. Assert the
        // saved document actually contains text and, if not, rebuild it from
        // the plain-text fallback so we never ship a blank-but-valid DOCX.
        if (!$usedFallback && !$this->docxHasText($absolutePath)) {
            $this->logger->warning('DocumentGeneratorService: DOCX produced no text runs, rebuilding with plain text fallback', [
                'path' => $absolutePath,
            ]);

            WordIOFactory::createWriter($this->buildPlainTextDocx($content), 'Word2007')->save($absolutePath);
        }
    }

    /**
     * Build a DOCX from the raw content as plain paragraphs. Used as the
     * always-valid fallback when HTML conversion fails or yields no text.
     */
    private function buildPlainTextDocx(string $content): PhpWord
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        foreach ($this->splitLines($content) as $line) {
            if ('' === trim($line)) {
                $section->addTextBreak();
            } else {
                $section->addText($line);
            }
        }

        return $phpWord;
    }

    /**
     * Self-close HTML void tags that PhpWord's XML parser would otherwise
     * reject (`<br>` → `<br/>`, `<hr>` → `<hr/>`, `<img ...>` → `<img .../>`).
     * Tags that are already self-closed are left untouched.
     */
    private function normalizeVoidTags(string $html): string
    {
        // <br> and <hr> with optional attributes, not already self-closed.
        $html = preg_replace('/<(br|hr)(\s[^>]*?)?\s*(?<!\/)>/i', '<$1$2/>', $html) ?? $html;

        // <img ...> not already self-closed.
        $html = preg_replace('/<img(\s[^>]*?)?\s*(?<!\/)>/i', '<img$1/>', $html) ?? $html;

        return $html;
    }

    /**
     * Whether the saved DOCX contains at least one text run (`<w:t>`), i.e.
     * the body is not blank. Reads word/document.xml from the OOXML zip.
     */
    private function docxHasText(string $absolutePath): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($absolutePath)) {
            // Can't inspect it — assume valid rather than forcing a rebuild.
            return true;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (false === $xml) {
            return true;
        }

        return str_contains($xml, '<w:t>') || str_contains($xml, '<w:t ');
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
