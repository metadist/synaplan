<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Source;

use App\Service\Tool\SideEffect;
use App\Service\Tool\Source\SynaplanMcpToolSource;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class SynaplanMcpToolSourceTest extends TestCase
{
    public function testMemoryAndFileIngestAreOwnRecords(): void
    {
        $tools = (new SynaplanMcpToolSource())->describe(7);
        $names = array_map(static fn (ToolDescriptor $tool): string => $tool->name, $tools);
        sort($names);

        self::assertSame(['file_ingest', 'memory_add'], $names);
        foreach ($tools as $tool) {
            self::assertSame(ToolSource::Plugin, $tool->source);
            self::assertSame(SideEffect::Write, $tool->sideEffect);
            self::assertSame(ToolDescriptor::POLICY_OWN_ARTEFACT, $tool->policyException);
            self::assertSame(7, $tool->ownerId);
        }
    }

    public function testAMissingUserHasNoTools(): void
    {
        self::assertSame([], (new SynaplanMcpToolSource())->describe(0));
    }
}
