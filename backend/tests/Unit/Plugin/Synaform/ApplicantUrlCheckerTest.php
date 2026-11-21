<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plugin\Synaform;

use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Synaform\Service\ApplicantUrlChecker;
use Plugin\Synaform\Service\ApplicantUrlFetch;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApplicantUrlCheckerTest extends TestCase
{
    private const MARKER = 'SYNAFORM-SSRF-MARKER-7f3a';

    /** @return list<array{0: string}> */
    public static function refusedUrls(): array
    {
        return [
            ['http://127.0.0.1/secrets'],
            ['http://127.0.0.1:8081/synaform-ssrf-probe'],
            ['http://10.0.0.5/internal'],
            ['http://169.254.169.254/latest/meta-data'],
            ['http://[::1]/'],
            ['http://localhost/admin'],
            ['http://ssrf-marker:8081/synaform-ssrf-probe'],
            ['http://backend/status'],
            ['ftp://93.184.216.34/file'],
            ['file:///etc/passwd'],
            ['http://2130706433/'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesInternalAndNonHttpAddressesWithoutARequest(string $url): void
    {
        $calls = 0;
        $checker = $this->checker(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::MARKER, ['http_code' => 200]);
        });

        $fetch = $checker->check($url);

        self::assertTrue($fetch->refused);
        self::assertNull($fetch->text);
        self::assertNotNull($fetch->warning);
        self::assertStringNotContainsString(self::MARKER, $fetch->warning);
        self::assertSame(0, $calls);
    }

    public function testKeepsTextFromAPublicPage(): void
    {
        $checker = $this->checker(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('http://93.184.216.34/cv', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertLessThanOrEqual(15, $options['max_duration']);
            self::assertTrue($options['extra']['egress_typed'] ?? false);

            return new MockResponse(
                '<html><script>secret()</script><body><p>Jane Doe, engineer</p></body></html>',
                ['http_code' => 200],
            );
        });

        $fetch = $checker->check('http://93.184.216.34/cv');

        self::assertFalse($fetch->refused);
        self::assertNull($fetch->warning);
        self::assertNotNull($fetch->text);
        self::assertStringContainsString('Jane Doe, engineer', $fetch->text);
        self::assertStringNotContainsString('secret()', $fetch->text);
    }

    public function testDropsTheBodyOfAnErrorPage(): void
    {
        $checker = $this->checker(fn (): MockResponse => new MockResponse(
            'not found '.self::MARKER,
            ['http_code' => 404],
        ));

        $fetch = $checker->check('https://8.8.8.8/missing');

        self::assertFalse($fetch->refused);
        self::assertNull($fetch->text);
        self::assertNotNull($fetch->warning);
        self::assertStringContainsString('HTTP 404', $fetch->warning);
        self::assertStringNotContainsString(self::MARKER, $fetch->warning);
    }

    public function testDoesNotFollowARedirectOntoAPrivateAddress(): void
    {
        $calls = 0;
        $checker = $this->checker(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::MARKER, [
                'http_code' => 302,
                'response_headers' => ['location' => 'http://10.0.0.5/secret'],
            ]);
        });

        $fetch = $checker->check('http://93.184.216.34/go');

        self::assertTrue($fetch->refused);
        self::assertNull($fetch->text);
        self::assertSame(ApplicantUrlChecker::PUBLIC_ONLY, $fetch->warning);
        self::assertSame(1, $calls);
    }

    public function testFollowsARedirectThatStaysOnAPublicAddress(): void
    {
        $urls = [];
        $checker = $this->checker(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;
            if (1 === count($urls)) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['location' => '/cv.html'],
                ]);
            }

            return new MockResponse('<p>Public CV</p>', ['http_code' => 200]);
        });

        $fetch = $checker->check('http://93.184.216.34/profile');

        self::assertSame([
            'http://93.184.216.34/profile',
            'http://93.184.216.34/cv.html',
        ], $urls);
        self::assertFalse($fetch->refused);
        self::assertSame('Public CV', $fetch->text);
        self::assertNull($fetch->warning);
    }

    public function testKeepsThePathForAQueryOrFragmentRedirect(): void
    {
        $urls = [];
        $checker = $this->checker(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;
            if (1 === count($urls)) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['location' => '?view=print'],
                ]);
            }
            if (2 === count($urls)) {
                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => ['location' => '#cv'],
                ]);
            }

            return new MockResponse('<p>Printable CV</p>', ['http_code' => 200]);
        });

        $fetch = $checker->check('http://93.184.216.34/profiles/jane?lang=en');

        self::assertSame([
            'http://93.184.216.34/profiles/jane?lang=en',
            'http://93.184.216.34/profiles/jane?view=print',
            'http://93.184.216.34/profiles/jane?view=print#cv',
        ], $urls);
        self::assertSame('Printable CV', $fetch->text);
        self::assertFalse($fetch->refused);
    }

    public function testABodyThatFailsToDownloadIsAWarningWithNoText(): void
    {
        $checker = $this->checker(fn (): MockResponse => new MockResponse((static function (): \Generator {
            yield '<p>partial';
            throw new TransportException('idle timeout');
        })(), ['http_code' => 200]));

        $fetch = $checker->check('http://93.184.216.34/cv');

        self::assertFalse($fetch->refused);
        self::assertNull($fetch->text);
        self::assertNotNull($fetch->warning);
        self::assertStringNotContainsString('partial', (string) $fetch->warning);
        self::assertStringContainsString('Could not reach the URL', $fetch->warning);
    }

    public function testStoredErrorBodyIsNotUsableText(): void
    {
        self::assertNull(ApplicantUrlFetch::storedText(self::MARKER, 'The site returned HTTP 404 — the page is not publicly readable by a server.'));
        self::assertSame('Jane Doe', ApplicantUrlFetch::storedText('Jane Doe', null));
    }

    private function checker(\Closure $response): ApplicantUrlChecker
    {
        return new ApplicantUrlChecker(new MockHttpClient($response), new SsrfGuard());
    }
}
