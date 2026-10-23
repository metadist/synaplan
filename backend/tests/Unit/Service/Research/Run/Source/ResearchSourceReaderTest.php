<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run\Source;

use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\ExtractionRegistry;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Service\File\Office\StructuredTextExtractor;
use App\Service\Research\Run\Source\CloudDocumentReader;
use App\Service\Research\Run\Source\DocumentFetcher;
use App\Service\Research\Run\Source\DownloadedDocument;
use App\Service\Research\Run\Source\ResearchSourceReader;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ResearchSourceReaderTest extends TestCase
{
    public function testReadsAPdfAndADocxFromTheDownload(): void
    {
        $pdf = $this->tempFile('%PDF-1.4 fixture');
        $docx = $this->docx('Kündigungsfrist: drei Monate');
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher->expects(self::exactly(2))->method('fetch')->willReturnCallback(
            static function (string $url) use ($pdf, $docx): DownloadedDocument {
                return DownloadedDocument::saved(str_ends_with($url, '.docx') ? $docx : $pdf);
            },
        );
        $cloud = $this->createMock(CloudDocumentReader::class);
        $cloud->expects(self::never())->method('read');
        $reader = $this->reader($fetcher, $cloud, 'Article 72 applies to this regulation.');

        $pdfRead = $reader->read('https://example.com/report.pdf', false);
        $docxRead = $reader->read('https://example.com/contract.docx', false);

        self::assertFalse($pdfRead->blocked);
        self::assertSame('Article 72 applies to this regulation.', $pdfRead->text);
        self::assertFalse($docxRead->blocked);
        self::assertStringContainsString('Kündigungsfrist: drei Monate', $docxRead->text);
        self::assertFileDoesNotExist($pdf);
        self::assertFileDoesNotExist($docx);
    }

    public function testLocalPdfWithNoTextNeverCallsVision(): void
    {
        $pdf = $this->tempFile('%PDF-1.4 empty');
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher->method('fetch')->willReturn(DownloadedDocument::saved($pdf));
        $cloud = $this->createMock(CloudDocumentReader::class);
        $cloud->expects(self::never())->method('read');
        $reader = $this->reader($fetcher, $cloud, '');

        $read = $reader->read('https://example.com/scan.pdf', false);

        self::assertTrue($read->blocked);
        self::assertSame('unreadable', $read->reason);
        self::assertSame('', $read->text);
        self::assertFileDoesNotExist($pdf);
    }

    public function testCloudReadCallsVisionOnceWhenTextExtractionFails(): void
    {
        $pdf = $this->tempFile('%PDF-1.4 scan');
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher->method('fetch')->willReturn(DownloadedDocument::saved($pdf));
        $cloud = $this->createMock(CloudDocumentReader::class);
        $cloud->expects(self::once())->method('read')->willReturn('scanned article 72');
        $reader = $this->reader($fetcher, $cloud, '');

        $read = $reader->read('https://example.com/scan.pdf', true);

        self::assertFalse($read->blocked);
        self::assertSame('scanned article 72', $read->text);
    }

    public function testSecondReadOfTheSameUrlDoesNotDownloadAgain(): void
    {
        $pdf = $this->tempFile('%PDF-1.4 cached');
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher->expects(self::once())->method('fetch')->willReturn(DownloadedDocument::saved($pdf));
        $reader = $this->reader($fetcher, $this->createMock(CloudDocumentReader::class), 'cached regulation text');

        $first = $reader->read('https://example.com/report.pdf', false);
        $second = $reader->read('https://example.com/report.pdf', false);

        self::assertSame($first->text, $second->text);
        self::assertFalse($second->blocked);
    }

    public function testOneHostIsFetchedAtATime(): void
    {
        $pdf = $this->tempFile('%PDF-1.4 host');
        $gate = new HostBusyProbe();
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher->expects(self::once())->method('fetch')->willReturnCallback(
            function () use ($gate, $pdf): DownloadedDocument {
                $inner = $gate->reader->read('https://example.com/other.pdf', false);
                self::assertTrue($inner->blocked);
                self::assertSame('host_busy', $inner->reason);

                return DownloadedDocument::saved($pdf);
            },
        );
        $gate->reader = $this->reader($fetcher, $this->createMock(CloudDocumentReader::class), 'outer text');

        $outer = $gate->reader->read('https://example.com/report.pdf', false);

        self::assertFalse($outer->blocked);
        self::assertSame('outer text', $outer->text);
    }

    private function reader(DocumentFetcher&MockObject $fetcher, CloudDocumentReader&MockObject $cloud, string $extracted): ResearchSourceReader
    {
        $pages = $this->createMock(UrlContentService::class);
        $pages->method('fetchForReading')->willReturnCallback(
            static fn (string $url): UrlContentResult => new UrlContentResult($url, 'html page', 'Title', 'example.com', true),
        );
        $office = $this->createMock(StructuredTextExtractor::class);
        $office->method('supports')->willReturn(false);
        $config = $this->createMock(PlugConfigService::class);
        $config->method('extractionChain')->willReturn(['local']);

        return new ResearchSourceReader(
            $pages,
            $fetcher,
            new ExtractionRegistry([$this->extractor($extracted)], $config, new NullLogger()),
            $office,
            $cloud,
            new NullLogger(),
        );
    }

    private function extractor(string $text): ContentExtractorInterface
    {
        return new class($text) implements ContentExtractorInterface {
            public function __construct(private string $text)
            {
            }

            public function key(): string
            {
                return 'local';
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor('local', 'Local', '', [], 'self-hosted');
            }

            public function supports(ExtractionRequest $request): bool
            {
                return 'pdf' === $request->ext;
            }

            public function extract(ExtractionRequest $request): ExtractionResult
            {
                return ExtractionResult::of($this->text, 'local');
            }

            public function runsInCloud(): bool
            {
                return false;
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }
        };
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rs4');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }

    private function docx(string $sentence): string
    {
        $path = sys_get_temp_dir().'/rs4-'.bin2hex(random_bytes(4)).'.docx';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
            .htmlspecialchars($sentence, \ENT_XML1)
            .'</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }
}

final class HostBusyProbe
{
    public ResearchSourceReader $reader;
}
