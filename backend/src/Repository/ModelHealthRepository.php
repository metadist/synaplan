<?php

declare(strict_types=1);

namespace App\Repository;

use App\AI\Health\FailureKind;
use App\AI\Health\ModelHealthState;
use App\Entity\ModelHealth;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModelHealth>
 */
class ModelHealthRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModelHealth::class);
    }

    public function findByModelId(int $modelId): ?ModelHealth
    {
        return $this->findOneBy(['modelId' => $modelId]);
    }

    /**
     * Existing rows keyed by model id, for callers that need many at once
     * (status page, health check) without an N+1 query.
     *
     * @param list<int> $modelIds
     *
     * @return array<int, ModelHealth>
     */
    public function findIndexedByModelId(array $modelIds = []): array
    {
        $qb = $this->createQueryBuilder('h');
        if ([] !== $modelIds) {
            $qb->where('h.modelId IN (:ids)')->setParameter('ids', $modelIds, ArrayParameterType::INTEGER);
        }

        $indexed = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            /* @var ModelHealth $row */
            $indexed[$row->getModelId()] = $row;
        }

        return $indexed;
    }

    /**
     * Get the row for a model, creating an unsaved one when it does not exist
     * yet. The caller decides when to persist and flush.
     */
    public function findOrCreate(int $modelId): ModelHealth
    {
        $health = $this->findByModelId($modelId);
        if (null !== $health) {
            return $health;
        }

        $health = (new ModelHealth())->setModelId($modelId);
        $this->getEntityManager()->persist($health);

        return $health;
    }

    /**
     * Ids of models the monitor currently considers dead.
     *
     * Restricted to a permanent verdict on purpose: a degraded model is flaky,
     * not gone, and routing away from every model that had a bad minute would
     * turn a provider hiccup into a platform-wide reshuffle. A credential
     * outage is excluded too — that is already handled provider-wide by the
     * usable-provider check, one layer up.
     *
     * INVARIANT: only confirmed evidence may reach this query, because routing
     * users away from a working model is as damaging as leaving a dead one in
     * place. Offline+Permanent is written in exactly two situations, both of
     * which the provider itself decided: it rejected the model id outright, or
     * real calls to it failed permanently. Absence from a published model list
     * is deliberately NOT one of them — those lists are partial, and an earlier
     * version that trusted them condemned three working Imagen models.
     *
     * @return list<int>
     */
    public function findOfflineModelIds(): array
    {
        $rows = $this->createQueryBuilder('h')
            ->select('h.modelId')
            ->where('h.state = :state')
            ->andWhere('h.kind = :kind')
            ->setParameter('state', ModelHealthState::Offline->value)
            ->setParameter('kind', FailureKind::Permanent->value)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['modelId'], $rows);
    }

    /**
     * Models this automation switched off and may switch back on.
     *
     * @return list<ModelHealth>
     */
    public function findAutoDisabled(): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.autoDisabled = 1')
            ->getQuery()
            ->getResult();
    }

    /**
     * Drop rows whose model no longer exists, so a deleted model does not keep
     * a stale entry on the status page forever.
     */
    public function pruneOrphans(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE h FROM BMODELHEALTH h LEFT JOIN BMODELS m ON m.BID = h.BMODELID WHERE m.BID IS NULL'
        );
    }
}
