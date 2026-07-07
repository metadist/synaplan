<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Service\Message\ConversationSummaryConfigService;
use App\Service\Message\ConversationSummaryService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for the rolling, tiered, condensing conversation summary.
 */
class ConversationSummaryServiceTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->modelConfigService->method('getSummaryModelConfig')->willReturn([
            'provider' => 'groq',
            'model' => 'gpt-oss-120b',
            'model_id' => 300,
        ]);
    }

    /**
     * @param array<string, string> $configOverrides
     */
    private function makeService(array $configOverrides = []): ConversationSummaryService
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => $configOverrides[$setting] ?? null,
        );
        $config = new ConversationSummaryConfigService($repo);

        return new ConversationSummaryService(
            $this->aiFacade,
            $this->modelConfigService,
            $config,
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    private function makeMessage(int $id, string $direction, string $text): Message&MockObject
    {
        $msg = $this->createMock(Message::class);
        $msg->method('getId')->willReturn($id);
        $msg->method('getDirection')->willReturn($direction);
        $msg->method('getText')->willReturn($text);
        $msg->method('getFileText')->willReturn(null);
        $msg->method('getFileType')->willReturn(null);

        return $msg;
    }

    /**
     * @return list<Message>
     */
    private function makeLongHistory(): array
    {
        $history = [];
        for ($i = 1; $i <= 12; ++$i) {
            $direction = 0 === $i % 2 ? 'OUT' : 'IN';
            // ~300 chars each so the older span forms once recent budget fills.
            $history[] = $this->makeMessage($i, $direction, "message-{$i} ".str_repeat('x', 290));
        }

        return $history;
    }

    public function testDisabledReturnsNotApplied(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        $service = $this->makeService(['ENABLED' => '0']);
        $history = $this->makeLongHistory();

        $result = $service->buildRollingContext($history, 7, 100);

        self::assertFalse($result->applied);
        self::assertNull($result->summary);
        self::assertSame($history, $result->recentMessages);
    }

    public function testNullChatIdReturnsNotApplied(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        $service = $this->makeService();
        $history = $this->makeLongHistory();

        $result = $service->buildRollingContext($history, 7, null);

        self::assertFalse($result->applied);
    }

    public function testShortHistoryThatFitsIsNotSummarized(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        // Default recent budget is 8000 chars; a couple of tiny turns fit easily.
        $service = $this->makeService();
        $history = [
            $this->makeMessage(1, 'IN', 'hi'),
            $this->makeMessage(2, 'OUT', 'hello there'),
        ];

        $result = $service->buildRollingContext($history, 7, 100);

        self::assertFalse($result->applied);
        self::assertSame($history, $result->recentMessages);
    }

    public function testLongHistoryIsCondensedWithGradientAndRecentKeptVerbatim(): void
    {
        $captured = null;
        $capturedOptions = null;
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturnCallback(function (array $messages, ?int $userId, array $options) use (&$captured, &$capturedOptions) {
                $captured = $messages;
                $capturedOptions = $options;

                return ['content' => 'CONDENSED ROLLING SUMMARY', 'provider' => 'groq', 'model' => 'gpt-oss-120b'];
            });

        // Force a small verbatim window so an older span forms (min clamp 1000).
        $service = $this->makeService(['RECENT_VERBATIM_CHARS' => '1000']);
        $history = $this->makeLongHistory();

        $result = $service->buildRollingContext($history, 7, 100);

        self::assertTrue($result->applied);
        self::assertSame('CONDENSED ROLLING SUMMARY', $result->summary);

        // Recent messages are the newest ones and a strict tail of the history.
        self::assertNotEmpty($result->recentMessages);
        self::assertLessThan(count($history), count($result->recentMessages));
        $expectedTail = array_slice($history, -count($result->recentMessages));
        self::assertSame($expectedTail, $result->recentMessages);
        self::assertGreaterThan(0, $result->summarizedCount);

        // The summarizer got the configured model + gradient instructions.
        self::assertNotNull($capturedOptions);
        self::assertSame('groq', $capturedOptions['provider'] ?? null);
        self::assertSame('gpt-oss-120b', $capturedOptions['model'] ?? null);
        self::assertNotNull($captured);
        $systemPrompt = $captured[0]['content'] ?? '';
        $sourceText = $captured[1]['content'] ?? '';
        self::assertStringContainsStringIgnoringCase('gradient', $systemPrompt);
        self::assertStringContainsString('Segment 1', $sourceText);
        self::assertStringContainsString('oldest', $sourceText);
        self::assertStringContainsString('message-1', $sourceText);

        // The newest (verbatim) turn must NOT be fed to the summarizer.
        $newest = $history[array_key_last($history)];
        self::assertContains($newest, $result->recentMessages);
        self::assertStringNotContainsString('message-12', $sourceText);
    }

    public function testSummarizerFailureFallsBackToFullHistory(): void
    {
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willThrowException(new \RuntimeException('provider down'));

        $service = $this->makeService(['RECENT_VERBATIM_CHARS' => '1000']);
        $history = $this->makeLongHistory();

        $result = $service->buildRollingContext($history, 7, 100);

        self::assertFalse($result->applied);
        self::assertNull($result->summary);
        self::assertSame($history, $result->recentMessages);
    }

    public function testStableOlderSpanIsCachedAcrossCalls(): void
    {
        // AI must be hit only once even though we summarize the same span twice.
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturn(['content' => 'CACHED SUMMARY', 'provider' => 'groq', 'model' => 'gpt-oss-120b']);

        $service = $this->makeService(['RECENT_VERBATIM_CHARS' => '1000']);
        $history = $this->makeLongHistory();

        $first = $service->buildRollingContext($history, 7, 100);
        $second = $service->buildRollingContext($history, 7, 100);

        self::assertTrue($first->applied);
        self::assertTrue($second->applied);
        self::assertSame('CACHED SUMMARY', $second->summary);
    }
}
