<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\McpServerConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * User-scoped access to external MCP server connections. Every lookup is
 * keyed by the owning user id — cross-tenant reads are structurally
 * impossible (release-4.0 plan 09 §2.6).
 *
 * @extends ServiceEntityRepository<McpServerConfig>
 */
class McpServerConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, McpServerConfig::class);
    }

    /**
     * @return list<McpServerConfig>
     */
    public function findByUser(int $userId): array
    {
        return $this->findBy(['userId' => $userId], ['id' => 'ASC']);
    }

    /**
     * @return list<McpServerConfig>
     */
    public function findEnabledByUser(int $userId): array
    {
        return $this->findBy(['userId' => $userId, 'enabled' => true], ['id' => 'ASC']);
    }

    public function findByIdAndUser(int $id, int $userId): ?McpServerConfig
    {
        return $this->findOneBy(['id' => $id, 'userId' => $userId]);
    }

    public function save(McpServerConfig $config, bool $flush = true): void
    {
        $this->getEntityManager()->persist($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(McpServerConfig $config, bool $flush = true): void
    {
        $this->getEntityManager()->remove($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
