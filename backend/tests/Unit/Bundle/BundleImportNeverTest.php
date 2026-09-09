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

final class BundleImportNeverTest extends TestCase
{
    public function testApplyCreatesDraftForImporterAndNeverTouchesShares(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn(4);

        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($owner);

        $draft = new Agent(4, 1, 'contract-review', 'Contract review', AgentDefinition::defaults()->toArray());
        $draft->setSource(Agent::SOURCE_IMPORT);

        $agentService = $this->createMock(AgentService::class);
        $agentService->expects(self::once())
            ->method('importDraft')
            ->with(
                $owner,
                'Contract review',
                'contract-review',
                $this->callback(static fn (mixed $definition): bool => is_array($definition)),
                'Review it',
                null,
                null,
            )
            ->willReturn($draft);

        $section = new AgentBundleSection(
            $this->createMock(AgentRepository::class),
            $agentService,
            $this->createMock(AgentConfig::class),
            new AgentDefinitionValidator(),
            $this->createMock(McpServerConfigRepository::class),
            $users,
        );

        $result = $section->apply([
            [
                'key' => 'contract-review',
                'name' => 'Contract review',
                'instruction' => 'Review it',
                'definition' => AgentDefinition::defaults()->toArray(),
            ],
        ], 4, new ImportOptions());

        self::assertSame(['contract-review'], $result->toArray()['created']);
        self::assertSame(Agent::SOURCE_IMPORT, $draft->getSource());
        self::assertSame(Agent::STATUS_DRAFT, $draft->getStatus());
        self::assertNull($draft->getPublishedVersionId());
        self::assertSame(4, $draft->getOwnerId());
    }
}
