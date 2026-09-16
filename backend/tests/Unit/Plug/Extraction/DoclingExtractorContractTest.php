<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\Extraction;

use App\Plug\Extraction\Adapter\DoclingExtractor;
use App\Plug\Extraction\Docling\DoclingClient;
use App\Plug\Extraction\Docling\DoclingRejectedException;
use App\Plug\Extraction\Docling\DoclingUnavailableException;
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

    public function testConvertFileSendsMultipartFormDataNotUrlencoded(): void
    {
        $fixture = (string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/extraction/recorded/docling/invoice-table.json',
        );
        $contentType = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($fixture, &$contentType): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/v1/convert/file', $url);
            $contentType = self::headerLine($options, 'content-type');

            return new MockResponse($fixture, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $client = new DoclingClient($http, new NullLogger(), 'http://docling.test', 120000);

        $path = tempnam(sys_get_temp_dir(), 'docling-multipart-');
        self::assertNotFalse($path);
        file_put_contents($path, "%PDF-1.4 recorded\n");

        $converted = $client->convertFile($path);
        self::assertNotSame('', $converted['text']);
        self::assertNotNull($contentType);
        self::assertStringContainsString('multipart/form-data', strtolower($contentType));
        self::assertStringNotContainsString('application/x-www-form-urlencoded', strtolower($contentType));

        @unlink($path);
    }

    public function testConvertFileHttp422IsRejectedNotUnavailable(): void
    {
        $http = new MockHttpClient([
            new MockResponse(
                '{"detail":[{"type":"missing","loc":["body","files"],"msg":"Field required"}]}',
                ['http_code' => 422],
            ),
        ]);
        $client = new DoclingClient($http, new NullLogger(), 'http://docling.test', 120000);

        $path = tempnam(sys_get_temp_dir(), 'docling-422-');
        self::assertNotFalse($path);
        file_put_contents($path, "%PDF-1.4 recorded\n");

        try {
            $client->convertFile($path);
            self::fail('HTTP 422 must not be treated as a successful convert');
        } catch (DoclingRejectedException $e) {
            self::assertStringContainsString('422', $e->getMessage());
        } catch (DoclingUnavailableException $e) {
            self::fail('HTTP 422 means the sidecar answered; must not be DoclingUnavailableException: '.$e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function headerLine(array $options, string $name): string
    {
        $normalized = $options['normalized_headers'][$name] ?? null;
        if (\is_array($normalized) && isset($normalized[0]) && \is_string($normalized[0])) {
            return $normalized[0];
        }

        $headers = $options['headers'] ?? [];
        if (!\is_array($headers)) {
            return '';
        }
        foreach ($headers as $key => $value) {
            $line = \is_string($key) ? $key.': '.(is_array($value) ? implode(',', $value) : (string) $value) : (string) $value;
            if (str_starts_with(strtolower($line), $name.':')) {
                return $line;
            }
        }

        return '';
    }
}
