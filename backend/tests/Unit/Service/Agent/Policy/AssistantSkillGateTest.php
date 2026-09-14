<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent\Policy;

use App\Service\Agent\Policy\AssistantSkillGate;
use App\Service\Multitask\Plan\Capability;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;

final class AssistantSkillGateTest extends TestCase
{
    public function testUserChatWithoutAssistantAllowsCodeRun(): void
    {
        self::assertTrue(AssistantSkillGate::allows(null, 'code_run'));
    }

    public function testExistingAssistantIsExcluded(): void
    {
        $profile = $this->profile(null, null);

        self::assertFalse(AssistantSkillGate::allows($profile, 'code_run'));
        self::assertSame(
            array_values(array_filter(Capability::values(), static fn (string $n): bool => 'code_run' !== $n)),
            AssistantSkillGate::filterCapabilities(null, $profile),
        );
    }

    public function testOptInAllowsCodeRun(): void
    {
        $profile = $this->profile(['chat', 'code_run'], null);

        self::assertTrue(AssistantSkillGate::allows($profile, 'code_run'));
        self::assertNull(AssistantSkillGate::filterCapabilities(null, $profile));
    }

    public function testDenyWins(): void
    {
        $profile = $this->profile(['code_run'], ['code_run']);

        self::assertFalse(AssistantSkillGate::allows($profile, 'code_run'));
    }

    /**
     * @param list<string>|null $allow
     * @param list<string>|null $deny
     */
    private function profile(?array $allow, ?array $deny): RuntimeProfile
    {
        return new RuntimeProfile(
            promptId: 1,
            promptTopic: 'agent:demo',
            systemPrompt: 'Hi',
            modelIds: [],
            ragScopes: [],
            toolFlags: [],
            skillAllow: $allow,
            skillDeny: $deny,
            parameters: [],
        );
    }
}
