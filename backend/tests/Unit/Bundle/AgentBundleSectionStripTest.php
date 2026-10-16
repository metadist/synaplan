<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\ImportOptions;
use App\Bundle\Section\AgentBundleSection;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use PHPUnit\Framework\TestCase;

final class AgentBundleSectionStripTest extends TestCase
{
    public function testStripDropsInstanceIdsAndSharedFolders(): void
    {
        $mcp = $this->createMock(McpServerConfigRepository::class);
        $server = $this->createMock(McpServerConfig::class);
        $server->method('getId')->willReturn(9);
        $server->method('getName')->willReturn('docs-search');
        $mcp->method('findByUser')->willReturn([$server]);

        $section = new AgentBundleSection(
            $this->createMock(AgentRepository::class),
            $this->createMock(AgentService::class),
            $this->createMock(AgentConfig::class),
            new AgentDefinitionValidator(),
            $mcp,
            $this->createMock(UserRepository::class),
        );

        $stripped = $section->stripForExport([
            'schema' => 'agent.v1',
            'knowledge' => ['ownFolder' => true, 'folders' => ['4:shared'], 'includeUserFiles' => false, 'ragLimit' => 8, 'ragMinScore' => 0.6],
            'tools' => ['internet' => true, 'files' => true, 'mcpServers' => [9], 'allow' => [], 'deny' => []],
            'triggers' => [
                'events' => [
                    ['id' => 'mail-1', 'kind' => 'mail', 'mailbox' => '4:12', 'rule' => ['from' => [], 'contains' => [], 'match' => 'any']],
                    ['id' => 'api-1', 'kind' => 'api', 'enabled' => true],
                ],
                'schedules' => [
                    ['id' => 's1', 'name' => 'Morning', 'tz' => 'UTC', 'instruction' => 'Ping', 'every' => ['unit' => 'day'], 'enabled' => true],
                ],
            ],
        ], 4);

        self::assertSame([], $stripped['knowledge']['folders']);
        self::assertTrue($stripped['knowledge']['droppedFolders']);
        self::assertSame(['docs-search'], $stripped['tools']['mcpServers']);
        self::assertArrayNotHasKey('mailbox', $stripped['triggers']['events'][0]);
        self::assertSame('mail', $stripped['triggers']['events'][0]['kind']);
        self::assertFalse($stripped['triggers']['schedules'][0]['enabled']);
        self::assertSame('api', $stripped['triggers']['events'][1]['kind']);
        $json = json_encode($stripped, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sk_', $json);
        self::assertStringNotContainsString('"4:12"', $json);
    }

    public function testSkipDoesNotCreateASecondAssistant(): void
    {
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::once())->method('slugTaken')->with(4, 'ping')->willReturn(true);
        $service = $this->createMock(AgentService::class);
        $service->expects(self::never())->method('importDraft');
        $service->expects(self::never())->method('replaceImportedDraft');

        $result = $this->section($agents, $service)->apply(
            [['key' => 'ping', 'name' => 'Ping', 'definition' => AgentDefinition::defaults()->toArray()]],
            4,
            new ImportOptions(ImportOptions::CONFLICT_SKIP),
        );

        self::assertSame(['ping'], $result->toArray()['skipped']);
        self::assertSame([], $result->toArray()['created']);
    }

    public function testOverwriteReplacesTheDraftInsteadOfCreatingOne(): void
    {
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::once())->method('slugTaken')->with(4, 'ping')->willReturn(true);
        $service = $this->createMock(AgentService::class);
        $service->expects(self::never())->method('importDraft');
        $service->expects(self::once())->method('replaceImportedDraft');

        $result = $this->section($agents, $service)->apply(
            [['key' => 'ping', 'name' => 'Ping', 'definition' => AgentDefinition::defaults()->toArray()]],
            4,
            new ImportOptions(ImportOptions::CONFLICT_OVERWRITE),
        );

        self::assertSame(['ping'], $result->toArray()['created']);
    }

    private function section(AgentRepository $agents, AgentService $service): AgentBundleSection
    {
        $users = $this->createMock(UserRepository::class);
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(4);
        $users->expects(self::once())->method('find')->with(4)->willReturn($owner);

        return new AgentBundleSection(
            $agents,
            $service,
            $this->createStub(AgentConfig::class),
            new AgentDefinitionValidator(),
            $this->createStub(McpServerConfigRepository::class),
            $users,
        );
    }
}
