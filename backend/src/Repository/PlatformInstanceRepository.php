<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlatformInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformInstance>
 */
class PlatformInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformInstance::class);
    }

    public function findByInstanceId(string $instanceId): ?PlatformInstance
    {
        $row = $this->findOneBy(['instanceId' => $instanceId]);

        return $row instanceof PlatformInstance ? $row : null;
    }

    public function findOutlookBuiltin(): ?PlatformInstance
    {
        return $this->findByInstanceId(PlatformInstance::OUTLOOK_BUILTIN_ID);
    }

    /**
     * @param list<string> $instanceIds
     *
     * @return array<string, PlatformInstance> keyed by instance id
     */
    public function findByInstanceIds(array $instanceIds): array
    {
        if ([] === $instanceIds) {
            return [];
        }

        $byId = [];
        /** @var PlatformInstance $instance */
        foreach ($this->findBy(['instanceId' => $instanceIds]) as $instance) {
            $byId[$instance->getInstanceId()] = $instance;
        }

        return $byId;
    }

    /**
     * @return list<PlatformInstance>
     */
    public function findAllOrdered(): array
    {
        /** @var list<PlatformInstance> $rows */
        $rows = $this->createQueryBuilder('p')
            ->orderBy('p.created', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(PlatformInstance $instance, bool $flush = true): void
    {
        $this->getEntityManager()->persist($instance);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
