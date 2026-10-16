<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramAlbumBuffer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class TelegramAlbumBufferTest extends TestCase
{
    public function testPartsAreReturnedInTheOrderTheyWereSent(): void
    {
        $buffer = $this->buffer();

        $this->assertTrue($buffer->add(5, 'g1', 11, ['message_id' => 21]));
        $this->assertFalse($buffer->add(5, 'g1', 10, ['message_id' => 20]));

        $this->assertSame([10, 11], array_keys($buffer->take(5, 'g1')));
    }

    public function testARetriedAlbumJobStillFindsItsParts(): void
    {
        $buffer = $this->buffer();
        $buffer->add(5, 'g1', 10, ['message_id' => 20]);

        $buffer->take(5, 'g1');

        $this->assertSame([10], array_keys($buffer->take(5, 'g1')));
    }

    public function testACompletedAlbumIsGoneAndALatePartIsAnsweredAlone(): void
    {
        $buffer = $this->buffer();
        $buffer->add(5, 'g1', 10, ['message_id' => 20]);
        $buffer->take(5, 'g1');

        $this->assertNull($buffer->add(5, 'g1', 12, ['message_id' => 22]));
        $buffer->complete(5, 'g1');

        $this->assertSame([], $buffer->take(5, 'g1'));
        $this->assertNull($buffer->add(5, 'g1', 13, ['message_id' => 23]));
    }

    private function buffer(): TelegramAlbumBuffer
    {
        return new TelegramAlbumBuffer(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }
}
