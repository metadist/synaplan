<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\Extraction;

use App\Plug\Extraction\Adapter\DoclingExtractor;
use App\Plug\Extraction\Docling\DoclingClient;
use App\Plug\Extraction\ExtractionRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DoclingExtractorContractTest extends TestCase
{
    public function testExtractReturnsMarkdownAndTextFromRecordedResponse(): void
    {
        $fixture = (string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/extraction/recorded/docling/invoice-table.json',
        );
        $http = new MockHttpClient([
            new MockResponse($fixture, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
        ]);
        $extractor = new DoclingExtractor(
            new DoclingClient($http, new NullLogger(), 'http://docling.test', 120000),
            50 * 1024 * 1024,
        );

        $path = tempnam(sys_get_temp_dir(), 'docling-contract-');
        self::assertNotFalse($path);
        file_put_contents($path, "%PDF-1.4 recorded\n");

        $request = new ExtractionRequest($path, basename($path), 'application/pdf', 'pdf', 1, false, 'document');
        self::assertTrue($extractor->supports($request));

        $result = $extractor->extract($request);
        self::assertSame('docling', $result->strategy);
        self::assertStringContainsString('EMEA', $result->text);
        self::assertNotNull($result->markdown);
        self::assertStringContainsString('| Region | Q3 |', $result->markdown);
        self::assertSame(1, $result->meta['pages'] ?? null);

        @unlink($path);
    }

    public function testSupportsRejectsAudioFamily(): void
    {
        $extractor = new DoclingExtractor(
            new DoclingClient(new MockHttpClient(), new NullLogger(), 'http://docling.test', 120000),
            50 * 1024 * 1024,
        );
        $path = tempnam(sys_get_temp_dir(), 'docling-audio-');
        self::assertNotFalse($path);
        file_put_contents($path, 'x');
        $request = new ExtractionRequest($path, basename($path), 'audio/mpeg', 'mp3', 1, false, 'audio');
        self::assertFalse($extractor->supports($request));
        @unlink($path);
    }
}
