<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run\Source;

use App\Service\Research\Run\Source\GuardedDocumentDownload;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GuardedDocumentDownloadTest extends TestCase
{
    public function testRejectsAPrivateAddressBeforeAnyRequest(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('A blocked address was fetched');
        });
        $download = $this->download($client, blocked: true);

        $result = $download->fetch('http://127.0.0.1/secret.pdf', 'pdf');

        self::assertFalse($result->ok);
        self::assertSame('blocked_address', $result->reason);
    }

    public function testDoesNotFollowARedirectOntoAPrivateAddress(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '127.0.0.1')) {
                self::fail('A blocked redirect was fetched');
            }

            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location: http://127.0.0.1/secret.pdf'],
            ]);
        });
        $download = new GuardedDocumentDownload($client, $this->guard());

        $result = $download->fetch('https://example.com/file.pdf', 'pdf');

        self::assertFalse($result->ok);
        self::assertSame('blocked_address', $result->reason);
    }

    public function testStopsWhenTheDeclaredSizeIsOverTwentyMegabytes(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn([
            'content-length' => [(string) (GuardedDocumentDownload::MAX_BYTES + 1)],
        ]);
        $response->expects(self::once())->method('cancel');
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn($response);
        $client->expects(self::never())->method('stream');
        $download = $this->download($client, blocked: false);

        $result = $download->fetch('https://example.com/big.pdf', 'pdf');

        self::assertFalse($result->ok);
        self::assertSame('too_large', $result->reason);
        self::assertSame('', $result->path);
    }

    public function testStopsWhenTheBodyGrowsPastTwentyMegabytes(): void
    {
        $client = new MockHttpClient([new MockResponse(str_repeat('a', GuardedDocumentDownload::MAX_BYTES + 1))]);
        $download = $this->download($client, blocked: false);

        $result = $download->fetch('https://example.com/big.pdf', 'pdf');

        self::assertSame('too_large', $result->reason);
        self::assertFalse(is_file($result->path));
    }

    public function testUsesAFifteenSecondCapAndKeepsTheFile(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('%PDF-1.4');
        });
        $download = $this->download($client, blocked: false);

        $result = $download->fetch('https://example.com/report.pdf', 'pdf');

        self::assertTrue($result->ok);
        self::assertStringEndsWith('.pdf', $result->path);
        self::assertSame('%PDF-1.4', (string) file_get_contents($result->path));
        self::assertEquals(GuardedDocumentDownload::TIMEOUT_SECONDS, $seen['timeout']);
        self::assertEquals(GuardedDocumentDownload::TIMEOUT_SECONDS, $seen['max_duration']);
        self::assertSame(0, $seen['max_redirects']);
        unlink($result->path);
    }

    public function testTimeoutIsReportedAsTimeout(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            throw new TimeoutException('Idle timeout reached for "https://example.com/report.pdf".');
        });
        $download = $this->download($client, blocked: false);

        $result = $download->fetch('https://example.com/report.pdf', 'pdf');

        self::assertSame('timeout', $result->reason);
    }

    private function download(HttpClientInterface $client, bool $blocked): GuardedDocumentDownload
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->method('isBlockedUrl')->willReturn($blocked);
        $guard->method('pinnedResolveOption')->willReturn([]);

        return new GuardedDocumentDownload($client, $guard);
    }

    private function guard(): SsrfGuard
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->method('isBlockedUrl')->willReturnCallback(
            static fn (string $url): bool => str_contains($url, '127.0.0.1'),
        );
        $guard->method('pinnedResolveOption')->willReturn([]);

        return $guard;
    }
}
