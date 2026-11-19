<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Loop;

use App\Service\Tool\Loop\ToolCallPartition;
use PHPUnit\Framework\TestCase;

final class ToolCallPartitionTest extends TestCase
{
    public function testSplitsOursFromTheClientsAndDropsTheRestInOrder(): void
    {
        $calls = [['name' => 'a'], ['name' => 'x'], ['name' => 'skip'], ['name' => 'b'], ['name' => 'y']];

        $partition = ToolCallPartition::of(
            $calls,
            static fn (array $call): ?bool => match ($call['name']) {
                'a', 'b' => true,
                'skip' => null,
                default => false,
            },
        );

        self::assertSame([['name' => 'a'], ['name' => 'b']], $partition->ours);
        self::assertSame([['name' => 'x'], ['name' => 'y']], $partition->client);
    }
}
