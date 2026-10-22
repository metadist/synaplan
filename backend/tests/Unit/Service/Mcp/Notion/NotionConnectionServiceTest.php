<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\Prompt;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\PromptRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpOAuthConsentService;
use App\Service\Mcp\Notion\NotionConnectionService;
use App\Service\Prompt\PromptService;
use App\Service\Security\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotionConnectionServiceTest extends TestCase
{
    public function testConnectReusesTheRowAndReturnsToTheNotionApp(): void
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn('1');

        $server = new McpServerConfig();
        $server->setUserId(4)->setName('Notion')->setUrl(NotionConnectionService::MCP_URL);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 9);

        $servers = $this->createStub(McpServerConfigRepository::class);
        $servers->method('findByUser')->willReturn([$server]);

        $prompt = new Prompt();
        $prompt->setOwnerId(0);
        $prompt->setTopic('general');
        $prompt->setPrompt('You are helpful.');
        $prompt->setShortDescription('General');
        (new \ReflectionProperty($prompt, 'id'))->setValue($prompt, 3);

        $prompts = $this->createStub(PromptRepository::class);
        $prompts->method('findByTopicAndUser')->willReturn($prompt);

        $promptService = $this->createMock(PromptService::class);
        $promptService->method('loadMetadataForPrompt')->willReturn(['tool_mcp' => true]);
        $promptService->expects($this->never())->method('createOwned');

        $consent = $this->createMock(McpOAuthConsentService::class);
        $consent->expects($this->once())->method('start')->with($server, 4, '/apps/notion')
            ->willReturn('https://mcp.notion.com/authorize?client_id=test');

        $service = new NotionConnectionService(
            new McpClientConfig($config),
            $servers,
            $consent,
            new EncryptionService('test-secret', new NullLogger()),
            $prompts,
            $promptService,
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $service->connect(4);

        self::assertSame('https://mcp.notion.com/authorize?client_id=test', $result['authorize_url']);
        self::assertSame(9, $result['server_id']);
    }
}
