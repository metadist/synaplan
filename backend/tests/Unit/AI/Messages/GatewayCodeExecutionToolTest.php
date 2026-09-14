<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\CodeExecutionTool;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\WebSearchTool;
use App\Entity\User;
use App\Service\Compute\ComputeRunGrant;
use App\Service\Mcp\McpToolRegistry;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

final class GatewayCodeExecutionToolTest extends TestCase
{
    public function testToolAbsentWithoutScope(): void
    {
        $snapshot = $this->catalog(grant: false)->build($this->user(), 's', []);

        self::assertSame([], $snapshot['tools']);
        self::assertArrayNotHasKey(CodeExecutionTool::NAME, $snapshot['dispatch']);
    }

    public function testToolAbsentForLegacyEmptyScopeKey(): void
    {
        $snapshot = $this->catalog(grant: false)->build($this->user(), 's', []);

        self::assertArrayNotHasKey(CodeExecutionTool::NAME, $snapshot['dispatch']);
        self::assertSame([], $this->catalog(grant: false)->replacedServerTools($snapshot));
    }

    public function testToolPresentWithScope(): void
    {
        $catalog = $this->catalog(grant: true);
        $snapshot = $catalog->build($this->user(), 's', []);

        self::assertSame(CodeExecutionTool::NAME, $snapshot['tools'][0]['name']);
        self::assertSame(['language', 'code'], $snapshot['tools'][0]['input_schema']['required']);
        self::assertSame([CodeExecutionTool::NAME], $catalog->replacedServerTools($snapshot));
    }

    public function testToolAbsentWhenAssistantExcludesCodeRun(): void
    {
        $assistant = new RuntimeProfile(
            promptId: 1,
            promptTopic: 'agent:demo',
            systemPrompt: 'Hi',
            modelIds: [],
            ragScopes: [],
            toolFlags: [],
            skillAllow: null,
            skillDeny: null,
            parameters: [],
        );
        $snapshot = $this->catalog(grant: true)->build($this->user(), 's', [], $assistant);

        self::assertArrayNotHasKey(CodeExecutionTool::NAME, $snapshot['dispatch']);
    }

    private function catalog(bool $grant): GatewayToolCatalog
    {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_OFF);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_OFF);

        $code = $this->createMock(CodeExecutionTool::class);
        $code->method('isAvailable')->willReturn(true);
        $code->method('declaration')->willReturn([
            'name' => CodeExecutionTool::NAME,
            'description' => 'File work',
            'input_schema' => [
                'type' => 'object',
                'required' => ['language', 'code'],
                'properties' => [],
            ],
        ]);

        $grantSvc = $this->createMock(ComputeRunGrant::class);
        $grantSvc->method('allows')->willReturnCallback(
            static function (?int $userId, ?RuntimeProfile $assistant) use ($grant): bool {
                if (!$grant) {
                    return false;
                }

                return \App\Service\Agent\Policy\AssistantSkillGate::allows($assistant, 'code_run');
            },
        );

        return new GatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $this->createMock(WebSearchTool::class),
            $this->createMock(AnalyzeImageTool::class),
            $config,
            $this->createMock(CacheItemPoolInterface::class),
            new NullLogger(),
            null,
            null,
            $code,
            $grantSvc,
        );
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);

        return $user;
    }
}
