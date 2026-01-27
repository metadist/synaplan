<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PluginData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PluginData>
 */
class PluginDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PluginData::class);
    }

    /**
     * Find a specific data entry by user, plugin, type, and key.
     */
    public function findOneByKey(int $userId, string $pluginName, string $dataType, string $dataKey): ?PluginData
    {
        return $this->createQueryBuilder('p')
            ->where('p.userId = :userId')
            ->andWhere('p.pluginName = :pluginName')
            ->andWhere('p.dataType = :dataType')
            ->andWhere('p.dataKey = :dataKey')
            ->setParameter('userId', $userId)
            ->setParameter('pluginName', $pluginName)
            ->setParameter('dataType', $dataType)
            ->setParameter('dataKey', $dataKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all data entries of a specific type for a user's plugin.
     *
     * @return PluginData[]
     */
    public function findAllByType(int $userId, string $pluginName, string $dataType): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.userId = :userId')
            ->andWhere('p.pluginName = :pluginName')
            ->andWhere('p.dataType = :dataType')
            ->setParameter('userId', $userId)
            ->setParameter('pluginName', $pluginName)
            ->setParameter('dataType', $dataType)
            ->orderBy('p.dataKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all data entries for a user's plugin.
     *
     * @return PluginData[]
     */
    public function findAllByPlugin(int $userId, string $pluginName): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.userId = :userId')
            ->andWhere('p.pluginName = :pluginName')
            ->setParameter('userId', $userId)
            ->setParameter('pluginName', $pluginName)
            ->orderBy('p.dataType', 'ASC')
            ->addOrderBy('p.dataKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete all data for a user's plugin.
     */
    public function deleteAllByPlugin(int $userId, string $pluginName): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.userId = :userId')
            ->andWhere('p.pluginName = :pluginName')
            ->setParameter('userId', $userId)
            ->setParameter('pluginName', $pluginName)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete all data of a specific type for a user's plugin.
     */
    public function deleteAllByType(int $userId, string $pluginName, string $dataType): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.userId = :userId')
            ->andWhere('p.pluginName = :pluginName')
            ->andWhere('p.dataType = :dataType')
            ->setParameter('userId', $userId)
            ->setParameter('pluginName', $pluginName)
            ->setParameter('dataType', $dataType)
            ->getQuery()
            ->execute();
    }
}
