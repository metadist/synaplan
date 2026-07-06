<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\UrlFetchRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Security\SsrfGuard;
use App\Service\UrlContentService;
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
    private function runner(array $responses = [], bool $flagEnabled = true): UrlFetchRunner
    {
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
