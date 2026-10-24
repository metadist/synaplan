<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageContext;
use Psr\Clock\ClockInterface;

/**
 * Builds what one step can see. The hosted world is the default until a run
 * stores the providers it is allowed to use.
 */
final readonly class ResolvingResearchStageContext implements ResearchStageContextFactory
{
    public function __construct(
        private UserRepository $users,
        private ResearchRoleResolver $roles,
        private ResearchEventLog $events,
        private ClockInterface $clock,
    ) {
    }

    public function open(ResearchRunState $state): StageContext
    {
        $user = $this->users->find($state->ref->userId);
        if (!$user instanceof User) {
            throw new RoleUnavailable('lead');
        }

        return new StageContext(
            $state,
            $this->roles->resolve($user, $state->ref->depth, new ResearchWorld(false), new ResearchModelHints(), null),
            $this->events,
            $this->clock->now(),
            new ResearchStop($state->stopRequested),
        );
    }
}
