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

    public function testFollowsAProtocolRelativeRedirectToTheNamedHost(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $url;
            if (1 === \count($seen)) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['Location: //cdn.example.com/file.pdf'],
                ]);
            }

            return new MockResponse('%PDF-1.4');
        });
        $download = $this->download($client, blocked: false);

        $result = $download->fetch('https://example.com:8443/docs/report.pdf', 'pdf');

        self::assertTrue($result->ok);
        self::assertSame([
            'https://example.com:8443/docs/report.pdf',
            'https://cdn.example.com/file.pdf',
        ], $seen);
        unlink($result->path);
    }

    public function testFollowsAMixedCaseAbsoluteRedirect(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $url;
            if (1 === \count($seen)) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['Location: HTTPS://cdn.example.com/file.pdf'],
                ]);
            }

            return new MockResponse('%PDF-1.4');
        });
        $download = $this->download($client, blocked: false);

        $result = $download->fetch('https://example.com/docs/report.pdf', 'pdf');

        self::assertTrue($result->ok);
        self::assertSame([
            'https://example.com/docs/report.pdf',
            'https://cdn.example.com/file.pdf',
        ], $seen);
        unlink($result->path);
    }

    public function testFollowsASameHostAbsolutePathRedirect(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = $url;
            if (1 === \count($seen)) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['Location: /files/a.pdf'],
                ]);
            }

            return new MockResponse('%PDF-1.4');
        });
        $download = $this->download($client, blocked: false);

        $result = $download->fetch('https://example.com:8443/docs/report.pdf', 'pdf');

        self::assertTrue($result->ok);
        self::assertSame([
            'https://example.com:8443/docs/report.pdf',
            'https://example.com:8443/files/a.pdf',
        ], $seen);
        unlink($result->path);
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
        self::assertLessThanOrEqual(GuardedDocumentDownload::TIMEOUT_SECONDS, $seen['timeout']);
        self::assertGreaterThan(GuardedDocumentDownload::TIMEOUT_SECONDS - 1, $seen['timeout']);
        self::assertLessThanOrEqual(GuardedDocumentDownload::TIMEOUT_SECONDS, $seen['max_duration']);
        self::assertSame(0, $seen['max_redirects']);
        unlink($result->path);
    }

    public function testCallerBudgetLowersTheCap(): void
    {
        $seen = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('%PDF-1.4');
        });

        $result = $this->download($client, blocked: false)->fetch('https://example.com/report.pdf', 'pdf', 4);

        self::assertTrue($result->ok);
        self::assertLessThanOrEqual(4, $seen['max_duration']);
        unlink($result->path);
    }

    public function testHtmlBehindAPdfLinkIsNotTreatedAsTheDocument(): void
    {
        $client = new MockHttpClient([new MockResponse('<html><body>Please sign in</body></html>')]);

        $result = $this->download($client, blocked: false)->fetch('https://example.com/report.pdf', 'pdf');

        self::assertFalse($result->ok);
        self::assertSame('unsupported_content', $result->reason);
    }

    public function testDocxMustBeAZip(): void
    {
        $client = new MockHttpClient([new MockResponse('not a zip')]);

        $result = $this->download($client, blocked: false)->fetch('https://example.com/contract.docx', 'docx');

        self::assertSame('unsupported_content', $result->reason);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirectProvider(): iterable
    {
        yield 'dot segments' => ['../other/c.pdf?v=2', 'https://example.com/docs/other/c.pdf?v=2'];
        yield 'query only' => ['?download=1', 'https://example.com/docs/files/a.pdf?download=1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirectProvider')]
    public function testFollowsRelativeRedirectLocations(string $location, string $expected): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested, $location): MockResponse {
            $requested[] = $url;
            if (1 === \count($requested)) {
                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: '.$location]]);
            }

            return new MockResponse('%PDF-1.4');
        });

        $result = $this->download($client, blocked: false)->fetch('https://example.com/docs/files/a.pdf', 'pdf');

        self::assertTrue($result->ok);
        self::assertSame($expected, $requested[1]);
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
