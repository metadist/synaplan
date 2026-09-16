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

    public function nextVersionNumber(int $agentId): int
    {
        $max = $this->createQueryBuilder('v')
            ->select('MAX(v.version)')
            ->where('v.agentId = :agentId')
            ->setParameter('agentId', $agentId)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }

    public function findLatest(int $agentId): ?AgentVersion
    {
        $version = $this->createQueryBuilder('v')
            ->where('v.agentId = :agentId')
            ->setParameter('agentId', $agentId)
            ->orderBy('v.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $version instanceof AgentVersion ? $version : null;
    }

    /**
     * @return list<AgentVersion>
     */
    public function findAllForAgent(int $agentId): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.agentId = :agentId')
            ->setParameter('agentId', $agentId)
            ->orderBy('v.version', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByAgentAndVersion(int $agentId, int $version): ?AgentVersion
    {
        $row = $this->findOneBy(['agentId' => $agentId, 'version' => $version]);

        return $row instanceof AgentVersion ? $row : null;
    }

    public function save(AgentVersion $version, bool $flush = true): void
    {
        $this->getEntityManager()->persist($version);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
