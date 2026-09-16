<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\Section\AgentBundleSection;
use App\Repository\AgentRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use PHPUnit\Framework\TestCase;

final class BundleExportNeverTest extends TestCase
{
    public function testExportJsonNeverContainsSecretsOrForeignIds(): void
    {
        $section = new AgentBundleSection(
            $this->createMock(AgentRepository::class),
            $this->createMock(AgentService::class),
            $this->createMock(AgentConfig::class),
            new AgentDefinitionValidator(),
            $this->createMock(McpServerConfigRepository::class),
            $this->createMock(UserRepository::class),
        );

        $stripped = $section->stripForExport([
            'schema' => 'agent.v1',
            'knowledge' => ['ownFolder' => true, 'folders' => ['9:shared'], 'includeUserFiles' => false, 'ragLimit' => 8, 'ragMinScore' => 0.6],
            'tools' => ['internet' => true, 'files' => true, 'mcpServers' => [3], 'allow' => [], 'deny' => []],
            'triggers' => [
                'events' => [
                    ['id' => 'mail-1', 'kind' => 'mail', 'mailbox' => '9:88', 'number' => '+1555', 'widget' => '9:w1'],
                ],
                'schedules' => [],
            ],
            'models' => ['chat' => 'openai:gpt-4o:chat', 'vision' => null, 'vectorize' => null],
        ], 9);

        $json = json_encode($stripped, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sk_', $json);
        self::assertStringNotContainsString('BAPIKEYS', $json);
        self::assertStringNotContainsString('9:88', $json);
        self::assertStringNotContainsString('+1555', $json);
        self::assertStringNotContainsString('9:w1', $json);
        self::assertSame([], $stripped['knowledge']['folders']);
    }
}
