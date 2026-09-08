<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Entity\UrlWatch;
use App\Repository\ConfigRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\UrlFetchRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Security\SsrfGuard;
use App\Service\UrlContentService;
use App\Service\UrlWatch\UrlWatchCompareResult;
use App\Service\UrlWatch\UrlWatchService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `url_fetch` data-node contract (release-4.0 plan 09 §3.1): planner-placed
 * fetch of a specific URL, flag-gated, SSRF-guarded, isolated failure, and
 * reuse of the step-2.7 pre-fetch.
 */
final class UrlFetchRunnerTest extends TestCase
{
    /**
     * @param list<MockResponse> $responses
     */
    private function runner(
        array $responses = [],
        bool $flagEnabled = true,
        ?UrlWatchService $watches = null,
    ): UrlFetchRunner {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'MULTITASK' === $group && 'URL_FETCH_ENABLED' === $setting
                ? ($flagEnabled ? '1' : '0')
                : null,
        );

        return new UrlFetchRunner(
            new UrlContentService(new MockHttpClient($responses), new SsrfGuard(), new NullLogger()),
            new MultitaskRoutingConfig($configRepo),
            new NullLogger(),
            $watches,
        );
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function context(string $messageText = '', array $classification = []): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn($messageText);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getFiles')->willReturn(new ArrayCollection([]));
        $message->method('getFileText')->willReturn('');

        return new NodeContext($message, [], 7, $classification);
    }

    private function node(array $inputs = []): TaskNode
    {
        return new TaskNode('n1', Capability::UrlFetch, [], $inputs);
    }

    public function testDisabledFlagFailsTheNodeWithoutFetching(): void
    {
        $runner = $this->runner([], flagEnabled: false);

        $result = $runner->run($this->node(['urls' => 'https://example.com/a']), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('disabled', (string) $result->error);
    }

    public function testFetchesUrlFromInputsAndReturnsFormattedContent(): void
    {
        $html = '<html><head><title>Example Article</title></head><body><p>The quick brown fox article body.</p></body></html>';
        $runner = $this->runner([
            new MockResponse('', ['http_code' => 404]), // robots.txt
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        $result = $runner->run(
            $this->node(['urls' => 'https://example.com/article']),
            $this->context('Fasse mir diese Seite zusammen: https://example.com/article'),
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('https://example.com/article', (string) $result->text);
        self::assertStringContainsString('quick brown fox', (string) $result->text);
        self::assertSame(['https://example.com/article'], $result->metadata['urls']);
        self::assertSame('example.com', $result->metadata['query']);
    }

    public function testFallsBackToUrlsInTheMessageText(): void
    {
        $html = '<html><head><title>T</title></head><body>fallback body content here</body></html>';
        $runner = $this->runner([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        $result = $runner->run(
            $this->node(),
            $this->context('what does https://example.org/page say?'),
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame(['https://example.org/page'], $result->metadata['urls']);
    }

    public function testNoUrlAnywhereFailsGracefully(): void
    {
        $result = $this->runner()->run($this->node(), $this->context('no links here'));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no URL', (string) $result->error);
    }

    public function testPrivateTargetIsBlockedBySsrfGuard(): void
    {
        $result = $this->runner()->run(
            $this->node(['urls' => 'http://127.0.0.1/admin']),
            $this->context(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('could not read the page', (string) $result->error);
    }

    public function testReusesStepTwoSevenPreFetchForWholeMessageUrls(): void
    {
        // No HTTP responses queued: a real fetch attempt would throw.
        $runner = $this->runner([]);

        $result = $runner->run(
            $this->node(),
            $this->context(
                'read https://example.com/doc please',
                ['url_content' => "## URL Content\npre-fetched block"],
            ),
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('pre-fetched block', (string) $result->text);
        self::assertTrue((bool) $result->metadata['reused_prefetched']);
    }

    public function testCompareInputSavesSnapshotAndReturnsDiffText(): void
    {
        $html = '<html><head><title>News</title></head><body><p>Today headline</p></body></html>';
        $watch = new UrlWatch(7, 'https://example.com/news', 'hash');
        $remembered = new UrlWatchCompareResult(
            UrlWatchCompareResult::CHANGED,
            $watch,
            '- yesterday',
            "# URL watch: https://example.com/news\nStatus: changed\n\n## Differences\n```diff\n- yesterday\n+ Today headline\n```",
        );
        $watches = $this->createMock(UrlWatchService::class);
        $watches->expects(self::once())->method('remember')->willReturn($remembered);

        $runner = $this->runner([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ], watches: $watches);

        $result = $runner->run(
            $this->node(['urls' => 'https://example.com/news', 'compare' => true]),
            $this->context('summarize https://example.com/news'),
        );

        self::assertTrue($result->isSuccessful());
        self::assertTrue((bool) $result->metadata['url_watch_compare']);
        self::assertSame(UrlWatchCompareResult::CHANGED, $result->metadata['compare_status']);
        self::assertStringContainsString('Status: changed', (string) $result->text);
        self::assertStringNotContainsString('## URL Content', (string) $result->text);
    }

    public function testCompareHeuristicDoesNotReusePrefetch(): void
    {
        $html = '<html><head><title>T</title></head><body>fresh compare body</body></html>';
        $watch = new UrlWatch(7, 'https://example.com/doc', 'hash');
        $watches = $this->createMock(UrlWatchService::class);
        $watches->expects(self::once())->method('remember')->willReturn(
            new UrlWatchCompareResult(
                UrlWatchCompareResult::FIRST,
                $watch,
                '',
                "# URL watch: https://example.com/doc\nStatus: first_save\n\n## Saved page\nfresh compare body",
            ),
        );

        $runner = $this->runner([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ], watches: $watches);

        $result = $runner->run(
            $this->node(),
            $this->context(
                'get https://example.com/doc and compare it to a previously saved version',
                ['url_content' => "## URL Content\npre-fetched block"],
            ),
        );

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('first_save', (string) $result->text);
        self::assertArrayNotHasKey('reused_prefetched', $result->metadata);
    }

    public function testOrdinarySummarizeDoesNotCompare(): void
    {
        $html = '<html><head><title>T</title></head><body>plain body</body></html>';
        $watches = $this->createMock(UrlWatchService::class);
        $watches->expects(self::never())->method('remember');

        $runner = $this->runner([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ], watches: $watches);

        $result = $runner->run(
            $this->node(['urls' => 'https://example.com/article']),
            $this->context('summarize this article: https://example.com/article'),
        );

        self::assertTrue($result->isSuccessful());
        self::assertArrayNotHasKey('url_watch_compare', $result->metadata);
        self::assertStringContainsString('plain body', (string) $result->text);
    }

    public function testExtractsUrlFromMarkdownLinkInPlannerInput(): void
    {
        $html = '<html><head><title>News</title></head><body>markdown fetched body</body></html>';
        $runner = $this->runner([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse($html, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        $result = $runner->run(
            $this->node(['urls' => '[the article](https://example.com/md)']),
            $this->context('summarize [the article](https://example.com/md)'),
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame(['https://example.com/md'], $result->metadata['urls']);
        self::assertStringContainsString('markdown fetched body', (string) $result->text);
    }

    public function testHttpErrorFailsTheNodeInIsolation(): void
    {
        $runner = $this->runner([
            new MockResponse('', ['http_code' => 404]), // robots.txt
            new MockResponse('', ['http_code' => 500]),
        ]);

        $result = $runner->run(
            $this->node(['urls' => 'https://example.com/broken']),
            $this->context(),
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('could not read the page', (string) $result->error);
    }
}
