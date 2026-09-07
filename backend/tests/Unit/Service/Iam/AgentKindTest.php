<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use PHPUnit\Framework\TestCase;

final class AgentKindTest extends TestCase
{
    public function testDraftsAreNotListedAndCannotBeShared(): void
    {
        $draft = new Agent(4, 20, 'drafty', 'Drafty', AgentDefinition::defaults()->toArray());
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($draft, 9);

        $repo = $this->createMock(AgentRepository::class);
        $repo->expects(self::atLeastOnce())->method('find')->willReturn($draft);
        $repo->method('findPublishedByOwner')->willReturn([]);

        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $kind = new AgentKind($repo, $this->createMock(AgentVersionRepository::class), $config);

        self::assertSame(AgentKind::KEY, $kind->key());
        self::assertSame([Permission::Read, Permission::Use, Permission::Edit], $kind->supportedPermissions());
        self::assertSame([], iterator_to_array($kind->listOwnedBy(4)));
        $this->expectException(ShareNotAllowedException::class);
        $kind->assertShareable('9');
    }

    public function testListOwnedByIsEmptyWhenFlagOff(): void
    {
        $published = new Agent(4, 20, 'live', 'Live', AgentDefinition::defaults()->toArray());
        $published->setPublishedVersionId(3);
        $published->setStatus(Agent::STATUS_PUBLISHED);
        $repo = $this->createMock(AgentRepository::class);
        $repo->expects(self::never())->method('findPublishedByOwner');

        $config = $this->createMock(AgentConfig::class);
        $config->method('isEnabled')->willReturn(false);

        $kind = new AgentKind($repo, $this->createMock(AgentVersionRepository::class), $config);
        self::assertSame([], iterator_to_array($kind->listOwnedBy(4)));
    }
}
