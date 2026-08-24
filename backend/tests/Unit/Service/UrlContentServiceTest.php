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
 * HTML extraction: prefer landmarks, fall back to the full body when a
 * targeted region is empty or too short (Kubio / page-builder pages).
 */
final class UrlContentServiceTest extends TestCase
{
    public function testPrefersArticleOverSurroundingChrome(): void
    {
        $html = <<<'HTML'
<html><head><title>Article Title</title></head><body>
<nav>Home About Pricing Contact lots of navigation chrome that is not the article</nav>
<article><p>The actual article body with enough characters to pass the useful-text threshold on its own without falling back.</p></article>
<footer>Copyright 2026 ignore this</footer>
</body></html>
HTML;

        $result = $this->service([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ])->fetch('https://example.com/article');

        self::assertTrue($result->success);
        self::assertSame('Article Title', $result->title);
        self::assertStringContainsString('actual article body', $result->extractedText);
        self::assertStringNotContainsString('navigation chrome', $result->extractedText);
    }

    public function testFallsBackToBodyWhenPageBuilderContentClassIsAFalseMatch(): void
    {
        $html = <<<'HTML'
<html><head><title>Startseite - FPS Energy</title></head><body>
<div class="h-column__content">info@fps.energy</div>
<div class="hero">
  <h1>Für die Energiewende</h1>
  <p>Fuel &amp; Power Supply delivers hydrogen, EV charging and biofuels to industry and fleets that want to act today.</p>
</div>
</body></html>
HTML;

        $chat = $this->service([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ])->fetch('https://fps.energy/');

        self::assertTrue($chat->success);
        self::assertStringContainsString('Energiewende', $chat->extractedText);
        self::assertStringContainsString('hydrogen', $chat->extractedText);
        self::assertGreaterThan(80, mb_strlen($chat->extractedText));

        $crawl = $this->service([
            new MockResponse("User-agent: *\nDisallow:\n", ['http_code' => 200]),
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ])->fetchForCrawling('https://fps.energy/');

        self::assertTrue($crawl->success);
        self::assertStringContainsString('Energiewende', $crawl->extractedText);
        self::assertGreaterThan(80, mb_strlen($crawl->extractedText));
    }

    public function testUsesEntryContentLandmarkWhenItHasEnoughText(): void
    {
        $html = <<<'HTML'
<html><body>
<div class="sidebar">Seasonal offer banner that should lose to the landmark.</div>
<div class="entry-content">
<p>This WordPress entry has enough characters in the landmark so the extractor keeps it instead of dumping the whole page.</p>
</div>
</body></html>
HTML;

        $result = $this->service([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ])->fetch('https://example.com/post');

        self::assertTrue($result->success);
        self::assertStringContainsString('WordPress entry', $result->extractedText);
        self::assertStringNotContainsString('Seasonal offer banner', $result->extractedText);
    }

    public function testFallsBackToUnstrippedBodyWhenChromeRemovalLeavesNothing(): void
    {
        $html = <<<'HTML'
<html><head><title>Short</title></head><body>
<header>
  <h1>Hydrogen for fleets that need a reliable supply today</h1>
  <p>We deliver tank infrastructure, redundancy and safe H2 supply even when the grid is tight.</p>
</header>
</body></html>
HTML;

        $result = $this->service([
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ])->fetch('https://example.com/hero');

        self::assertTrue($result->success);
        self::assertStringContainsString('Hydrogen for fleets', $result->extractedText);
        self::assertStringContainsString('tank infrastructure', $result->extractedText);
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
