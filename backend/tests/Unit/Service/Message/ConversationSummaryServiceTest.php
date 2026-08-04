<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Repository\MessageRepository;
use App\Service\Message\ConversationSummaryConfigService;
use App\Service\Message\ConversationSummaryService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for the async, incremental rolling conversation summary.
 *
 * Hot path ({@see ConversationSummaryService::buildRollingContext()}) must
 * never call the AI. The worker path ({@see ConversationSummaryService::refresh()})
 * owns every summarizer round-trip.
 */
class ConversationSummaryServiceTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private MessageRepository&MockObject $messageRepository;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->cache = new ArrayAdapter();
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

        return new ConversationSummaryService(
            $this->aiFacade,
            $this->modelConfigService,
            new ConversationSummaryConfigService($repo),
            $this->messageRepository,
            $this->cache,
            new NullLogger(),
        );
    }

    private function makeMessage(int $id, string $direction, string $text): Message&MockObject
    {
        $msg = $this->createMock(Message::class);
        $msg->method('getId')->willReturn($id);
        $msg->method('getDirection')->willReturn($direction);
        $msg->method('getText')->willReturn($text);
        $msg->method('getFileText')->willReturn('');
        $msg->method('getFileType')->willReturn('');
        $msg->method('getUnixTimestamp')->willReturn(1_700_000_000 + $id);

        return $msg;
    }

    /**
     * @return list<Message>
     */
    private function makeShortWindow(int $firstId, int $count): array
    {
        $window = [];
        for ($i = $firstId; $i < $firstId + $count; ++$i) {
            $window[] = $this->makeMessage($i, 0 === $i % 2 ? 'OUT' : 'IN', "message-{$i} ".str_repeat('x', 50));
        }

        return $window;
    }

    /**
     * @return list<Message>
     */
    private function makeChat(int $count): array
    {
        return $this->makeShortWindow(1, $count);
    }

    public function testDisabledReturnsNotAppliedAndNeverCallsAi(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        $result = $this->makeService(['ENABLED' => '0'])->buildRollingContext(
            $this->makeChat(40),
            40,
            7,
            100,
        );

        self::assertFalse($result->applied);
    }

    public function testHotPathNeverCallsAiEvenOnAColdStore(): void
    {
        // Cold start: older span exists but nothing is stored yet. The hot path
        // must answer without a summary rather than block on the summarizer.
        $chat = $this->makeChat(40);
        $window = array_slice($chat, -15);

        $this->messageRepository->method('findIdBefore')->willReturn(25);
        $this->aiFacade->expects($this->never())->method('chat');

        $result = $this->makeService()->buildRollingContext($window, count($chat), 7, 100);

        self::assertFalse($result->applied);
        self::assertSame($window, $result->recentMessages);
    }

    public function testHotPathReadsTheStoredSummaryWithoutCallingAi(): void
    {
        $chat = $this->makeChat(40);
        $window = array_slice($chat, -15);

        $this->messageRepository->method('findIdBefore')->willReturn(25);
        $this->aiFacade->expects($this->never())->method('chat');

        $service = $this->makeService();
        // Seed the store the way the worker would after a previous refresh.
        self::assertTrue($this->seedStoreViaRefresh($service, $chat, 'STORED SUMMARY'));

        $result = $service->buildRollingContext($window, count($chat), 7, 100);

        self::assertTrue($result->applied);
        self::assertSame('STORED SUMMARY', $result->summary);
        self::assertSame($window, $result->recentMessages);
    }

    public function testHotPathAppendsRawGapWhenStoreIsOneTurnBehind(): void
    {
        $chat = $this->makeChat(40);
        $window = array_slice($chat, -15); // ids 26..40, olderLastId = 25

        $this->messageRepository->method('findIdBefore')->willReturn(25);
        // Store covers only up to 23 — messages 24 and 25 are the gap.
        $gap = [$chat[23], $chat[24]]; // ids 24, 25
        $this->messageRepository->expects($this->once())
            ->method('findMessagesBetween')
            ->with(7, 100, 23, 25)
            ->willReturn($gap);
        $this->aiFacade->expects($this->never())->method('chat');

        $service = $this->makeService();
        $this->seedStoreViaRefresh($service, array_slice($chat, 0, 23), 'PREVIOUS', upToOverride: 23);

        $result = $service->buildRollingContext($window, count($chat), 7, 100);

        self::assertTrue($result->applied);
        self::assertIsString($result->summary);
        self::assertStringContainsString('PREVIOUS', $result->summary);
        self::assertStringContainsString('not yet condensed', $result->summary);
        self::assertStringContainsString('message-24', $result->summary);
        self::assertStringContainsString('message-25', $result->summary);
    }

    public function testRefreshBootstrapsFromScratch(): void
    {
        $chat = $this->makeChat(40);
        $this->messageRepository->method('findAllByChatId')->willReturn($chat);

        $captured = null;
        $this->aiFacade->expects($this->once())->method('chat')->willReturnCallback(
            function (array $messages) use (&$captured): array {
                $captured = $messages;

                return ['content' => 'BOOTSTRAP SUMMARY', 'provider' => 'groq', 'model' => 'gpt-oss-120b'];
            },
        );

        $wrote = $this->makeService()->refresh(100, 7);

        self::assertTrue($wrote);
        self::assertNotNull($captured);
        $system = $captured[0]['content'] ?? '';
        self::assertStringContainsString('Already covered', $system);
        self::assertStringContainsString('Open questions', $system);
        self::assertStringContainsString('gradient', strtolower($system));
    }

    public function testRefreshIncrementalFoldsOnlyNewMessages(): void
    {
        $chat = $this->makeChat(40);
        $this->messageRepository->method('findAllByChatId')->willReturn($chat);

        $service = $this->makeService();
        // Seed a store that covers up to message 23 — two messages short of
        // the current olderLastId (25 for a 15-msg verbatim window of short
        // turns).
        $this->seedStoreViaRefresh($service, array_slice($chat, 0, 23), 'PREVIOUS SUMMARY', upToOverride: 23);

        $captured = null;
        $this->aiFacade->expects($this->once())->method('chat')->willReturnCallback(
            function (array $messages) use (&$captured): array {
                $captured = $messages;

                return ['content' => 'FOLDED SUMMARY', 'provider' => 'groq', 'model' => 'gpt-oss-120b'];
            },
        );

        self::assertTrue($service->refresh(100, 7));
        self::assertNotNull($captured);
        $system = $captured[0]['content'] ?? '';
        $user = $captured[1]['content'] ?? '';
        self::assertStringContainsString('PREVIOUS rolling summary', $system);
        self::assertStringContainsString('PREVIOUS SUMMARY', $user);
        self::assertStringContainsString('Newly aged-out', $user);
        // Only the gap (24, 25), not the whole older span.
        self::assertStringContainsString('message-24', $user);
        self::assertStringContainsString('message-25', $user);
        self::assertStringNotContainsString('message-1 ', $user);
    }

    public function testRefreshNoOpsWhenStoreAlreadyCoversTheOlderSpan(): void
    {
        $chat = $this->makeChat(40);
        $this->messageRepository->method('findAllByChatId')->willReturn($chat);

        $service = $this->makeService();
        $this->aiFacade->expects($this->once())->method('chat')->willReturn(
            ['content' => 'FRESH', 'provider' => 'groq', 'model' => 'gpt-oss-120b'],
        );
        self::assertTrue($service->refresh(100, 7));

        // Second call: store is current → no AI call.
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->aiFacade->expects($this->never())->method('chat');
        $service = new ConversationSummaryService(
            $this->aiFacade,
            $this->modelConfigService,
            new ConversationSummaryConfigService($this->createStub(ConfigRepository::class)),
            $this->messageRepository,
            $this->cache,
            new NullLogger(),
        );

        self::assertFalse($service->refresh(100, 7));
    }

    public function testVerbatimTailNeverGrowsBeyondTheCallersWindow(): void
    {
        $chat = $this->makeChat(40);
        $window = array_slice($chat, -15);

        $this->messageRepository->method('findIdBefore')->willReturn(25);
        $this->aiFacade->expects($this->never())->method('chat');

        $service = $this->makeService();
        $this->seedStoreViaRefresh($service, $chat, 'STORED');

        $result = $service->buildRollingContext($window, count($chat), 7, 100);

        self::assertTrue($result->applied);
        self::assertSame($window, $result->recentMessages);
    }

    /**
     * Seed the Redis-shaped store. When {@see $upToOverride} is set the entry
     * is written directly (avoids the verbatim-budget no-op on short histories);
     * otherwise a real {@see ConversationSummaryService::refresh()} runs so the
     * store key / fingerprint stay in lock-step with production.
     *
     * @param list<Message> $historyForRefresh
     */
    private function seedStoreViaRefresh(
        ConversationSummaryService $service,
        array $historyForRefresh,
        string $summaryText,
        ?int $upToOverride = null,
    ): bool {
        if (null !== $upToOverride) {
            $this->writeStoreDirectly($summaryText, $upToOverride, $upToOverride);

            return true;
        }

        $repo = $this->createMock(MessageRepository::class);
        $repo->method('findAllByChatId')->willReturn($historyForRefresh);

        $ai = $this->createMock(AiFacade::class);
        $ai->method('chat')->willReturn([
            'content' => $summaryText,
            'provider' => 'groq',
            'model' => 'gpt-oss-120b',
        ]);

        $seeder = new ConversationSummaryService(
            $ai,
            $this->modelConfigService,
            new ConversationSummaryConfigService($this->createStub(ConfigRepository::class)),
            $repo,
            $this->cache,
            new NullLogger(),
        );

        return $seeder->refresh(100, 7);
    }

    private function writeStoreDirectly(string $summary, int $upToMessageId, int $summarizedCount): void
    {
        // Mirror ConversationSummaryService::storeKey fingerprint defaults.
        $fingerprint = md5(implode(':', [4000, 3, 8000, 'v2-async']));
        $key = sprintf('conv_summary.chat.%d.%s', 100, $fingerprint);
        $item = $this->cache->getItem($key);
        $item->set([
            'summary' => $summary,
            'upToMessageId' => $upToMessageId,
            'summarizedCount' => $summarizedCount,
        ]);
        $item->expiresAfter(3600);
        $this->cache->save($item);
    }
}
