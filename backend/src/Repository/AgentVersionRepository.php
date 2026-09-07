<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgentVersion>
 */
class AgentVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentVersion::class);
    }

    public function deleteForAgent(int $agentId): void
    {
        $this->createQueryBuilder('v')
            ->delete()
            ->where('v.agentId = :agentId')
            ->setParameter('agentId', $agentId)
            ->getQuery()
            ->execute();
    }
}
