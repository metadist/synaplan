<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Entity\Message;
use App\Service\Multitask\InProgressTurnResolver;
use App\Service\Multitask\TaskPlanStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class InProgressTurnResolverTest extends TestCase
{
    private TaskPlanStore&MockObject $store;
    private InProgressTurnResolver $resolver;

    protected function setUp(): void
    {
        $this->store = $this->createMock(TaskPlanStore::class);
        $this->resolver = new InProgressTurnResolver($this->store);
    }

    public function testReturnsNullForNoMessage(): void
    {
        self::assertNull($this->resolver->resolve(null));
    }

    public function testReturnsNullWhenNewestMessageIsAssistantReply(): void
    {
        // An OUT tail means the turn already completed — nothing in progress.
        $this->store->expects(self::never())->method('loadCards');

        self::assertNull($this->resolver->resolve($this->message('OUT', 'complete', 10)));
    }

    public function testReturnsNullWhenInboundMessageIsNotProcessing(): void
    {
        $this->store->expects(self::never())->method('loadCards');

        self::assertNull($this->resolver->resolve($this->message('IN', 'complete', 10)));
    }

    public function testReturnsNullWhenNoCardsPersistedYet(): void
    {
        $this->store->method('loadCards')->willReturn([]);

        self::assertNull($this->resolver->resolve($this->message('IN', 'processing', 10)));
    }

    public function testBuildsPayloadPickingFirstTextCardAsReplyNode(): void
    {
        $cards = [
            ['nodeId' => 'n1', 'capability' => 'image_generation', 'kind' => 'image', 'state' => 'running'],
            ['nodeId' => 'n2', 'capability' => 'chat', 'kind' => 'text', 'state' => 'running'],
        ];
        $this->store->method('loadCards')->with(10)->willReturn($cards);

        $result = $this->resolver->resolve($this->message('IN', 'processing', 10));

        self::assertNotNull($result);
        self::assertSame('n2', $result['reply_node']);
        self::assertSame($cards, $result['cards']);
    }

    public function testFallsBackToFirstCardWhenNoTextCard(): void
    {
        $cards = [
            ['nodeId' => 'n1', 'capability' => 'image_generation', 'kind' => 'image', 'state' => 'running'],
        ];
        $this->store->method('loadCards')->willReturn($cards);

        $result = $this->resolver->resolve($this->message('IN', 'processing', 10));

        self::assertNotNull($result);
        self::assertSame('n1', $result['reply_node']);
    }

    private function message(string $direction, string $status, int $id): Message
    {
        $message = new Message();
        $message->setDirection($direction);
        $message->setStatus($status);

        // Message::getId() has no setter — assign the persisted id via reflection
        // to simulate a message that has already been flushed.
        $ref = new \ReflectionProperty(Message::class, 'id');
        $ref->setValue($message, $id);

        return $message;
    }
}
