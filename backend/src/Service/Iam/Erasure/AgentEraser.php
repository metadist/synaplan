<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Service\Agent\AgentCascadeCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 150)]
final readonly class AgentEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgentRepository $agents,
        private AgentCascadeCleanup $agentCascade,
        private LoggerInterface $logger,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->agents->findByOwner($erasure->userId) as $agent) {
            $cleanup = $this->agentCascade->unshareAndRemoveDependents($agent);
            $this->em->remove($agent);

            // Best-effort: the rows are gone; a Qdrant or filesystem failure
            // only leaves orphaned vectors or files.
            $erasure->afterCommit(function () use ($cleanup): void {
                try {
                    $this->agentCascade->purgeExternal($cleanup);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to purge assistant files or vectors after delete', [
                        'owner_id' => $cleanup->ownerId,
                        'group_key' => $cleanup->groupKey,
                        'exception' => $e,
                    ]);
                }
            });
        }
    }

    public static function covers(): array
    {
        return [Agent::class];
    }
}
