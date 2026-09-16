<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Source;

use App\Service\Tool\SideEffect;
use App\Service\Tool\Source\SkillToolSource;
use App\Tests\Support\SkillCatalogFactory;
use PHPUnit\Framework\TestCase;

final class SkillToolSourceTest extends TestCase
{
    public function testWriteSkillsMatchTheApprovalGate(): void
    {
        $byName = [];
        foreach ((new SkillToolSource(SkillCatalogFactory::real()))->describe(1) as $descriptor) {
            $byName[$descriptor->name] = $descriptor;
        }

        self::assertSame(SideEffect::Write, $byName['skill:email_me']->sideEffect);
        self::assertSame(SideEffect::Write, $byName['skill:save_to_folder']->sideEffect);
        self::assertSame(SideEffect::Write, $byName['skill:calendar_event']->sideEffect);
        self::assertSame(SideEffect::Write, $byName['skill:mcp_action']->sideEffect);
        self::assertSame(SideEffect::Read, $byName['skill:compose_reply']->sideEffect);
        self::assertSame(SideEffect::Read, $byName['skill:chat']->sideEffect);
    }
}
