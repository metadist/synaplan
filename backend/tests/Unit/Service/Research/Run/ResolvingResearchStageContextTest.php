<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchRoleResolver;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResolvingResearchStageContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ResolvingResearchStageContextTest extends TestCase
{
    public function testAStepAfterAWaitStartsWithTheTimeAlreadyWorked(): void
    {
        $created = new \DateTimeImmutable('2026-10-24T09:00:00+00:00');
        $budget = ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard), $created);
        $budget->at($created->modify('+90 seconds'));
        $saved = ResearchRunState::open(
            ResearchRunRef::start(7, ResearchDepth::Standard, 'Which shop is cheaper?', null, $created),
            $budget,
        );
        $state = ResearchRunState::decode($saved->encode());
        self::assertInstanceOf(ResearchRunState::class, $state);

        $clock = new MockClock($created->modify('+15 minutes'));
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn(new User());
        $roles = $this->createStub(ResearchRoleResolver::class);
        $roles->method('resolve')->willReturn(new ResearchRoles(1, 2, 3, 4, 5, false, []));
        $factory = new ResolvingResearchStageContext(
            $users,
            $roles,
            $this->createStub(ResearchEventLog::class),
            $this->createStub(ResearchStateStore::class),
            $clock,
        );

        $ctx = $factory->open($state);
        $ctx->state->budget->at($clock->now()->modify('+30 seconds'));

        self::assertSame([120, 360], $ctx->state->budget->toArray()['seconds']);
    }
}
