<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\DocumentGeneratorService;
use App\Service\File\Presentation\PptxRenderer;
use App\Service\File\Presentation\PptxRequestDirectiveResolver;
use App\Service\File\Presentation\SlideMarkdownParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DocumentGeneratorServiceTest extends TestCase
{
    private DocumentGeneratorService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->service = self::createService(new NullLogger());
        $this->tmpDir = sys_get_temp_dir().'/docgen_'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    private static function createService(LoggerInterface $logger): DocumentGeneratorService
    {
        return new DocumentGeneratorService($logger, new SlideMarkdownParser(), new PptxRenderer($logger));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testIsBinaryFormat(): void
    {
        $this->assertTrue($this->service->isBinaryFormat('docx'));
        $this->assertTrue($this->service->isBinaryFormat('XLSX'));
        $this->assertTrue($this->service->isBinaryFormat('pptx'));
        $this->assertFalse($this->service->isBinaryFormat('txt'));
        $this->assertFalse($this->service->isBinaryFormat('csv'));
        $this->assertFalse($this->service->isBinaryFormat('md'));
    }

    public function testDocxIsValidOoxmlZip(): void
    {
        $path = $this->tmpDir.'/test.docx';
        $this->service->write("# Title\n\nSome **bold** text and a paragraph.", 'docx', $path);

        $this->assertFileExists($path);
        $this->assertTrue($this->isZipContaining($path, 'word/document.xml'));
        $this->assertGreaterThan(1000, filesize($path), 'A real DOCX is far larger than its text source');
    }

    public function testDocxRegistersDefaultStylesAndHeadingName(): void
    {
        $path = $this->tmpDir.'/styled.docx';
        $this->service->write("# Quarterly report\n\nA short body paragraph.", 'docx', $path);

        $zip = new \ZipArchive();
        $this->assertTrue(true === $zip->open($path), 'DOCX must be a valid OOXML zip');
        $styles = (string) $zip->getFromName('word/styles.xml');
        $footer = (string) $zip->getFromName('word/footer1.xml');
        $zip->close();

        $this->assertStringContainsString('Calibri', $styles);
        $this->assertStringContainsString('Heading1', $styles);
        $this->assertStringContainsString('1E3A5F', $styles);
        $this->assertStringContainsString('Heading1', $this->readDocxDocument($path));
        $this->assertStringContainsString('PAGE', $footer);
    }

    public function testDocxFallbackForUnparsableContentStillProducesValidFile(): void
    {
        $path = $this->tmpDir.'/plain.docx';
        $this->service->write("Just a plain line\nAnother line", 'docx', $path);

        $this->assertTrue($this->isZipContaining($path, 'word/document.xml'));
    }

    /**
     * Issue #1196: LLMs emit bare `<br>` inside markdown table cells. PhpWord's
     * XML parser rejects the unclosed tag and silently produced a structurally
     * valid but EMPTY DOCX (no <w:t> runs). The generator must now self-close
     * void tags so the table content survives.
     */
    public function testDocxWithBrInTableCellContainsText(): void
    {
        $path = $this->tmpDir.'/table_br.docx';
        $content = "| Day | Exercise |\n| --- | --- |\n| 1 | Bench press<br>Pull-ups |";
        $this->service->write($content, 'docx', $path);

        $documentXml = $this->readDocxDocument($path);
        $this->assertStringContainsString('<w:t', $documentXml, 'DOCX with <br> in a table cell must contain text runs');
        $this->assertStringContainsString('Bench press', $documentXml);
        $this->assertStringContainsString('Pull-ups', $documentXml);
    }

    /**
     * Issue #1196 (defense in depth): even pathological HTML must never yield a
     * blank-but-valid DOCX — the post-write assertion rebuilds from plain text.
     */
    public function testDocxAlwaysContainsTextForNonEmptyContent(): void
    {
        $path = $this->tmpDir.'/pathological.docx';
        $content = "Line one<br>Line two\n\n| a | b |\n| --- | --- |\n| x<br>y | z |";
        $this->service->write($content, 'docx', $path);

        $documentXml = $this->readDocxDocument($path);
        $this->assertStringContainsString('<w:t', $documentXml, 'A non-empty source must always yield a non-empty DOCX body');
    }

    /**
     * Issue #1228: an image marker must become an embedded OOXML image at the
     * requested position instead of being ignored or rendered as marker text.
     */
    public function testDocxEmbedsReferencedImage(): void
    {
        $imagePath = $this->tmpDir.'/profile.png';
        file_put_contents($imagePath, self::onePixelPng());

        $path = $this->tmpDir.'/with_image.docx';
        $this->service->write(
            "# Application\n\n{{IMAGE:file:42}}\n\nCover letter",
            'docx',
            $path,
            ['file:42' => $imagePath],
        );

        $documentXml = $this->readDocxDocument($path);
        $this->assertTrue(
            str_contains($documentXml, '<w:drawing>') || str_contains($documentXml, '<w:pict>'),
            'DOCX must contain an OOXML image element',
        );
        $this->assertStringNotContainsString('{{IMAGE:', $documentXml);
        $this->assertTrue($this->docxContainsMedia($path), 'DOCX must contain the embedded image binary');
    }

    public function testDocxSkipsUnconvertibleWebpImageAndCleansTemporaryFiles(): void
    {
        if (!class_exists(\Imagick::class) && !function_exists('imagewebp')) {
            $this->markTestSkipped('WebP generation requires Imagick or GD');
        }

        $validWebp = $this->tmpDir.'/valid.webp';
        $this->assertTrue(self::writeOnePixelWebp($validWebp));

        $invalidWebp = $this->tmpDir.'/invalid.webp';
        file_put_contents($invalidWebp, 'not a webp image');
        $temporaryFilesBefore = glob(sys_get_temp_dir().'/ooxml_image_*') ?: [];

        $path = $this->tmpDir.'/conversion_failure.docx';
        $this->service->write(
            "Cover letter\n\n{{IMAGE:file:1}}{{IMAGE:file:2}}",
            'docx',
            $path,
            ['file:1' => $validWebp, 'file:2' => $invalidWebp],
        );

        $this->assertFileExists($path, 'A broken image must not discard the document');
        $this->assertStringContainsString('Cover letter', $this->readDocxDocument($path));

        $leakedFiles = array_values(array_diff(
            glob(sys_get_temp_dir().'/ooxml_image_*') ?: [],
            $temporaryFilesBefore,
        ));
        foreach ($leakedFiles as $leakedFile) {
            @unlink($leakedFile);
        }

        $this->assertSame([], $leakedFiles, 'Converted WebP temporary files must be removed after the write');
    }

    /**
     * Issue #1228 follow-up: the model emits `{{IMAGE:attached:1}}` even when
     * nothing is attached. An unresolvable marker must cost the image, never
     * the whole document.
     */
    public function testDocxSkipsUnresolvedImageReference(): void
    {
        $path = $this->tmpDir.'/missing_image.docx';
        $this->service->write("Dear Sir or Madam\n\n{{IMAGE:file:999}}\n\nKind regards", 'docx', $path);

        $documentXml = $this->readDocxDocument($path);
        $this->assertStringContainsString('Dear Sir or Madam', $documentXml);
        $this->assertStringContainsString('Kind regards', $documentXml);
        $this->assertStringNotContainsString('{{IMAGE:', $documentXml);
        $this->assertFalse($this->docxContainsMedia($path));
    }

    /**
     * The plain-text fallback exists so a document is always produced — it must
     * not re-throw on the very image marker that can send it there. Content
     * consisting only of an unresolvable marker leaves an empty body and
     * therefore always takes the rebuild path.
     */
    public function testDocxPlainTextFallbackSurvivesUnresolvedImageReference(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $service = self::createService($logger);

        $path = $this->tmpDir.'/fallback_image.docx';
        $service->write('{{IMAGE:file:999}}', 'docx', $path);

        $this->assertContains(
            'DocumentGeneratorService: DOCX produced no content, rebuilding with plain text fallback',
            $logger->messages,
        );
        $this->assertTrue($this->isZipContaining($path, 'word/document.xml'));
    }

    public function testWriteDocxThrowsOnEmptyContent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->write("   \n  ", 'docx', $this->tmpDir.'/empty.docx');
    }

    public function testXlsxIsValidOoxmlZip(): void
    {
        $path = $this->tmpDir.'/test.xlsx';
        $this->service->write("Name,Age\nJohn,25\nJane,30", 'xlsx', $path);

        $this->assertFileExists($path);
        $this->assertTrue($this->isZipContaining($path, 'xl/workbook.xml'));
    }

    public function testPptxIsValidOoxmlZip(): void
    {
        $path = $this->tmpDir.'/test.pptx';
        $this->service->write("# Slide One\nContent\n\n# Slide Two\nMore content", 'pptx', $path);

        $this->assertFileExists($path);
        $this->assertTrue($this->isZipContaining($path, '[Content_Types].xml'));
    }

    /**
     * Issue #1397: every markdown heading has to become its own slide instead of
     * everything landing in a single text box.
     */
    public function testPptxSplitsHeadingsAndRulesIntoSeparateSlides(): void
    {
        $path = $this->tmpDir.'/slides.pptx';
        $this->service->write("# Cover\n\n## Second\n\n- a\n\n---\n\nThird", 'pptx', $path);

        $this->assertTrue($this->isZipContaining($path, 'ppt/slides/slide1.xml'));
        $this->assertTrue($this->isZipContaining($path, 'ppt/slides/slide2.xml'));
        $this->assertTrue($this->isZipContaining($path, 'ppt/slides/slide3.xml'));
        $this->assertFalse($this->isZipContaining($path, 'ppt/slides/slide4.xml'));
    }

    /**
     * Issue #1397: the deck must carry real slide structure — a title, bulleted
     * body text and surviving inline emphasis.
     */
    public function testPptxRendersTitledSlidesWithBulletsAndEmphasis(): void
    {
        $path = $this->tmpDir.'/structured.pptx';
        $this->service->write("# Cover\n\n## Early years\n\n- Born in **Munich**\n- Adopted at 12 weeks", 'pptx', $path);

        $slide = $this->readPptxSlide($path, 2);
        $this->assertStringContainsString('Early years', $slide, 'The heading must become the slide title');
        $this->assertStringContainsString('a:buChar', $slide, 'Body lines must be real bullets');
        $this->assertStringContainsString('b="1"', $slide, '**bold** must survive as a bold run');
        $this->assertStringNotContainsString('**', $slide, 'Markdown markers must not be visible on the slide');
    }

    /**
     * Issue #1397: the picture belongs INSIDE the .pptx. It used to be stripped,
     * which is why the model faked slides as separate chat images.
     */
    public function testPptxEmbedsReferencedImage(): void
    {
        $imagePath = $this->tmpDir.'/photo.png';
        file_put_contents($imagePath, self::onePixelPng());

        $path = $this->tmpDir.'/with_image.pptx';
        $this->service->write(
            "# Cover\n\n## The cat\n\n- a portrait\n\n{{IMAGE:file:42}}",
            'pptx',
            $path,
            ['file:42' => $imagePath],
        );

        $slide = $this->readPptxSlide($path, 2);
        $this->assertStringContainsString('<a:blip', $slide, 'The slide must reference an embedded picture');
        $this->assertStringNotContainsString('{{IMAGE:', $slide);
        $this->assertTrue($this->pptxContainsMedia($path), 'The .pptx must carry the image binary');
    }

    public function testPptxSkipsUnresolvedImageReference(): void
    {
        $path = $this->tmpDir.'/missing_image.pptx';
        $this->service->write("## Slide\n\n- text\n\n{{IMAGE:file:999}}", 'pptx', $path);

        $slide = $this->readPptxSlide($path, 1);
        $this->assertStringContainsString('text', $slide);
        $this->assertStringNotContainsString('{{IMAGE:', $slide);
        $this->assertFalse($this->pptxContainsMedia($path));
    }

    public function testPptxConvertsWebpImageAndCleansTemporaryFiles(): void
    {
        $webpPath = $this->tmpDir.'/photo.webp';
        if (!self::writeOnePixelWebp($webpPath)) {
            $this->markTestSkipped('WebP generation requires Imagick or GD');
        }

        $temporaryFilesBefore = glob(sys_get_temp_dir().'/ooxml_image_*') ?: [];

        $path = $this->tmpDir.'/webp.pptx';
        $this->service->write("## Slide\n\n{{IMAGE:file:7}}", 'pptx', $path, ['file:7' => $webpPath]);

        $this->assertTrue($this->pptxContainsMedia($path), 'A WebP picture must be embedded as a converted PNG');

        $leakedFiles = array_values(array_diff(
            glob(sys_get_temp_dir().'/ooxml_image_*') ?: [],
            $temporaryFilesBefore,
        ));
        foreach ($leakedFiles as $leakedFile) {
            @unlink($leakedFile);
        }

        $this->assertSame([], $leakedFiles, 'Converted WebP temporary files must be removed after the write');
    }

    public function testPptxRendersMarkdownTableAsATable(): void
    {
        $path = $this->tmpDir.'/table.pptx';
        $this->service->write(
            "## Numbers\n\n| Year | Weight |\n| --- | --- |\n| 2019 | 0.9 kg |",
            'pptx',
            $path,
        );

        $slide = $this->readPptxSlide($path, 1);
        $this->assertStringContainsString('<a:tbl>', $slide);
        $this->assertStringContainsString('0.9 kg', $slide);
        $this->assertStringNotContainsString('| Year |', $slide);
    }

    /**
     * PhpPresentation writes a horizontal cell edge from the LOWER cell's top
     * border, so a separator defined on the bottom border silently disappears.
     */
    public function testPptxTableRowsAreSeparatedByAVisibleRule(): void
    {
        $path = $this->tmpDir.'/table_rules.pptx';
        $this->service->write(
            "## Numbers\n\n| Year | Weight |\n| --- | --- |\n| 2019 | 0.9 kg |\n| 2020 | 3.4 kg |",
            'pptx',
            $path,
        );

        preg_match_all('#<a:tr\b.*?</a:tr>#s', $this->readPptxSlide($path, 1), $rows);
        $this->assertCount(3, $rows[0], 'Header row plus two body rows expected');

        foreach (array_slice($rows[0], 1) as $index => $row) {
            $this->assertSame(
                1,
                preg_match('#<a:lnT\b[^>]*>\s*<a:solidFill>#', $row),
                sprintf('Body row %d must carry a visible separator above it', $index + 1),
            );
        }
    }

    /**
     * Transitions are opt-in: a deck nobody asked to animate must not animate.
     */
    public function testPptxHasNoTransitionUnlessTheDirectiveAsksForOne(): void
    {
        $plainPath = $this->tmpDir.'/plain.pptx';
        $this->service->write("## One\n\n- a\n\n## Two\n\n- b", 'pptx', $plainPath);
        $this->assertStringNotContainsString('p:transition', $this->readPptxSlide($plainPath, 1));

        $animatedPath = $this->tmpDir.'/animated.pptx';
        $this->service->write("{{PPTX:transition=fade}}\n## One\n\n- a\n\n## Two\n\n- b", 'pptx', $animatedPath);
        $this->assertStringContainsString('p:transition', $this->readPptxSlide($animatedPath, 1));
        $this->assertStringContainsString('p:transition', $this->readPptxSlide($animatedPath, 2));
    }

    public function testExplicitRequestOptionsApplyWhenModelOmitsDirective(): void
    {
        $content = PptxRequestDirectiveResolver::apply(
            "## One\n\n- a\n\n## Two\n\n- b",
            'Erstelle eine Präsentation mit Ocean-Theme und Fade-Übergängen.',
        );
        $path = $this->tmpDir.'/requested_options.pptx';

        $this->service->write($content, 'pptx', $path);

        $firstSlide = $this->readPptxSlide($path, 1);
        $this->assertStringContainsString('F3F9FC', $firstSlide, 'The requested Ocean background must be rendered');
        $this->assertStringContainsString('<p:transition', $firstSlide);
        $this->assertStringContainsString('<p:fade', $firstSlide);
        $this->assertStringContainsString('<p:fade', $this->readPptxSlide($path, 2));
    }

    public function testPptxThemeDirectiveChangesTheSlideColors(): void
    {
        $defaultPath = $this->tmpDir.'/default_theme.pptx';
        $this->service->write("## Slide\n\n- a", 'pptx', $defaultPath);
        $this->assertStringNotContainsString('111827', $this->readPptxSlide($defaultPath, 1));

        $themedPath = $this->tmpDir.'/midnight.pptx';
        $this->service->write("{{PPTX:theme=midnight}}\n## Slide\n\n- a", 'pptx', $themedPath);
        $this->assertStringContainsString('111827', $this->readPptxSlide($themedPath, 1), 'The midnight background must be applied');
    }

    /**
     * The directive configures the PPTX renderer only — no other format may ever
     * show it to the user.
     */
    public function testPresentationDirectiveNeverReachesTheOutput(): void
    {
        $pptxPath = $this->tmpDir.'/directive.pptx';
        $this->service->write("{{PPTX:theme=ocean}}\n## Slide\n\n- a", 'pptx', $pptxPath);
        $this->assertStringNotContainsString('PPTX:', $this->readPptxSlide($pptxPath, 1));

        $docxPath = $this->tmpDir.'/directive.docx';
        $this->service->write("{{PPTX:theme=ocean}}\n\n# Heading\n\nBody", 'docx', $docxPath);
        $documentXml = $this->readDocxDocument($docxPath);
        $this->assertStringNotContainsString('PPTX:', $documentXml);
        $this->assertStringContainsString('Heading', $documentXml);

        $markdownPath = $this->tmpDir.'/directive.md';
        $this->service->write("{{PPTX:theme=ocean}}\n\n# Heading", 'md', $markdownPath);
        $this->assertSame('# Heading', file_get_contents($markdownPath));
    }

    public function testPptxWithoutHeadingsStillYieldsOneValidSlide(): void
    {
        $path = $this->tmpDir.'/plain_text.pptx';
        $this->service->write("Just a sentence.\nAnd another one.", 'pptx', $path);

        $this->assertTrue($this->isZipContaining($path, 'ppt/slides/slide1.xml'));
        $this->assertFalse($this->isZipContaining($path, 'ppt/slides/slide2.xml'));
        $this->assertStringContainsString('Just a sentence.', $this->readPptxSlide($path, 1));
    }

    /**
     * A broken layout must never cost the user their file: the deck degrades to
     * the flat text rendering, which is always openable.
     */
    public function testPptxFallsBackToPlainTextWhenRenderingFails(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $renderer = $this->createMock(PptxRenderer::class);
        $renderer->method('render')->willThrowException(new \RuntimeException('layout exploded'));
        $service = new DocumentGeneratorService($logger, new SlideMarkdownParser(), $renderer);

        $path = $this->tmpDir.'/fallback.pptx';
        $service->write("{{PPTX:theme=ocean}}\n# Slide One\n\nSome **content**", 'pptx', $path);

        $slide = $this->readPptxSlide($path, 1);
        $this->assertStringContainsString('Slide One', $slide);
        $this->assertStringNotContainsString('PPTX:', $slide);
        $this->assertContains(
            'DocumentGeneratorService: PPTX rendering failed, using plain text fallback',
            $logger->messages,
        );
    }

    public function testPptxFromEmptyContentIsStillOpenable(): void
    {
        $path = $this->tmpDir.'/empty.pptx';
        $this->service->write("   \n  ", 'pptx', $path);

        $this->assertTrue($this->isZipContaining($path, 'ppt/slides/slide1.xml'));
    }

    public function testTextFormatsAreWrittenVerbatim(): void
    {
        $path = $this->tmpDir.'/test.csv';
        $content = "a,b,c\n1,2,3";
        $this->service->write($content, 'csv', $path);

        $this->assertSame($content, file_get_contents($path));
    }

    public function testUnknownExtensionIsWrittenAsText(): void
    {
        $path = $this->tmpDir.'/test.md';
        $content = "# Heading\n\ntext";
        $this->service->write($content, 'md', $path);

        $this->assertSame($content, file_get_contents($path));
    }

    /**
     * Only DOCX can embed images — a text format would show the raw marker.
     */
    public function testTextFormatsDropImageMarkers(): void
    {
        $path = $this->tmpDir.'/markers.md';
        $this->service->write("# Heading\n\n{{IMAGE:file:42}}\n\ntext", 'md', $path);

        $this->assertSame("# Heading\n\ntext", file_get_contents($path));
    }

    /**
     * Phase M step M0: a `{{TOC}}` directive must yield a REAL, updatable Word
     * table of contents — a `TOC` field instruction plus headings rendered as
     * outline-level styles — asserted by unpacking the OOXML, not by eyeballing.
     */
    public function testDocxTocDirectiveRendersAnUpdatableTocField(): void
    {
        $path = $this->tmpDir.'/toc.docx';
        $content = <<<'MD'
            # Marketing Plan

            {{TOC}}

            ## Goals

            Grow **brand awareness** in the DACH region.

            ### Quarterly targets

            - Q1: launch
            - Q2: scale

            ## Budget

            | Item | Cost |
            | ---- | ---- |
            | Ads  | 1000 |
            MD;

        $this->service->write($content, 'docx', $path);

        $documentXml = $this->readDocxDocument($path);

        // The dynamic field Word/LibreOffice re-computes on update.
        $this->assertStringContainsString('TOC \o 1-3 \h \z \u', $documentXml);
        // Static entries with hyperlink anchors so the TOC works before the
        // first field update too.
        $this->assertStringContainsString('_Toc', $documentXml);
        // Headings are real outline-styled titles, not plain paragraphs.
        $this->assertStringContainsString('Heading1', $documentXml);
        $this->assertStringContainsString('Heading2', $documentXml);
        // The registered styles use Word's BUILT-IN heading names — that is
        // what the TOC field's \o switch collects when the user refreshes it.
        $stylesXml = $this->readDocxEntry($path, 'word/styles.xml');
        $this->assertStringContainsString('w:val="heading 1"', $stylesXml);
        $this->assertStringContainsString('w:val="heading 2"', $stylesXml);
        // Body content survives the TOC-mode rendering path.
        $this->assertStringContainsString('brand awareness', $documentXml);
        $this->assertStringContainsString('Quarterly targets', $documentXml);
        $this->assertStringContainsString('Ads', $documentXml, 'tables must survive TOC mode');
        // The directive itself never reaches the reader.
        $this->assertStringNotContainsString('{{TOC}}', $documentXml);
    }

    public function testDocxTocMarkerWithoutHeadingsIsDroppedNotRendered(): void
    {
        $path = $this->tmpDir.'/toc_no_headings.docx';
        $this->service->write("{{TOC}}\n\nJust a paragraph without any headings.", 'docx', $path);

        $documentXml = $this->readDocxDocument($path);
        $this->assertStringNotContainsString('TOC \o', $documentXml, 'an empty TOC field would be malformed OOXML');
        $this->assertStringNotContainsString('{{TOC}}', $documentXml);
        $this->assertStringContainsString('Just a paragraph', $documentXml);
    }

    public function testTocMarkerNeverReachesTextFormats(): void
    {
        $path = $this->tmpDir.'/toc.md';
        $this->service->write("# Heading\n\n{{TOC}}\n\ntext", 'md', $path);

        $this->assertSame("# Heading\n\ntext", file_get_contents($path));
    }

    public function testTocMarkerNeverReachesPptxSlides(): void
    {
        $path = $this->tmpDir.'/toc.pptx';
        $this->service->write("# Cover\n\n{{TOC}}\n\n## Agenda\n\n- one", 'pptx', $path);

        $this->assertStringNotContainsString('{{TOC}}', $this->readPptxSlide($path, 1));
    }

    private function readDocxEntry(string $path, string $entry): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue(true === $zip->open($path), 'DOCX must be a valid OOXML zip');
        $xml = $zip->getFromName($entry);
        $zip->close();
        $this->assertNotFalse($xml, 'DOCX must contain '.$entry);

        return (string) $xml;
    }

    private function readPptxSlide(string $path, int $number): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue(true === $zip->open($path), 'PPTX must be a valid OOXML zip');
        $xml = $zip->getFromName('ppt/slides/slide'.$number.'.xml');
        $zip->close();
        $this->assertNotFalse($xml, 'PPTX must contain slide '.$number);

        return (string) $xml;
    }

    private function pptxContainsMedia(string $path): bool
    {
        return $this->zipContainsEntryPrefix($path, 'ppt/media/');
    }

    private static function onePixelPng(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );

        // A truncated fixture would produce an empty file and turn the image
        // assertions below into a false negative instead of a setup failure.
        self::assertNotFalse($png, 'the one-pixel PNG fixture must be valid base64');

        return $png;
    }

    private static function writeOnePixelWebp(string $path): bool
    {
        if (class_exists(\Imagick::class)) {
            $image = new \Imagick();
            $image->newImage(1, 1, new \ImagickPixel('white'));
            $image->setImageFormat('webp');
            $image->writeImage($path);
            $image->clear();
            $image->destroy();

            return true;
        }

        if (!function_exists('imagewebp')) {
            return false;
        }

        $image = imagecreatetruecolor(1, 1);
        $written = imagewebp($image, $path);
        imagedestroy($image);

        return $written;
    }

    private function readDocxDocument(string $path): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue(true === $zip->open($path), 'DOCX must be a valid OOXML zip');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertNotFalse($xml, 'DOCX must contain word/document.xml');

        return (string) $xml;
    }

    private function isZipContaining(string $path, string $entry): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return false;
        }
        $found = false !== $zip->locateName($entry);
        $zip->close();

        return $found;
    }

    private function docxContainsMedia(string $path): bool
    {
        return $this->zipContainsEntryPrefix($path, 'word/media/');
    }

    private function zipContainsEntryPrefix(string $path, string $prefix): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return false;
        }

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && str_starts_with($name, $prefix)) {
                $zip->close();

                return true;
            }
        }

        $zip->close();

        return false;
    }
}
