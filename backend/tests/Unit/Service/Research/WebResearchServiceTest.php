<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research;

use App\Plug\PlugConfigService;
use App\Service\Context\CondensedText;
use App\Service\Context\ContextCondenser;
use App\Service\Research\WebResearchService;
use App\Service\UrlContentResult;
use App\Service\UrlContentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebResearchServiceTest extends TestCase
{
    private UrlContentService&MockObject $urlContent;
    private ContextCondenser&MockObject $condenser;
    private PlugConfigService&MockObject $plugConfig;

    protected function setUp(): void
    {
        $this->urlContent = $this->createMock(UrlContentService::class);
        $this->condenser = $this->createMock(ContextCondenser::class);
        $this->plugConfig = $this->createMock(PlugConfigService::class);
        $this->plugConfig->method('isWebSearchReadPagesEnabled')->willReturn(true);
        $this->plugConfig->method('webSearchReadPagesMax')->willReturn(4);
        $this->plugConfig->method('webSearchReadPagesBudgetChars')->willReturn(28000);
        $this->plugConfig->method('isUrlReadEnabled')->willReturn(true);
        $this->plugConfig->method('urlReadMax')->willReturn(3);

        // Default: the condenser passes text through verbatim.
        $this->condenser->method('fit')->willReturnCallback(
            static fn (string $text, string $question, int $budget): CondensedText => CondensedText::verbatim($text, $budget),
        );
    }

    public function testDeepenReadsHostDiverseTopResultsAndAttachesPageContent(): void
    {
        $results = [
            'query' => 'UAE 40 billion Germany sectors',
            'results' => [
                ['title' => 'Handelsblatt A', 'url' => 'https://www.handelsblatt.com/a', 'description' => 'snippet a'],
                ['title' => 'Handelsblatt B', 'url' => 'https://www.handelsblatt.com/b', 'description' => 'snippet b'],
                ['title' => 'LinkedIn post', 'url' => 'https://www.linkedin.com/posts/x', 'description' => 'snippet li'],
                ['title' => 'Report PDF', 'url' => 'https://gov.example.de/report.pdf', 'description' => 'snippet pdf'],
                ['title' => 'Reuters', 'url' => 'https://www.reuters.com/r', 'description' => 'snippet r'],
                ['title' => 'Tagesschau', 'url' => 'https://www.tagesschau.de/t', 'description' => 'snippet t'],
                ['title' => 'FAZ', 'url' => 'https://www.faz.net/f', 'description' => 'snippet f'],
                ['title' => 'Spiegel', 'url' => 'https://www.spiegel.de/s', 'description' => 'snippet s'],
            ],
        ];

        $fetched = [];
        $this->urlContent->method('fetchForReading')->willReturnCallback(function (string $url) use (&$fetched): UrlContentResult {
            $fetched[] = $url;

            return new UrlContentResult($url, str_repeat('Article body from '.$url.'. ', 40), 'Title '.$url, (string) parse_url($url, PHP_URL_HOST), true, null, $url.'?final');
        });

        $progress = [];
        $progressMeta = [];
        $out = $this->service()->deepen($results, 'Which sectors get the money?', 7, static function (string $status, string $message, array $meta) use (&$progress, &$progressMeta): void {
            $progress[] = $status;
            $progressMeta[] = $meta;
        });

        self::assertSame([
            'https://www.handelsblatt.com/a',
            'https://www.reuters.com/r',
            'https://www.tagesschau.de/t',
            'https://www.faz.net/f',
        ], $fetched, 'one page per host, LinkedIn and PDFs skipped, rank order kept');

        self::assertSame(4, $out['pages_read']);
        self::assertSame(4, $out['pages_attempted']);
        self::assertCount(8, $out['results'], 'result list and citation numbers are unchanged');

        self::assertTrue($out['results'][0]['fetched']);
        self::assertStringContainsString('Article body from https://www.handelsblatt.com/a', $out['results'][0]['page_content']);
        self::assertSame('https://www.handelsblatt.com/a?final', $out['results'][0]['final_url']);
        self::assertArrayNotHasKey('page_content', $out['results'][1], 'second page of the same host is not read');
        self::assertArrayNotHasKey('page_content', $out['results'][2], 'LinkedIn is skipped');
        self::assertArrayNotHasKey('page_content', $out['results'][3], 'PDF is skipped');
        self::assertTrue($out['results'][6]['fetched'] ?? false, 'the fourth distinct host is the last one read');
        self::assertArrayNotHasKey('fetched', $out['results'][7], 'beyond the page cap nothing is attempted');

        self::assertSame('reading_pages', $progress[0]);
        self::assertContains('reading_pages', $progress);

        // The progress line names the sites so the user sees what is being read.
        self::assertSame(['handelsblatt.com', 'reuters.com', 'tagesschau.de', 'faz.net'], $progressMeta[0]['hosts']);
        self::assertSame('fetching', $progressMeta[0]['stage']);
        self::assertSame('handelsblatt.com', $progressMeta[1]['current_host']);
        $last = end($progressMeta);
        self::assertSame(4, $last['pages_read']);
        self::assertSame('faz.net', $last['current_host']);
    }

    public function testDeepenCondensesEachPageToItsShareOfTheBudgetWithTheQuestionAsLens(): void
    {
        $results = ['query' => 'q', 'results' => [
            ['title' => 'A', 'url' => 'https://alpha.example/', 'description' => ''],
            ['title' => 'B', 'url' => 'https://beta.example/', 'description' => ''],
        ]];
        $this->urlContent->method('fetchForReading')->willReturnCallback(
            static fn (string $url): UrlContentResult => new UrlContentResult($url, str_repeat('long text ', 5000), 'T', 'h', true),
        );

        $condenser = $this->createMock(ContextCondenser::class);
        $calls = [];
        $condenser->method('fit')->willReturnCallback(function (string $text, string $question, int $budget, ?int $userId) use (&$calls): CondensedText {
            $calls[] = [$question, $budget, $userId];

            return new CondensedText('condensed: '.mb_substr($text, 0, 20), 'condensed', mb_strlen($text), $budget, 1, [1], false, 1);
        });

        $service = new WebResearchService($this->urlContent, $condenser, $this->plugConfig, new NullLogger());
        $out = $service->deepen($results, 'What is the figure?', 42, null, 2, 10000);

        self::assertSame([['What is the figure?', 5000, 42], ['What is the figure?', 5000, 42]], $calls, 'budget split evenly, question and user passed through');
        self::assertSame('condensed: long text long text ', $out['results'][0]['page_content']);
        self::assertSame('condensed', $out['results'][0]['page_content_strategy']);
    }

    public function testDeepenRecordsBlockedPagesWithoutBreakingTheResults(): void
    {
        $results = ['query' => 'q', 'results' => [
            ['title' => 'Walled', 'url' => 'https://walled.example/', 'description' => 'teaser'],
            ['title' => 'Open', 'url' => 'https://open.example/', 'description' => ''],
        ]];
        $this->urlContent->method('fetchForReading')->willReturnCallback(
            static fn (string $url): UrlContentResult => str_contains($url, 'walled')
                ? new UrlContentResult($url, '', '', 'walled.example', false, 'HTTP 403', $url, 'bot_blocked')
                : new UrlContentResult($url, str_repeat('Open article. ', 50), 'Open', 'open.example', true, null, $url),
        );

        $out = $this->service()->deepen($results, 'q', null);

        self::assertFalse($out['results'][0]['fetched']);
        self::assertSame('bot_blocked', $out['results'][0]['blocked_reason']);
        self::assertArrayNotHasKey('page_content', $out['results'][0]);
        self::assertTrue($out['results'][1]['fetched']);
        self::assertSame(1, $out['pages_read']);
        self::assertSame(2, $out['pages_attempted']);
    }

    public function testDeepenIsANoOpWhenDisabledOrWithoutResults(): void
    {
        $this->urlContent->expects(self::never())->method('fetchForReading');

        $service = $this->service();
        self::assertSame(['query' => 'q', 'results' => []], $service->deepen(['query' => 'q', 'results' => []], 'q', null));

        $results = ['query' => 'q', 'results' => [['title' => 'A', 'url' => 'https://a.example.com/']]];
        self::assertSame($results, $service->deepen($results, 'q', null, null, 0));
    }

    public function testReadMentionedUrlsBuildsAnHonestPromptForReadAndBlockedLinks(): void
    {
        $this->urlContent->method('fetchForReading')->willReturnCallback(
            static fn (string $url): UrlContentResult => match ($url) {
                'https://lnkd.in/p/abc' => new UrlContentResult($url, str_repeat('Hydrogen hub article text. ', 30), 'Hydrogen hubs', 'lnkd.in', true, null, 'https://www.example.com/hydrogen'),
                'https://www.linkedin.com/posts/private' => new UrlContentResult($url, 'A public teaser.', 'Sign in', 'www.linkedin.com', false, 'Page requires a login', $url, 'login_wall'),
                default => self::fail('unexpected url '.$url),
            },
        );

        $progress = [];
        $read = $this->service()->readMentionedUrls(
            ['https://lnkd.in/p/abc', 'https://www.linkedin.com/posts/private', 'https://lnkd.in/p/abc'],
            'Summarize this',
            5,
            static function (string $status, string $message, array $meta) use (&$progress): void {
                $progress[$status] = $meta;
            },
        );

        self::assertSame(1, $read->successCount());
        self::assertCount(2, $read->pages, 'duplicates are read once');
        self::assertStringContainsString('Hydrogen hubs', (string) $read->contextForQuery());

        $prompt = $this->service()->formatMentionedUrlsForPrompt($read);
        self::assertStringContainsString('## Linked Pages', $prompt);
        self::assertStringContainsString('--- URL: https://lnkd.in/p/abc ---', $prompt);
        self::assertStringContainsString('Resolved to: https://www.example.com/hydrogen', $prompt);
        self::assertStringContainsString('Hydrogen hub article text.', $prompt);
        self::assertStringContainsString('--- URL: https://www.linkedin.com/posts/private ---', $prompt);
        self::assertStringContainsString('Status: NOT READ. The page requires a login', $prompt);
        self::assertStringContainsString("Public preview:\nA public teaser.", $prompt);

        self::assertSame(2, $progress['fetching_urls']['urls_total']);
        self::assertSame(1, $progress['urls_fetched']['urls_read']);
        self::assertSame('login_wall', $progress['urls_fetched']['pages'][1]['blocked_reason']);
        self::assertTrue($progress['urls_fetched']['pages'][0]['fetched']);
    }

    public function testReadMentionedUrlsRespectsTheConfiguredLimit(): void
    {
        $fetched = 0;
        $this->urlContent->method('fetchForReading')->willReturnCallback(function (string $url) use (&$fetched): UrlContentResult {
            ++$fetched;

            return new UrlContentResult($url, str_repeat('text ', 100), 'T', 'h', true);
        });

        $read = $this->service()->readMentionedUrls(['https://a.example/', 'https://b.example/', 'https://c.example/', 'https://d.example/'], 'q', null);

        self::assertSame(3, $fetched);
        self::assertCount(3, $read->pages);
    }

    private function service(): WebResearchService
    {
        return new WebResearchService($this->urlContent, $this->condenser, $this->plugConfig, new NullLogger());
    }
}
