<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Chat\Run;

use App\Service\Chat\Run\ChatRun;
use App\Service\Chat\Run\ChatRunBuffer;
use App\Service\Chat\Run\ChatRunService;
use App\Service\Infrastructure\RedisService;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Resumable turns live entirely in Redis (sorted sets, sets, TTLs), so they can
 * only be verified against a real instance — a fake would test the fake. Skips
 * gracefully when Redis is unreachable, like {@see \App\Tests\Integration\Service\Infrastructure\RedisIntegrationTest}.
 */
final class ChatRunBufferTest extends KernelTestCase
{
    private RedisService $redis;
    private ChatRunBuffer $buffer;
    private ChatRunService $service;

    /** @var list<ChatRun> runs created by the test, dropped in tearDown */
    private array $runs = [];

    private string $ownerKey;
    private int $chatId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->redis = static::getContainer()->get(RedisService::class);

        if (!$this->redis->isAvailable()) {
            self::markTestSkipped('Redis is not reachable — skipping resumable-run integration test.');
        }

        // Wired by hand rather than pulled from the container: both are private,
        // single-consumer services and would be inlined away before the test
        // container ever sees them.
        $this->buffer = new ChatRunBuffer($this->redis);
        $this->service = new ChatRunService($this->buffer, new NullLogger());

        // Unique per run so parallel/repeated runs never collide on the shared
        // chat pointer and owner index keys.
        $this->ownerKey = ChatRunService::ownerKeyForUser(random_int(100000, 999999));
        $this->chatId = random_int(100000, 999999);
    }

    protected function tearDown(): void
    {
        foreach ($this->runs as $run) {
            $this->buffer->forget($run);
        }

        parent::tearDown();
    }

    public function testEventsReplayInOrderAndFromCanSkipWhatTheClientAlreadySaw(): void
    {
        $run = $this->newRun();
        $this->buffer->append($run->getRunId(), 1, '{"status":"data","chunk":"Hel"}');
        $this->buffer->append($run->getRunId(), 2, '{"status":"data","chunk":"lo"}');
        $this->buffer->append($run->getRunId(), 3, '{"status":"complete"}');

        $all = $this->buffer->readEvents($run->getRunId());
        self::assertSame([1, 2, 3], array_column($all, 'seq'));
        self::assertSame('{"status":"complete"}', $all[2]['payload']);

        // `from` is exclusive: a client that saw seq 2 must not get it twice.
        $tail = $this->buffer->readEvents($run->getRunId(), 2);
        self::assertSame([3], array_column($tail, 'seq'));
    }

    public function testIdenticalTextChunksAreKeptApart(): void
    {
        // A sorted set deduplicates equal members — without the seq prefix two
        // identical tokens would collapse and the replayed answer would silently
        // lose a word.
        $run = $this->newRun();
        $this->buffer->append($run->getRunId(), 1, '{"status":"data","chunk":"the"}');
        $this->buffer->append($run->getRunId(), 2, '{"status":"data","chunk":"the"}');

        self::assertCount(2, $this->buffer->readEvents($run->getRunId()));
    }

    public function testRecorderBundlesConsecutiveTextAndKeepsOtherEventsVerbatim(): void
    {
        $recorder = $this->service->begin($this->ownerKey, $this->chatId, 'track-bundle');
        self::assertNotNull($recorder);
        $this->trackRun($recorder->getRunId());

        foreach (['He', 'llo', ' wor', 'ld'] as $chunk) {
            $recorder->record(['status' => 'data', 'chunk' => $chunk]);
        }
        $recorder->record(['status' => 'memories_loaded', 'memories' => []]);
        $recorder->finish(ChatRun::STATUS_COMPLETE, 4711);

        $events = array_map(
            static fn (array $event): array => (array) json_decode($event['payload'], true),
            $this->buffer->readEvents($recorder->getRunId()),
        );

        // Four tokens well under the flush thresholds must cost one entry, not
        // four — otherwise a long answer floods Redis with thousands of writes.
        self::assertSame(
            [
                ['status' => 'data', 'chunk' => 'Hello world'],
                ['status' => 'memories_loaded', 'memories' => []],
            ],
            $events,
        );

        $stored = $this->buffer->find($recorder->getRunId());
        self::assertNotNull($stored);
        self::assertSame(ChatRun::STATUS_COMPLETE, $stored->getStatus());
        self::assertSame(4711, $stored->getMessageId());
        self::assertSame(2, $stored->getLastSeq());
    }

    public function testATerminalRunIsNoLongerOfferedForResume(): void
    {
        $recorder = $this->service->begin($this->ownerKey, $this->chatId, 'track-terminal');
        self::assertNotNull($recorder);
        $this->trackRun($recorder->getRunId());

        $recorder->record(['status' => 'data', 'chunk' => 'partial']);
        self::assertNotNull($this->buffer->findActiveForChat($this->chatId));
        self::assertSame([$this->chatId], $this->buffer->findActiveChatIdsForOwner($this->ownerKey));

        $recorder->finish(ChatRun::STATUS_COMPLETE);

        self::assertNull($this->buffer->findActiveForChat($this->chatId));
        self::assertSame([], $this->buffer->findActiveChatIdsForOwner($this->ownerKey));
        self::assertNull($this->service->describeActiveForChat($this->chatId, $this->ownerKey));
    }

    public function testDescribeActiveForChatRebuildsTheTextProducedSoFar(): void
    {
        $recorder = $this->service->begin($this->ownerKey, $this->chatId, 'track-describe');
        self::assertNotNull($recorder);
        $this->trackRun($recorder->getRunId());

        $recorder->record(['status' => 'data', 'chunk' => 'Half an ']);
        $recorder->record(['status' => 'memories_loaded', 'memories' => []]);
        $recorder->record(['status' => 'data', 'chunk' => 'answer']);
        $recorder->record(['status' => 'task_update', 'task' => 'x']);

        $active = $this->service->describeActiveForChat($this->chatId, $this->ownerKey);

        self::assertNotNull($active);
        self::assertSame($recorder->getRunId(), $active['runId']);
        self::assertSame('track-describe', $active['trackId']);
        // Only text events rebuild the bubble; the rest merely advance lastSeq.
        self::assertSame('Half an answer', $active['partialText']);
        self::assertGreaterThan(0, $active['lastSeq']);
    }

    public function testAForeignOwnerCanNeitherAttachNorDiscoverTheRun(): void
    {
        $recorder = $this->service->begin($this->ownerKey, $this->chatId, 'track-owner');
        self::assertNotNull($recorder);
        $this->trackRun($recorder->getRunId());
        $recorder->record(['status' => 'data', 'chunk' => 'secret']);

        $stranger = ChatRunService::ownerKeyForUser(1);

        self::assertNull($this->service->authorize($recorder->getRunId(), $stranger));
        self::assertNull($this->service->describeActiveForChat($this->chatId, $stranger));
        self::assertNotNull($this->service->authorize($recorder->getRunId(), $this->ownerKey));
    }

    public function testAnUnknownRunIdIsNotAuthorized(): void
    {
        self::assertNull($this->service->authorize('does-not-exist', $this->ownerKey));
        self::assertNull($this->service->authorize('', $this->ownerKey));
    }

    public function testAnAbandonedRunGoesStaleSoNobodyWaitsForADeadWorker(): void
    {
        $run = $this->newRun();
        self::assertFalse($this->service->isStale($run));

        // Simulate a worker that died mid-turn: heartbeat frozen in the past,
        // status still "running".
        $frozen = $run->toArray();
        $frozen['updated'] = time() - (ChatRunService::STALE_AFTER_SECONDS + 5);
        $stale = ChatRun::fromArray($frozen);
        $this->buffer->save($stale);

        self::assertTrue($this->service->isStale($stale));
        self::assertNull($this->service->describeActiveForChat($this->chatId, $this->ownerKey));

        // A finished run is never stale — it has an outcome to deliver.
        $stale->markTerminal(ChatRun::STATUS_COMPLETE);
        self::assertFalse($this->service->isStale($stale));
    }

    public function testForgetLeavesNothingBehind(): void
    {
        $recorder = $this->service->begin($this->ownerKey, $this->chatId, 'track-forget');
        self::assertNotNull($recorder);
        $this->trackRun($recorder->getRunId());
        $recorder->record(['status' => 'data', 'chunk' => 'nevermind']);

        $run = $this->buffer->find($recorder->getRunId());
        self::assertNotNull($run);
        $this->buffer->forget($run);

        self::assertNull($this->buffer->find($recorder->getRunId()));
        self::assertSame([], $this->buffer->readEvents($recorder->getRunId()));
        self::assertNull($this->buffer->findActiveForChat($this->chatId));
        self::assertSame([], $this->buffer->findActiveChatIdsForOwner($this->ownerKey));
    }

    public function testTheOwnerIndexDropsChatsWhoseRunExpired(): void
    {
        $recorder = $this->service->begin($this->ownerKey, $this->chatId, 'track-index');
        self::assertNotNull($recorder);
        $run = $this->buffer->find($recorder->getRunId());
        self::assertNotNull($run);
        $this->runs[] = $run;

        // Mimic TTL expiry of the run snapshot and its chat pointer while the
        // owner index still lists the chat.
        $this->redis->delete('chatrun:'.$recorder->getRunId());

        self::assertSame([], $this->buffer->findActiveChatIdsForOwner($this->ownerKey));
    }

    private function newRun(): ChatRun
    {
        $run = new ChatRun('test-run-'.bin2hex(random_bytes(6)), $this->ownerKey, $this->chatId, 'track-'.bin2hex(random_bytes(3)));
        $this->buffer->save($run);
        $this->runs[] = $run;

        return $run;
    }

    private function trackRun(string $runId): void
    {
        $run = $this->buffer->find($runId);
        if (null !== $run) {
            $this->runs[] = $run;
        }
    }
}
