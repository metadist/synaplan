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

        self::assertSame(Capability::values(), $renderedOrder);
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
}
