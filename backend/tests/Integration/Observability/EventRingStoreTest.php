<?php

declare(strict_types=1);

namespace App\Tests\Integration\Observability;

use App\Observability\EventRingStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the ring against the real Redis of the test environment.
 */
final class EventRingStoreTest extends KernelTestCase
{
    private EventRingStore $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $store = self::getContainer()->get(EventRingStore::class);
        self::assertInstanceOf(EventRingStore::class, $store);
        $this->store = $store;
        $this->store->clear();
    }

    protected function tearDown(): void
    {
        $this->store->clear();
        parent::tearDown();
    }

    public function testRecordAndReadBackNewestFirst(): void
    {
        self::assertTrue($this->store->record(['event' => 'first', 'level' => 'info', 'ts' => 1000]));
        self::assertTrue($this->store->record(['event' => 'second', 'level' => 'error', 'ts' => 2000]));

        $recent = $this->store->recent();

        self::assertCount(2, $recent);
        self::assertSame('second', $recent[0]['event']);
        self::assertSame('first', $recent[1]['event']);
    }

    public function testFreeTextIsScrubbedOnRead(): void
    {
        $this->store->record([
            'event' => 'http_500',
            'level' => 'error',
            'exception_message' => 'User admin@synaplan.com not found',
            'ts' => 3000,
        ]);

        $recent = $this->store->recent(level: 'error');

        self::assertCount(1, $recent);
        self::assertStringNotContainsString('admin@synaplan.com', (string) $recent[0]['exception_message']);
        self::assertStringContainsString('[email]', (string) $recent[0]['exception_message']);
    }

    public function testHostRoundTrips(): void
    {
        $this->store->record(['event' => 'boom', 'level' => 'error', 'host' => 'web2', 'ts' => 4000]);

        $recent = $this->store->recent(level: 'error');

        self::assertCount(1, $recent);
        self::assertSame('web2', $recent[0]['host']);
    }

    public function testFilterByLevelAndRequestId(): void
    {
        $this->store->record(['event' => 'a', 'level' => 'info', 'request_id' => 'req-1', 'ts' => 10]);
        $this->store->record(['event' => 'b', 'level' => 'error', 'request_id' => 'req-2', 'ts' => 20]);
        $this->store->record(['event' => 'c', 'level' => 'error', 'request_id' => 'req-1', 'ts' => 30]);

        self::assertCount(2, $this->store->recent(level: 'error'));
        self::assertCount(2, $this->store->recent(requestId: 'req-1'));

        $both = $this->store->recent(level: 'error', requestId: 'req-1');
        self::assertCount(1, $both);
        self::assertSame('c', $both[0]['event']);
    }

    public function testQueryMatchesAcrossFields(): void
    {
        $this->store->record(['event' => 'ai_fallback', 'level' => 'warning', 'provider' => 'anthropic', 'ts' => 5]);
        $this->store->record(['event' => 'http_500', 'level' => 'error', 'exception_class' => 'RuntimeException', 'ts' => 6]);

        self::assertCount(1, $this->store->recent(query: 'anthropic'));
        self::assertCount(1, $this->store->recent(query: 'runtimeexception'));
    }

    public function testSummaryAggregatesByLevelAndEvent(): void
    {
        $this->store->record(['event' => 'http_500', 'level' => 'error', 'route' => 'chat_send', 'ts' => 100]);
        $this->store->record(['event' => 'http_500', 'level' => 'error', 'route' => 'chat_send', 'ts' => 101]);
        $this->store->record(['event' => 'ai_fallback', 'level' => 'warning', 'ts' => 102]);

        $summary = $this->store->summary(0);

        self::assertSame(3, $summary['total']);
        self::assertSame(2, $summary['by_level']['error']);
        self::assertSame(1, $summary['by_level']['warning']);
        self::assertSame(2, $summary['by_event']['http_500']);
        self::assertSame(2, $summary['by_route']['chat_send']);
        self::assertCount(2, $summary['recent_errors']);
    }

    public function testStackFramesAreCappedAtFifteen(): void
    {
        $frames = array_map(static fn (int $i): string => "file.php:$i", range(1, 40));
        $this->store->record(['event' => 'boom', 'level' => 'error', 'stack' => $frames, 'ts' => 7]);

        $recent = $this->store->recent(level: 'error');
        self::assertLessThanOrEqual(15, \count($recent[0]['stack']));
    }
}
