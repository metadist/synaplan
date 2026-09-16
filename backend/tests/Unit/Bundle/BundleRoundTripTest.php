<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\ImportOptions;
use App\Bundle\Section\AgentBundleSection;
use App\Entity\Agent;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use PHPUnit\Framework\TestCase;

final class BundleRoundTripTest extends TestCase
{
    public function testPreviewNamesMissingModelThenApplyCreatesDraft(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn(4);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($owner);

        $draft = new Agent(4, 1, 'contract-review', 'Contract review', AgentDefinition::defaults()->toArray());
        $agentService = $this->createMock(AgentService::class);
        $agentService->expects(self::once())->method('importDraft')->willReturn($draft);

        $section = new AgentBundleSection(
            $this->createMock(AgentRepository::class),
            $agentService,
            $this->createMock(AgentConfig::class),
            new AgentDefinitionValidator(),
            $this->createMock(McpServerConfigRepository::class),
            $users,
        );

        $definition = AgentDefinition::defaults()->toArray();
        $definition['models']['chat'] = 'missing-provider:does-not-exist:chat';
        $definition['triggers'] = [
            'events' => [
                ['id' => 'mail-1', 'kind' => 'mail'],
                ['id' => 'widget-1', 'kind' => 'widget'],
            ],
            'schedules' => [
                ['id' => 's1', 'name' => 'Morning', 'tz' => 'UTC', 'instruction' => 'Ping', 'every' => ['unit' => 'day'], 'enabled' => true],
            ],
        ];

        $preview = $section->preview([
            [
                'key' => 'contract-review',
                'name' => 'Contract review',
                'definition' => $definition,
            ],
        ], 4);
        $codes = array_column($preview->toArray()['items'], 'code');
        self::assertContains('needsModel', $codes);
        self::assertContains('needsMailbox', $codes);
        self::assertContains('needsWidget', $codes);
        self::assertContains('schedulesOff', $codes);

        $result = $section->apply([
            [
                'key' => 'contract-review',
                'name' => 'Contract review',
                'instruction' => 'Review it',
                'definition' => $definition,
            ],
        ], 4, new ImportOptions());
        self::assertSame(['contract-review'], $result->toArray()['created']);
        self::assertSame([], $result->toArray()['failed']);
        self::assertSame(Agent::STATUS_DRAFT, $draft->getStatus());
        self::assertNull($draft->getPublishedVersionId());
    }
}
