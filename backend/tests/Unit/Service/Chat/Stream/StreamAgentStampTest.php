<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\Message;
use App\Service\Chat\Stream\StreamMessageMeta;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StreamAgentStampTest extends TestCase
{
    private StreamMessageMeta $meta;

    protected function setUp(): void
    {
        $this->meta = new StreamMessageMeta(new NullLogger());
    }

    public function testStampWritesAgentIdOnIncomingAndOutgoing(): void
    {
        $incoming = $this->messageWithId(11);
        $outgoing = $this->messageWithId(12);
        $this->invokeStamp($incoming, 7);
        $this->invokeStamp($outgoing, 7);

        self::assertSame('7', $incoming->getMeta('AGENTID'));
        self::assertSame('7', $outgoing->getMeta('AGENTID'));
    }

    public function testStampIsNoOpWhenAgentIdMissing(): void
    {
        $message = $this->messageWithId(13);
        $this->invokeStamp($message, null);

        self::assertNull($message->getMeta('AGENTID'));
    }

    /**
     * MessageMeta::setMessage() copies a non-nullable int id, so a transient
     * Message needs one before setMeta() works.
     */
    private function messageWithId(int $id): Message
    {
        $message = new Message();
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function invokeStamp(Message $message, ?int $agentId): void
    {
        $this->meta->stampAgentId($message, $agentId);
    }
}
