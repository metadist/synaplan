<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Skill;

use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Skill\SkillCatalog;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Tests\Support\SkillCatalogFactory;
use PHPUnit\Framework\TestCase;

final class SkillCatalogTest extends TestCase
{
    public function testEveryCapabilityHasARunnerDeclaredDescriptor(): void
    {
        $catalog = SkillCatalogFactory::real();

        foreach (Capability::cases() as $capability) {
            $descriptor = $catalog->descriptorFor($capability);
            self::assertNotNull($descriptor, "Capability '{$capability->value}' has no SkillDescriptor — did a runner forget describe()?");
            self::assertNotSame('', trim($descriptor->summary), "Capability '{$capability->value}' has an empty planner-facing summary.");
        }
    }

    public function testRenderOrderFollowsCapabilityEnumDeclarationNotRunnerOrder(): void
    {
        $catalog = SkillCatalogFactory::real();

        $lines = explode("\n", $catalog->renderCapabilityList());
        $renderedOrder = array_map(
            static fn (string $line): string => explode('"', $line)[1],
            $lines,
        );

        // Flag-gated blocks (url_fetch, …) may be omitted, but whatever IS
        // rendered must follow the Capability enum declaration order.
        $expected = array_values(array_intersect(Capability::values(), $renderedOrder));
        self::assertSame($expected, $renderedOrder);
        self::assertContains('chat', $renderedOrder);
        self::assertContains('compose_reply', $renderedOrder);
    }

    public function testDynamicNoteIsAppendedBelowTheSummaryWithTheUserId(): void
    {
        $stub = new class implements \App\Service\Multitask\Execution\TaskRunner {
            public function supportedCapabilities(): array
            {
                return [Capability::WebSearch];
            }

            public function describe(): array
            {
                return [new SkillDescriptor(
                    Capability::WebSearch,
                    'Search the web.',
                    static fn (?int $userId): string => "  Available for user {$userId}.",
                )];
            }

            public function run(\App\Service\Multitask\Plan\TaskNode $node, \App\Service\Multitask\Execution\NodeContext $context): \App\Service\Multitask\Execution\NodeResult
            {
                throw new \LogicException('not executed in this test');
            }
        };

        $rendered = (new SkillCatalog([$stub]))->renderCapabilityList(42);

        self::assertStringContainsString("- \"web_search\": Search the web.\n  Available for user 42.", $rendered);
    }

    public function testMissingDescriptorRendersEmptyDescriptionInsteadOfCrashing(): void
    {
        $rendered = (new SkillCatalog([]))->renderCapabilityList();

        self::assertStringContainsString('- "chat": ', $rendered);
        self::assertCount(count(Capability::cases()), explode("\n", $rendered));
    }

    public function testFlagGatedCapabilityIsOmittedWhenDisabledAndListedWhenEnabled(): void
    {
        // Default (no routing config, enabledDefault=false): url_fetch is
        // invisible to the planner.
        $off = SkillCatalogFactory::real()->renderCapabilityList();
        self::assertStringNotContainsString('"url_fetch"', $off);

        // Flag resolved ON: the capability line appears in enum order.
        $configRepo = $this->createMock(\App\Repository\ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'URL_FETCH_ENABLED' === $setting ? '1' : null,
        );
        $catalog = new SkillCatalog(
            SkillCatalogFactory::runners(),
            new \App\Service\Multitask\MultitaskRoutingConfig($configRepo),
        );

        $on = $catalog->renderCapabilityList();
        self::assertStringContainsString('- "url_fetch": ', $on);
        $lines = explode("\n", $on);
        self::assertCount(count(Capability::cases()), $lines);
    }
}
