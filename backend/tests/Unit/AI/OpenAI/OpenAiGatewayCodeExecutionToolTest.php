<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\OpenAI;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\CodeExecutionTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\OpenAI\OpenAiGatewayToolCatalog;
use App\Entity\User;
use App\Service\Compute\ComputeRunGrant;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use PHPUnit\Framework\TestCase;

final class OpenAiGatewayCodeExecutionToolTest extends TestCase
{
    public function testToolAbsentWithoutScope(): void
    {
        $snapshot = $this->catalog(false)->build($this->user(), []);

        self::assertSame([], $snapshot['tools']);
    }

    public function testToolAbsentForLegacyEmptyScopeKey(): void
    {
        self::assertArrayNotHasKey(
            CodeExecutionTool::NAME,
            $this->catalog(false)->build($this->user(), [])['dispatch'],
        );
    }

    public function testToolPresentWithScope(): void
    {
        $snapshot = $this->catalog(true)->build($this->user(), []);

        self::assertSame(CodeExecutionTool::NAME, $snapshot['tools'][0]['function']['name']);
        self::assertArrayHasKey(CodeExecutionTool::NAME, $snapshot['dispatch']);
    }

    private function catalog(bool $grant): OpenAiGatewayToolCatalog
    {
        $code = $this->createMock(CodeExecutionTool::class);
        $code->method('isAvailable')->willReturn(true);
        $code->method('declaration')->willReturn([
            'name' => CodeExecutionTool::NAME,
            'description' => 'File work',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ]);
        $grantSvc = $this->createMock(ComputeRunGrant::class);
        $grantSvc->method('allows')->willReturn($grant);

        $messages = $this->createMock(MessagesGatewayConfig::class);
        $messages->method('webSearchMode')->willReturn(MessagesGatewayConfig::WEB_SEARCH_OFF);

        $mcp = $this->createMock(McpClientConfig::class);
        $mcp->method('isClientEnabled')->willReturn(false);

        return new OpenAiGatewayToolCatalog(
            new McpToolCatalogAdapter($this->createMock(McpToolRegistry::class)),
            $mcp,
            $this->createMock(WebSearchTool::class),
            $messages,
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
