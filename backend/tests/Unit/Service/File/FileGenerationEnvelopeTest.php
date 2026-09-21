<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileGenerationEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\File\FileGenerationEnvelope
 */
class FileGenerationEnvelopeTest extends TestCase
{
    public function testExtractsPureJsonEnvelope(): void
    {
        $data = FileGenerationEnvelope::extract('{"BFILEPATH":"report.docx","BFILETEXT":"# Title\nBody"}');

        $this->assertNotNull($data);
        $this->assertSame('report.docx', $data['filename']);
        $this->assertSame("# Title\nBody", $data['content']);
        $this->assertSame('docx', $data['extension']);
    }

    public function testExtractsFencedJsonEnvelope(): void
    {
        $reply = "```json\n{\"BFILEPATH\":\"data.csv\",\"BFILETEXT\":\"a,b\\nc,d\"}\n```";

        $data = FileGenerationEnvelope::extract($reply);

        $this->assertNotNull($data);
        $this->assertSame('data.csv', $data['filename']);
        $this->assertSame('csv', $data['extension']);
    }

    /**
     * The regression at the heart of #1406: a conversational sentence precedes
     * the envelope, so the reply does not start with `{`.
     */
    public function testExtractsEnvelopeAfterProsePreamble(): void
    {
        $reply = 'Here is the presentation with a cover slide: '
            .'{"BFILEPATH":"slides.pptx","BFILETEXT":"# Slide one\nContent"}';

        $data = FileGenerationEnvelope::extract($reply);

        $this->assertNotNull($data);
        $this->assertSame('slides.pptx', $data['filename']);
        $this->assertSame('pptx', $data['extension']);
        $this->assertSame("# Slide one\nContent", $data['content']);
    }

    public function testIgnoresBracesInsideFileContent(): void
    {
        $reply = 'Sure, here you go: '
            .'{"BFILEPATH":"a.docx","BFILETEXT":"code: function() { return {a: 1}; }"}';

        $data = FileGenerationEnvelope::extract($reply);

        $this->assertNotNull($data);
        $this->assertSame('code: function() { return {a: 1}; }', $data['content']);
    }

    public function testReturnsNullForPlainProse(): void
    {
        $this->assertNull(FileGenerationEnvelope::extract('I cannot create that document right now.'));
    }

    public function testReturnsNullForNonFileJson(): void
    {
        $this->assertNull(FileGenerationEnvelope::extract('{"BTEXT":"just a chat reply"}'));
    }

    public function testReturnsNullForEmptyContent(): void
    {
        $this->assertNull(FileGenerationEnvelope::extract('{"BFILEPATH":"a.docx","BFILETEXT":"   "}'));
    }

    public function testReturnsNullForMalformedEmbeddedEnvelope(): void
    {
        $reply = 'Here is your file: {"BFILEPATH":"a.docx","BFILETEXT":"unterminated';

        $this->assertNull(FileGenerationEnvelope::extract($reply));
        $this->assertTrue(FileGenerationEnvelope::hasSignature($reply));
    }

    public function testExtractsOptionalPdfExportKey(): void
    {
        $data = FileGenerationEnvelope::extract(
            '{"BFILEPATH":"report.docx","BFILETEXT":"# Title","BEXPORT":"pdf"}'
        );

        $this->assertNotNull($data);
        $this->assertSame('report.docx', $data['filename']);
        $this->assertSame('docx', $data['extension']);
        $this->assertSame('pdf', $data['export']);
    }

    public function testIgnoresUnknownExportTarget(): void
    {
        $data = FileGenerationEnvelope::extract(
            '{"BFILEPATH":"report.docx","BFILETEXT":"# Title","BEXPORT":"html"}'
        );

        $this->assertNotNull($data);
        $this->assertArrayNotHasKey('export', $data);
    }

    public function testSignatureRequiresBothFileEnvelopeKeys(): void
    {
        $this->assertFalse(FileGenerationEnvelope::hasSignature('Plain prose'));
        $this->assertFalse(FileGenerationEnvelope::hasSignature('{"BFILEPATH":"a.docx"}'));
        $this->assertFalse(FileGenerationEnvelope::hasSignature('The BFILEPATH field is mentioned in prose.'));
        $this->assertTrue(FileGenerationEnvelope::hasSignature('{"BFILEPATH":"a.docx","BFILETEXT":'));
    }

    #[DataProvider('provideRequestedFormats')]
    public function testRequestedFormatDetectsNamedFormats(string $text, ?string $expected): void
    {
        $this->assertSame($expected, FileGenerationEnvelope::requestedFormat($text));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideRequestedFormats(): iterable
    {
        yield 'german excel' => ['Vergleich Plan und Ist, ich will die Abweichung als Excel.', 'xlsx'];
        yield 'english xlsx' => ['Compare plan and actual, give me the variance as xlsx.', 'xlsx'];
        yield 'last match wins' => ['Mach aus dem CSV ein Excel.', 'xlsx'];
        yield 'source mention loses to output' => ['Create a Word document from this Excel spreadsheet.', 'docx'];
        yield 'von csv nach excel' => ['Konvertiere von CSV nach Excel.', 'xlsx'];
        yield 'url source never elects' => ['Lad https://example.com/data.xlsx und mach ein Diagramm.', null];
        yield 'csv explicit' => ['Export the table as CSV, please.', 'csv'];
        yield 'word document' => ['Write it as a Word document.', 'docx'];
        yield 'german word compound' => ['Schreib mir ein Word-Dokument dazu.', 'docx'];
        yield 'powerpoint' => ['Make me a PowerPoint deck from this.', 'pptx'];
        yield 'bare word is not a format' => ['In other words, summarize it.', null];
        yield 'pdf stays with the export logic' => ['Create a PDF agenda.', null];
        yield 'no format named' => ['Vergleich Plan und Ist.', null];
    }
}
