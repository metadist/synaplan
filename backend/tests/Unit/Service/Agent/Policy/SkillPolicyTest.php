<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent\Policy;

use App\Service\Agent\Policy\SkillPolicy;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;

final class SkillPolicyTest extends TestCase
{
    public function testDenyWinsOverAllow(): void
    {
        $profile = $this->profile(['chat', 'email_me'], ['email_me']);

        self::assertFalse(SkillPolicy::isAllowed($profile, 'email_me'));
        self::assertTrue(SkillPolicy::isAllowed($profile, 'chat'));
        self::assertSame(['chat'], SkillPolicy::allowedCapabilities($profile));
    }

    public function testNullAllowIsUnrestrictedUnlessDenied(): void
    {
        $profile = $this->profile(null, ['email_me']);

        self::assertFalse(SkillPolicy::isAllowed($profile, 'email_me'));
        self::assertTrue(SkillPolicy::isAllowed($profile, 'chat'));
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
