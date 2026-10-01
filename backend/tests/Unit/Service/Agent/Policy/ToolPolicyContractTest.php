<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent\Policy;

use App\Service\Agent\Policy\LegacyFlagToolPolicy;
use App\Service\Agent\Policy\RegistryToolPolicy;
use App\Service\Agent\Policy\ToolPolicySourceInterface;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolPolicyContractTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: ToolPolicySourceInterface}>
     */
    public static function adapters(): array
    {
        $legacy = new LegacyFlagToolPolicy();

        return [
            ['legacy', $legacy],
            ['registry', new RegistryToolPolicy($legacy)],
        ];
    }

    #[DataProvider('adapters')]
    public function testInternetOffBlocksWebSearch(string $_name, ToolPolicySourceInterface $policy): void
    {
        $profile = $this->profile($policy->flagsFromDefinition([
            'internet' => false,
            'files' => true,
            'mcpServers' => [],
            'allow' => [],
            'deny' => [],
        ]));

        self::assertFalse($policy->isAllowed($profile, 'web_search'));
        self::assertTrue($policy->isAllowed($profile, 'rag_search'));
    }

    #[DataProvider('adapters')]
    public function testInternetOnOnlyAllowsWebSearch(string $_name, ToolPolicySourceInterface $policy): void
    {
        $flags = $policy->flagsFromDefinition([
            'internet' => true,
            'files' => true,
            'mcpServers' => [],
            'allow' => [],
            'deny' => [],
        ]);

        self::assertArrayNotHasKey('tool_internet', $flags, '"internet: true" must not force a search on every message');
        self::assertTrue($policy->isAllowed($this->profile($flags), 'web_search'));
    }

    #[DataProvider('adapters')]
    public function testInternetOffIsAHardDisableFlag(string $_name, ToolPolicySourceInterface $policy): void
    {
        $flags = $policy->flagsFromDefinition(['internet' => false]);

        self::assertFalse($flags['tool_internet']);
    }

    #[DataProvider('adapters')]
    public function testDenyWins(string $_name, ToolPolicySourceInterface $policy): void
    {
        $profile = $this->profile($policy->flagsFromDefinition([
            'internet' => true,
            'files' => true,
            'mcpServers' => [],
            'allow' => ['web_search'],
            'deny' => ['web_search'],
        ]));

        self::assertFalse($policy->isAllowed($profile, 'web_search'));
        self::assertSame([], $policy->allowedTools($profile));
    }

    /**
     * @param array<string, mixed> $flags
     */
    private function profile(array $flags): RuntimeProfile
    {
        return new RuntimeProfile(
            promptId: 1,
            promptTopic: 'agent:demo',
            systemPrompt: 'Hi',
            modelIds: [],
            ragScopes: [],
            toolFlags: $flags,
            skillAllow: null,
            skillDeny: null,
            parameters: [],
        );
    }
}
