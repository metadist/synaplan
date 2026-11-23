<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use App\Service\Security\FixtureHostResolver;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A public page that redirects to an internal address: the fetch stops at
 * the redirect and the internal address is never requested.
 */
final class UrlContentServiceRedirectTest extends TestCase
{
    private const INTERNAL = 'http://127.0.0.1:6333/collections';
    private const SECRET = '{"result":{"collections":[{"name":"user_documents"}]}}';

    /** @var list<string> */
    private array $requested = [];

    /** @return array<string, array{0: \Closure(UrlContentService, string): UrlContentResult}> */
    public static function fetchers(): array
    {
        return [
            'fetch' => [static fn (UrlContentService $s, string $url): UrlContentResult => $s->fetch($url)],
            'fetchForCrawling' => [static fn (UrlContentService $s, string $url): UrlContentResult => $s->fetchForCrawling($url)],
            'fetchApi' => [static fn (UrlContentService $s, string $url): UrlContentResult => $s->fetchApi($url, 'GET', ['Authorization' => 'Bearer x'])],
            'fetchForReading' => [static fn (UrlContentService $s, string $url): UrlContentResult => $s->fetchForReading($url)],
        ];
    }

    /**
     * @param \Closure(UrlContentService, string): UrlContentResult $fetch
     */
    #[DataProvider('fetchers')]
    public function testARedirectToAnInternalAddressIsRefused(\Closure $fetch): void
    {
        $result = $fetch($this->service(), 'https://public.example.com/go');

        self::assertFalse($result->success);
        self::assertSame('URL points to a private/blocked address', $result->error);
        self::assertSame('', $result->extractedText);
        self::assertNotContains(self::INTERNAL, $this->requested);
    }

    /**
     * @param \Closure(UrlContentService, string): UrlContentResult $fetch
     */
    #[DataProvider('fetchers')]
    public function testAPublicRedirectIsStillFollowed(\Closure $fetch): void
    {
        $result = $fetch($this->service(), 'https://public.example.com/moved');

        self::assertTrue($result->success, (string) $result->error);
        self::assertStringContainsString('Public article text', $result->extractedText);
        self::assertContains('https://www.example.org/article', $this->requested);
    }

    public function testARobotsFileThatRedirectsInsideIsNotRequestedThere(): void
    {
        $this->service()->fetchForCrawling('https://robots.example.com/page');

        self::assertContains('https://robots.example.com/robots.txt', $this->requested);
        self::assertNotContains(self::INTERNAL, $this->requested);
    }

    private function service(): UrlContentService
    {
        $article = '<html><head><title>Article</title></head><body><article><p>'
            .str_repeat('Public article text that is long enough to count as content. ', 6)
            .'</p></article></body></html>';

        $client = new MockHttpClient(function (string $method, string $url) use ($article): MockResponse {
            $this->requested[] = $url;

            return match ($url) {
                'https://public.example.com/go',
                'https://robots.example.com/robots.txt' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => self::INTERNAL]]),
                'https://public.example.com/moved' => new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://www.example.org/article']]),
                'https://www.example.org/article',
                'https://robots.example.com/page' => new MockResponse($article, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
                self::INTERNAL => new MockResponse(self::SECRET, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });

        return new UrlContentService($client, new SsrfGuard(new FixtureHostResolver()), new NullLogger());
    }
}
