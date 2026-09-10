<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool;

use App\Service\Tool\Exception\DuplicateToolNameException;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function testDuplicateNamesThrow(): void
    {
        $source = new class implements ToolSourceInterface {
            public function source(): ToolSource
            {
                return ToolSource::Builtin;
            }

            public function describe(int $userId, array $context = []): array
            {
                $a = new ToolDescriptor('dup', 'A', '', [], SideEffect::Read, ToolSource::Builtin, 0);
                $b = new ToolDescriptor('dup', 'B', '', [], SideEffect::Read, ToolSource::Builtin, 0);

                return [$a, $b];
            }
        };

        $registry = new ToolRegistry([$source]);
        $this->expectException(DuplicateToolNameException::class);
        $registry->forUser(1);
    }

    public function testUnknownClassDefaultsToWrite(): void
    {
        $this->assertSame(SideEffect::Write, SideEffect::fromHints(null, null));
        $this->assertSame(SideEffect::Read, SideEffect::fromHints(true, false));
        $this->assertSame(SideEffect::Destructive, SideEffect::fromHints(false, true));
    }

    public function testGetByCallName(): void
    {
        $source = new class implements ToolSourceInterface {
            public function source(): ToolSource
            {
                return ToolSource::Mcp;
            }

            public function describe(int $userId, array $context = []): array
            {
                return [new ToolDescriptor(
                    'mcp:1:search',
                    'Search',
                    '',
                    [],
                    SideEffect::Read,
                    ToolSource::Mcp,
                    0,
                    meta: ['gatewayName' => 'search'],
                )];
            }
        };
        $registry = new ToolRegistry([$source]);
        $found = $registry->get(1, 'search');
        $this->assertNotNull($found);
        $this->assertSame('mcp:1:search', $found->name);
    }
}
