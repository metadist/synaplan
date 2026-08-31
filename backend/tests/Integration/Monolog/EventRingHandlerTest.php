<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monolog;

use App\Monolog\EventRingHandler;
use App\Observability\EventRingStore;
use App\Observability\RequestIdGenerator;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class EventRingHandlerTest extends KernelTestCase
{
    private EventRingStore $store;
    private RequestStack $requestStack;
    private EventRingHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $store = self::getContainer()->get(EventRingStore::class);
        self::assertInstanceOf(EventRingStore::class, $store);
        $this->store = $store;
        $this->store->clear();

        $this->requestStack = new RequestStack();
        $this->handler = new EventRingHandler($this->store, $this->requestStack);
    }

    protected function tearDown(): void
    {
        $this->store->clear();
        parent::tearDown();
    }

    public function testWritesErrorWithScrubbedExceptionAndStack(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable('@1000'),
            'app',
            Level::Error,
            'RAG failed for user@example.com',
            ['exception' => new \RuntimeException('boom for admin@synaplan.com')],
        );

        $this->handler->handle($record);

        $events = $this->store->recent(level: 'error');
        self::assertCount(1, $events);
        self::assertSame('exception', $events[0]['event']);
        self::assertSame('RuntimeException', $events[0]['exception_class']);
        self::assertStringNotContainsString('@synaplan.com', (string) $events[0]['exception_message']);
        self::assertStringNotContainsString('@example.com', (string) $events[0]['message']);
        self::assertNotEmpty($events[0]['stack']);
        self::assertSame(gethostname() ?: null, $events[0]['host']);
    }

    public function testIgnoresRecordsBelowWarning(): void
    {
        $record = new LogRecord(new \DateTimeImmutable('@1000'), 'app', Level::Info, 'just info');

        $this->handler->handle($record);

        self::assertCount(0, $this->store->recent());
    }

    public function testEnrichesFromCurrentRequest(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'chat_send');
        $request->attributes->set(RequestIdGenerator::ATTRIBUTE, 'req-xyz');
        $request->setMethod('POST');
        $this->requestStack->push($request);

        $this->handler->handle(new LogRecord(new \DateTimeImmutable('@2000'), 'app', Level::Warning, 'slow'));

        $events = $this->store->recent();
        self::assertCount(1, $events);
        self::assertSame('chat_send', $events[0]['route']);
        self::assertSame('POST', $events[0]['method']);
        self::assertSame('req-xyz', $events[0]['request_id']);
    }

    public function testCopiesAllowListedContextFields(): void
    {
        $this->handler->handle(new LogRecord(
            new \DateTimeImmutable('@3000'),
            'app',
            Level::Error,
            'AI request failed',
            [
                'provider' => 'anthropic',
                'model' => 'claude-sonnet',
                'worker' => 'ReVectorizeMessageHandler',
                'user_id' => 4711,
                'status_code' => 502,
                'duration_ms' => '812',
            ],
        ));

        $events = $this->store->recent(level: 'error');
        self::assertCount(1, $events);
        self::assertSame('anthropic', $events[0]['provider']);
        self::assertSame('claude-sonnet', $events[0]['model']);
        self::assertSame('ReVectorizeMessageHandler', $events[0]['worker']);
        self::assertSame(4711, $events[0]['user_id']);
        self::assertSame(502, $events[0]['status_code']);
        self::assertSame(812, $events[0]['duration_ms']);
    }

    /**
     * The context is an allow-list, not a passthrough: log calls across the app
     * also put addresses and payloads in there.
     */
    public function testDropsContextFieldsThatAreNotAllowListed(): void
    {
        $this->handler->handle(new LogRecord(
            new \DateTimeImmutable('@3100'),
            'app',
            Level::Error,
            'Mail delivery failed',
            ['to' => 'victim@example.com', 'email' => 'victim@example.com', 'payload' => 'secret body'],
        ));

        $events = $this->store->recent(level: 'error');
        self::assertCount(1, $events);
        self::assertStringNotContainsString('victim@example.com', json_encode($events[0], \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret body', json_encode($events[0], \JSON_THROW_ON_ERROR));
    }

    /**
     * `$logger->error('X failed', ['error' => $e->getMessage()])` without an
     * exception object is by far the most common shape in this codebase; the
     * reason must survive into the event, scrubbed.
     */
    public function testUsesScrubbedContextErrorWhenNoExceptionIsAttached(): void
    {
        $this->handler->handle(new LogRecord(
            new \DateTimeImmutable('@3200'),
            'app',
            Level::Error,
            'Embedding failed',
            ['error' => 'no route to host for admin@synaplan.com'],
        ));

        $events = $this->store->recent(level: 'error');
        self::assertCount(1, $events);
        self::assertSame('log', $events[0]['event']);
        self::assertNull($events[0]['exception_class']);
        self::assertStringContainsString('no route to host', (string) $events[0]['exception_message']);
        self::assertStringNotContainsString('admin@synaplan.com', (string) $events[0]['exception_message']);
    }
}
