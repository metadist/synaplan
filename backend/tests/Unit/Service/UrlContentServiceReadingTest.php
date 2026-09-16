<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Security\SsrfGuard;
use App\Service\UrlContentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Reader mode ({@see UrlContentService::fetchForReading()}): the fetch that
 * runs for links a user pasted and for web-search result pages.
 */
final class UrlContentServiceReadingTest extends TestCase
{
    public function testReadsAPlainArticleAndReportsTheFinalUrl(): void
    {
        $article = str_repeat('The UAE sovereign fund announced a forty billion euro programme for German industry. ', 12);
        $html = '<html><head><title>UAE invests in Germany</title><meta name="description" content="Forty billion for German industry."></head>'
            .'<body><nav>Menu</nav><article><p>'.$article.'</p></article></body></html>';

        $service = $this->service([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html; charset=utf-8']]),
        ]);

        $result = $service->fetchForReading('https://news.example.com/uae-germany');

        self::assertTrue($result->success);
        self::assertSame('UAE invests in Germany', $result->title);
        self::assertSame('https://news.example.com/uae-germany', $result->finalUrl);
        self::assertNull($result->blockedReason);
        self::assertStringContainsString('Forty billion for German industry.', $result->extractedText);
        self::assertStringContainsString('forty billion euro programme', $result->extractedText);
    }

    public function testFollowsHttpRedirectsHopByHopAndKeepsTheOriginalUrl(): void
    {
        $body = '<html><head><title>Target</title></head><body><main><p>'.str_repeat('Real article text about the topic. ', 20).'</p></main></body></html>';
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested, $body): MockResponse {
            $requested[] = $url;

            return match ($url) {
                'https://short.example/abc' => new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://www.example.org/interim']]),
                'https://www.example.org/interim' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/final/article']]),
                'https://www.example.org/final/article' => new MockResponse($body, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
                default => new MockResponse('unexpected '.$url, ['http_code' => 500]),
            };
        });

        $result = (new UrlContentService($client, new SsrfGuard(), new NullLogger()))->fetchForReading('https://short.example/abc');

        self::assertTrue($result->success);
        self::assertSame('https://short.example/abc', $result->url, 'the URL the user gave stays the citation key');
        self::assertSame('https://www.example.org/final/article', $result->finalUrl);
        self::assertSame('www.example.org', $result->finalHostname());
        self::assertSame([
            'https://short.example/abc',
            'https://www.example.org/interim',
            'https://www.example.org/final/article',
        ], $requested);
    }

    public function testRedirectToAPrivateAddressIsBlockedBeforeItIsRequested(): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data/']]);
        });

        $result = (new UrlContentService($client, new SsrfGuard(), new NullLogger()))->fetchForReading('https://public.example.com/go');

        self::assertFalse($result->success);
        self::assertSame('blocked_address', $result->blockedReason);
        self::assertSame(['https://public.example.com/go'], $requested, 'the metadata endpoint must never be contacted');
    }

    public function testStopsOnARedirectLoop(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $target = 'https://loop.example.com/a' === $url ? 'https://loop.example.com/b' : 'https://loop.example.com/a';

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $target]]);
        });

        $result = (new UrlContentService($client, new SsrfGuard(), new NullLogger()))->fetchForReading('https://loop.example.com/a');

        self::assertFalse($result->success);
        self::assertSame('redirect_loop', $result->blockedReason);
    }

    public function testResolvesALinkedInShortlinkInterstitialToTheExternalTarget(): void
    {
        $interstitial = '<html><head><title>LinkedIn</title></head><body>'
            .'<a href="https://www.linkedin.com/">Home</a>'
            .'<p>This link will take you to a page that\'s not on LinkedIn</p>'
            .'<a href="https://www.handelsblatt.com/politik/uae-investiert-40-milliarden/" class="artdeco-button">Continue</a>'
            .'<img src="https://static.licdn.com/x.png"></body></html>';
        $article = '<html><head><title>VAE investieren 40 Milliarden</title></head><body><article><p>'
            .str_repeat('Die Vereinigten Arabischen Emirate investieren in Wasserstoff, Chemie und Häfen. ', 15).'</p></article></body></html>';

        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested, $interstitial, $article): MockResponse {
            $requested[] = $url;

            return match ($url) {
                'https://lnkd.in/p/d_-_Y6Ye' => new MockResponse($interstitial, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
                'https://www.handelsblatt.com/politik/uae-investiert-40-milliarden/' => new MockResponse($article, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
                default => new MockResponse('unexpected '.$url, ['http_code' => 500]),
            };
        });

        $result = (new UrlContentService($client, new SsrfGuard(), new NullLogger()))->fetchForReading('https://lnkd.in/p/d_-_Y6Ye');

        self::assertTrue($result->success);
        self::assertSame('https://www.handelsblatt.com/politik/uae-investiert-40-milliarden/', $result->finalUrl);
        self::assertSame('VAE investieren 40 Milliarden', $result->title);
        self::assertStringContainsString('Wasserstoff, Chemie und Häfen', $result->extractedText);
        self::assertCount(2, $requested);
    }

    public function testFollowsAMetaRefreshRedirect(): void
    {
        $refresh = '<html><head><meta http-equiv="refresh" content="0; url=https://www.example.com/landing"></head><body>Redirecting…</body></html>';
        $landing = '<html><head><title>Landing</title></head><body><main><p>'.str_repeat('Landing page content. ', 30).'</p></main></body></html>';

        $client = new MockHttpClient(fn (string $method, string $url): MockResponse => match ($url) {
            'https://go.example.com/x' => new MockResponse($refresh, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
            'https://www.example.com/landing' => new MockResponse($landing, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
            default => new MockResponse('unexpected '.$url, ['http_code' => 500]),
        });

        $result = (new UrlContentService($client, new SsrfGuard(), new NullLogger()))->fetchForReading('https://go.example.com/x');

        self::assertTrue($result->success);
        self::assertSame('https://www.example.com/landing', $result->finalUrl);
        self::assertSame('Landing', $result->title);
    }

    public function testLinkedInStatus999IsReportedAsBotBlocked(): void
    {
        $service = $this->service([
            new MockResponse('', ['http_code' => 999]),
        ]);

        $result = $service->fetchForReading('https://www.linkedin.com/posts/someone_activity-123');

        self::assertFalse($result->success);
        self::assertSame('bot_blocked', $result->blockedReason);
        self::assertSame('https://www.linkedin.com/posts/someone_activity-123', $result->finalUrl);
    }

    public function testAuthWallPageIsReportedAsLoginWallWithItsPublicPreview(): void
    {
        $html = '<html><head><title>Sign in</title><meta name="description" content="Great post about hydrogen hubs in Hamburg."></head>'
            .'<body><h1>Join LinkedIn</h1><p>Sign in to LinkedIn to see this post.</p></body></html>';

        $service = $this->service([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        $result = $service->fetchForReading('https://www.linkedin.com/authwall?trk=x');

        self::assertFalse($result->success);
        self::assertSame('login_wall', $result->blockedReason);
        self::assertSame('Great post about hydrogen hubs in Hamburg.', $result->extractedText, 'the meta description is the only public preview');
    }

    public function testBinaryContentIsRejectedAsUnsupported(): void
    {
        $service = $this->service([
            new MockResponse('%PDF-1.7 ...', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/pdf']]),
        ]);

        $result = $service->fetchForReading('https://example.com/report.pdf');

        self::assertFalse($result->success);
        self::assertSame('unsupported_content', $result->blockedReason);
    }

    public function testJsonAndPlainTextAreReturnedVerbatimAndCapped(): void
    {
        $service = $this->service([
            new MockResponse('{"figure": 40000000000, "unit": "EUR"}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
            new MockResponse(str_repeat('x', 1000), ['http_code' => 200, 'response_headers' => ['content-type' => 'text/plain']]),
        ]);

        $json = $service->fetchForReading('https://api.example.com/data');
        self::assertTrue($json->success);
        self::assertSame('{"figure": 40000000000, "unit": "EUR"}', $json->extractedText);

        $capped = $service->fetchForReading('https://example.com/notes.txt', 300);
        self::assertTrue($capped->success);
        self::assertSame(303, mb_strlen($capped->extractedText), '300 chars plus the ellipsis');
    }

    public function testEmptyClientRenderedPageIsReportedAsNoText(): void
    {
        $service = $this->service([
            new MockResponse('<html><head><title>App</title></head><body><div id="root"></div><script src="/app.js"></script></body></html>', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        $result = $service->fetchForReading('https://spa.example.com/');

        self::assertFalse($result->success);
        self::assertSame('no_text', $result->blockedReason);
    }

    public function testFetchManyForReadingDeduplicatesAndRespectsTheLimit(): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('<html><body><p>'.str_repeat('Some page text. ', 30).'</p></body></html>', ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]);
        });

        $results = (new UrlContentService($client, new SsrfGuard(), new NullLogger()))->fetchManyForReading([
            'https://a.example.com/',
            'https://a.example.com/',
            'https://b.example.com/',
            'https://c.example.com/',
        ], 5000, 2);

        self::assertCount(2, $results);
        self::assertSame(['https://a.example.com/', 'https://b.example.com/'], $requested);
    }

    public function testFormatForPromptShowsTheResolvedUrlWhenItDiffers(): void
    {
        $body = '<html><head><title>T</title></head><body><main><p>'.str_repeat('Article body. ', 30).'</p></main></body></html>';
        $client = new MockHttpClient(fn (string $method, string $url): MockResponse => match ($url) {
            'https://s.example/1' => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://www.example.com/full']]),
            default => new MockResponse($body, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        });
        $service = new UrlContentService($client, new SsrfGuard(), new NullLogger());

        $prompt = $service->formatForPrompt([$service->fetchForReading('https://s.example/1')]);

        self::assertStringContainsString('--- URL: https://s.example/1 ---', $prompt);
        self::assertStringContainsString('Resolved to: https://www.example.com/full', $prompt);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function service(array $responses): UrlContentService
    {
        return new UrlContentService(
            new MockHttpClient($responses),
            new SsrfGuard(),
            new NullLogger(),
        );
    }
}
