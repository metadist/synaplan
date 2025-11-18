<?php

namespace App\Repository;

use App\Entity\RateLimitConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @extends ServiceEntityRepository<RateLimitConfig>
 */
class RateLimitConfigRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private CacheItemPoolInterface $cache
    ) {
        parent::__construct($registry, RateLimitConfig::class);
    }

    /**
     * Findet Rate-Limit-Config für Scope und Plan (mit Cache)
     */
    public function findByUserLevel(string $scope, string $userLevel): ?RateLimitConfig
    {
        $cacheKey = "rate_limit.{$scope}.{$userLevel}";
        $item = $this->cache->getItem($cacheKey);
        
        if ($item->isHit()) {
            return $item->get();
        }
        
        $config = $this->createQueryBuilder('r')
            ->where('r.scope = :scope')
            ->andWhere('r.plan = :plan')
            ->setParameter('scope', $scope)
            ->setParameter('plan', $userLevel)
            ->getQuery()
            ->getOneOrNullResult();
        
        $item->set($config);
        $item->expiresAfter(3600); // 1 Stunde Cache
        $this->cache->save($item);
        
        return $config;
    }

    /**
     * Findet alle Configs für einen Scope
     */
    public function findByScope(string $scope): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.scope = :scope')
            ->setParameter('scope', $scope)
            ->orderBy('r.plan', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Speichert Config
     */
    public function save(RateLimitConfig $config, bool $flush = true): void
    {
        $config->touch();
        $this->getEntityManager()->persist($config);
        
        if ($flush) {
            $this->getEntityManager()->flush();
            
            // Clear Cache
            $cacheKey = "rate_limit.{$config->getScope()}.{$config->getPlan()}";
            $this->cache->deleteItem($cacheKey);
        }
    }
}

