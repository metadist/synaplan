<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Service\File\Office\DocxStyleSheet;
use App\Service\File\Presentation\PptxRenderer;
use App\Service\File\Presentation\SlideMarkdownParser;
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

    private const IMAGE_MARKER_PATTERN = '/\{\{IMAGE:[a-z]+:\d+}}/';

    /**
     * Content directive marking where the Word table of contents belongs
     * (own line, usually right after the document title). DOCX renders a
     * real, updatable TOC field there; every other format strips the marker.
     */
    private const TOC_MARKER_PATTERN = '/^[ \t]*\{\{TOC}}[ \t]*$/mi';

    /** Headings deeper than this are kept in the document but not listed in the TOC. */
    private const TOC_MAX_DEPTH = 3;

    public function __construct(
        private LoggerInterface $logger,
        private SlideMarkdownParser $slideParser,
        private PptxRenderer $pptxRenderer,
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
     * @param array<string, string> $images image marker reference => absolute path
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function write(string $content, string $extension, string $absolutePath, array $images = []): void
    {
        switch (strtolower($extension)) {
            case 'docx':
                $this->writeDocx($this->stripPresentationDirective($content), $absolutePath, $images);
                break;
            case 'xlsx':
                $this->writeXlsx($this->stripMarkers($content), $absolutePath);
                break;
            case 'pptx':
                $this->writePptx($this->stripTocMarker($content), $absolutePath, $images);
                break;
            default:
                // Only DOCX and PPTX can embed images — every other format would
                // show the raw marker to the user, so drop it.
                if (false === file_put_contents($absolutePath, $this->stripMarkers($content))) {
                    throw new \RuntimeException('Failed to write file: '.$absolutePath);
                }
        }
    }

    /**
     * Build a Word document. The AI content is treated as markdown and
     * converted to HTML so headings, lists, bold text and tables are kept.
     * If HTML parsing fails, fall back to plain paragraphs so the file is
     * still valid and openable.
     *
     * An image that cannot be embedded never fails the document: it is skipped
     * and the user receives the text without that image.
     *
     * @param array<string, string> $images
     */
    private function writeDocx(string $content, string $absolutePath, array $images): void
    {
        if ('' === trim($content)) {
            throw new \RuntimeException('Cannot generate DOCX from empty content');
        }

        $normalized = $this->normalizeImagesForOoxml($images);
        $images = $normalized['images'];
        $temporaryImages = $normalized['temporary'];

        // A TOC needs at least one heading to list — a bare marker in a
        // heading-less document is dropped (an empty TOC field would be
        // malformed OOXML).
        $withToc = 1 === preg_match(self::TOC_MARKER_PATTERN, $content)
            && 1 === preg_match('/^#{1,6}[ \t]+\S/m', $content);
        if (!$withToc) {
            $content = $this->stripTocMarker($content);
        }

        try {
            // Ensure special characters (like '&', '<', '>') are escaped in the XML to prevent document corruption.
            WordSettings::setOutputEscapingEnabled(true);

            $usedFallback = false;
            try {
                $phpWord = new PhpWord();
                DocxStyleSheet::apply($phpWord);
                $section = $phpWord->addSection(DocxStyleSheet::sectionSettings());
                DocxStyleSheet::decorateSection($section);
                $this->addDocxContent($section, $content, $images, $withToc);
            } catch (\Throwable $e) {
                $this->logger->warning('DocumentGeneratorService: DOCX HTML parsing failed, using plain text fallback', [
                    'error' => $e->getMessage(),
                ]);

                $phpWord = $this->buildPlainTextDocx($content, $images);
                $usedFallback = true;
            }

            WordIOFactory::createWriter($phpWord, 'Word2007')->save($absolutePath);

            // Defense in depth: even when addHtml() does not throw, a malformed
            // fragment can leave the body without a single text run. Assert the
            // saved document actually contains text and, if not, rebuild it from
            // the plain-text fallback so we never ship a blank-but-valid DOCX.
            if (!$usedFallback && !$this->docxHasContent($absolutePath)) {
                $this->logger->warning('DocumentGeneratorService: DOCX produced no content, rebuilding with plain text fallback', [
                    'path' => $absolutePath,
                ]);

                WordIOFactory::createWriter($this->buildPlainTextDocx($content, $images), 'Word2007')->save($absolutePath);
            }
        } finally {
            foreach ($temporaryImages as $temporaryImage) {
                @unlink($temporaryImage);
            }
        }
    }

    /**
     * Replace WebP images with PNG copies, because neither Word nor PowerPoint
     * renders WebP reliably. An image that cannot be converted is dropped from
     * the list so the document is produced without it.
     *
     * The caller owns the returned temporary files and must unlink them.
     *
     * @param array<string, string> $images
     *
     * @return array{images: array<string, string>, temporary: list<string>}
     */
    private function normalizeImagesForOoxml(array $images): array
    {
        $temporary = [];

        foreach ($images as $reference => $imagePath) {
            if ('webp' !== strtolower(pathinfo($imagePath, PATHINFO_EXTENSION))) {
                continue;
            }

            try {
                $convertedPath = $this->convertWebpToPng($imagePath);
            } catch (\RuntimeException $e) {
                $this->logger->warning('DocumentGeneratorService: skipping WebP image that could not be converted', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);

                unset($images[$reference]);
                continue;
            }

            $images[$reference] = $convertedPath;
            $temporary[] = $convertedPath;
        }

        return ['images' => $images, 'temporary' => $temporary];
    }

    private function convertWebpToPng(string $sourcePath): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'ooxml_image_');
        if (false === $temporaryPath) {
            throw new \RuntimeException('Failed to create a temporary file for a WebP document image');
        }

        try {
            if (class_exists(\Imagick::class)) {
                $image = new \Imagick($sourcePath);
                $image->setImageFormat('png');
                $image->writeImage($temporaryPath);
                $image->clear();
                $image->destroy();

                return $temporaryPath;
            }

            if (function_exists('imagecreatefromwebp')) {
                $image = imagecreatefromwebp($sourcePath);
                if (false !== $image && imagepng($image, $temporaryPath)) {
                    imagedestroy($image);

                    return $temporaryPath;
                }
                if (false !== $image) {
                    imagedestroy($image);
                }
            }
        } catch (\Throwable $e) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Failed to convert a WebP document image: '.$sourcePath, 0, $e);
        }

        @unlink($temporaryPath);
        throw new \RuntimeException('WebP document images require Imagick or GD WebP support');
    }

    /**
     * Add markdown and image markers to a Word section in source order.
     *
     * In TOC mode markdown headings are added as PhpWord Title elements (real
     * `Heading{N}` styles with outline levels) instead of going through the
     * HTML converter — the TOC field can only collect Titles.
     *
     * @param array<string, string> $images
     */
    private function addDocxContent(\PhpOffice\PhpWord\Element\Section $section, string $content, array $images, bool $withToc = false): void
    {
        $tocPending = $withToc;

        foreach ($this->splitImageMarkers($content) as $part) {
            if ($part['image']) {
                $path = $images[$part['value']] ?? null;
                if (null === $path) {
                    $this->logUnresolvedImage($part['value']);
                    continue;
                }
                $section->addImage($path, ['width' => 180, 'ratio' => true]);
                continue;
            }

            if ('' === trim($part['value'])) {
                continue;
            }

            if ($withToc) {
                $this->addMarkdownWithTitles($section, $part['value'], $tocPending);
                continue;
            }

            $this->addMarkdownAsHtml($section, $part['value']);
        }
    }

    private function addMarkdownAsHtml(\PhpOffice\PhpWord\Element\Section $section, string $markdown): void
    {
        $html = (new \Parsedown())->text($markdown);

        // PhpWord parses HTML as XHTML. Self-close void tags that models
        // commonly leave open so table and paragraph content survives.
        WordHtml::addHtml($section, $this->normalizeVoidTags($html), false, false);
    }

    /**
     * TOC-mode rendering: heading lines become Title elements, the `{{TOC}}`
     * marker becomes the TOC field (first occurrence only), everything in
     * between keeps the normal markdown→HTML path.
     */
    private function addMarkdownWithTitles(\PhpOffice\PhpWord\Element\Section $section, string $markdown, bool &$tocPending): void
    {
        $segments = preg_split(
            '/^([ \t]*\{\{TOC}}[ \t]*|#{1,6}[ \t]+.+)$/mi',
            $markdown,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );
        if (false === $segments) {
            $this->addMarkdownAsHtml($section, $this->stripTocMarker($markdown));

            return;
        }

        foreach ($segments as $segment) {
            $trimmed = trim($segment);
            if ('' === $trimmed) {
                continue;
            }

            if (1 === preg_match('/^\{\{TOC}}$/i', $trimmed)) {
                if ($tocPending) {
                    $section->addTOC(['size' => 11], null, 1, self::TOC_MAX_DEPTH);
                    $tocPending = false;
                }
                continue;
            }

            if (1 === preg_match('/^(#{1,6})[ \t]+(.+)$/', $trimmed, $m)) {
                $section->addTitle($this->stripMarkdown($m[2]), strlen($m[1]));
                continue;
            }

            $this->addMarkdownAsHtml($section, $segment);
        }
    }

    /**
     * Build a DOCX from the raw content as plain paragraphs. Used as the
     * always-valid fallback when HTML conversion fails or yields no text, so
     * this path must never throw on the document body itself.
     *
     * @param array<string, string> $images
     */
    private function buildPlainTextDocx(string $content, array $images = []): PhpWord
    {
        $content = $this->stripTocMarker($content);

        $phpWord = new PhpWord();
        DocxStyleSheet::apply($phpWord);
        $section = $phpWord->addSection(DocxStyleSheet::sectionSettings());
        DocxStyleSheet::decorateSection($section);

        foreach ($this->splitImageMarkers($content) as $part) {
            if ($part['image']) {
                $path = $images[$part['value']] ?? null;
                if (null === $path) {
                    $this->logUnresolvedImage($part['value']);
                    continue;
                }
                $section->addImage($path, ['width' => 180, 'ratio' => true]);
                continue;
            }

            foreach ($this->splitLines($part['value']) as $line) {
                if ('' === trim($line)) {
                    $section->addTextBreak();
                } else {
                    $section->addText($line);
                }
            }
        }

        return $phpWord;
    }

    /**
     * A marker without a resolved path is skipped, never fatal: losing one
     * image is a far better outcome for the user than losing the document.
     */
    private function logUnresolvedImage(string $reference): void
    {
        $this->logger->warning('DocumentGeneratorService: skipping unresolved document image reference', [
            'reference' => $reference,
        ]);
    }

    /**
     * Remove every generator marker from content that is written as text: image
     * markers and the presentation directive would otherwise be shown verbatim.
     */
    private function stripMarkers(string $content): string
    {
        if (!str_contains($content, '{{')) {
            return $content;
        }

        return $this->closeMarkerGaps(preg_replace(
            [SlideMarkdownParser::DIRECTIVE_PATTERN, self::IMAGE_MARKER_PATTERN, self::TOC_MARKER_PATTERN],
            '',
            $content,
        ) ?? $content);
    }

    /**
     * Remove the `{{TOC}}` directive from content whose format cannot render a
     * table of contents (everything except the DOCX title path).
     */
    private function stripTocMarker(string $content): string
    {
        if (!str_contains($content, '{{')) {
            return $content;
        }

        return $this->closeMarkerGaps(preg_replace(self::TOC_MARKER_PATTERN, '', $content) ?? $content);
    }

    /**
     * The presentation directive only configures the PPTX renderer. Any other
     * format has to drop it, or the user reads `{{PPTX:theme=ocean}}` in their
     * Word document.
     */
    private function stripPresentationDirective(string $content): string
    {
        if (!str_contains($content, '{{')) {
            return $content;
        }

        return $this->closeMarkerGaps(
            preg_replace(SlideMarkdownParser::DIRECTIVE_PATTERN, '', $content) ?? $content,
        );
    }

    /**
     * A removed marker leaves a hole behind: an empty paragraph in the middle of
     * the text, or a blank first line when the directive occupied it.
     */
    private function closeMarkerGaps(string $content): string
    {
        return ltrim(preg_replace('/\R{3,}/', "\n\n", $content) ?? $content, "\r\n");
    }

    /**
     * @return list<array{image: bool, value: string}>
     */
    private function splitImageMarkers(string $content): array
    {
        $parts = preg_split(
            '/\{\{IMAGE:([a-z]+:\d+)}}/',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );
        if (false === $parts) {
            return [['image' => false, 'value' => $content]];
        }

        return array_map(
            static fn (string $part, int $index): array => [
                'image' => 1 === $index % 2,
                'value' => $part,
            ],
            $parts,
            array_keys($parts),
        );
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
     * Whether the saved DOCX contains at least one text or image element.
     * Reads word/document.xml from the OOXML zip.
     */
    private function docxHasContent(string $absolutePath): bool
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

        return str_contains($xml, '<w:t>')
            || str_contains($xml, '<w:t ')
            || str_contains($xml, '<w:drawing>')
            || str_contains($xml, '<w:pict>');
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
     * Build a PowerPoint presentation: the markdown is parsed into slides and
     * drawn with a real layout — title, bullets, tables and embedded images
     * (#1397).
     *
     * If anything about the structured path fails, the deck falls back to the
     * flat text rendering so the user still receives an openable file.
     *
     * @param array<string, string> $images
     */
    private function writePptx(string $content, string $absolutePath, array $images): void
    {
        $normalized = $this->normalizeImagesForOoxml($images);

        try {
            try {
                $this->pptxRenderer->render(
                    $this->slideParser->parse($content),
                    $absolutePath,
                    $normalized['images'],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('DocumentGeneratorService: PPTX rendering failed, using plain text fallback', [
                    'error' => $e->getMessage(),
                ]);

                $this->writePlainTextPptx($this->stripMarkers($content), $absolutePath);
            }
        } finally {
            foreach ($normalized['temporary'] as $temporaryImage) {
                @unlink($temporaryImage);
            }
        }
    }

    /**
     * Always-valid fallback: one text box per markdown section, markdown markers
     * removed. No design, but an openable presentation.
     */
    private function writePlainTextPptx(string $content, string $absolutePath): void
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
