<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\CloudWatch;

use App\Service\CloudWatch\WatchChangeQueue;
use PHPUnit\Framework\TestCase;

final class WatchChangeQueueTest extends TestCase
{
    public function testEachPersonOnACloudIsToldOnce(): void
    {
        $queue = new WatchChangeQueue();
        $queue->add('pi_a', 'ada');
        $queue->add('pi_a', 'ada');
        $queue->add('pi_a', 'bob');
        $queue->add('pi_b', 'ada');
        $queue->add('', 'ada');
        $queue->add('pi_a', '');

        self::assertSame([
            ['instanceId' => 'pi_a', 'externalId' => 'ada'],
            ['instanceId' => 'pi_a', 'externalId' => 'bob'],
            ['instanceId' => 'pi_b', 'externalId' => 'ada'],
        ], $queue->pull());
        self::assertSame([], $queue->pull());
    }
}
