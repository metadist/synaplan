<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\McpServerConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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

    public function findByUserAndName(int $userId, string $name): ?McpServerConfig
    {
        return $this->findOneBy(['userId' => $userId, 'name' => $name]);
    }

    public function save(McpServerConfig $config, bool $flush = true): void
    {
        $this->getEntityManager()->persist($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Stores a sign-in only while the row still has the address the sign-in
     * started with (and, unless $startingSignIn, still uses sign-in). A
     * concurrent edit wins: nothing is written and false is returned. The
     * entity is reloaded either way so a later flush cannot write stale
     * fields back.
     */
    public function storeOAuthStateAt(
        McpServerConfig $server,
        string $expectedUrl,
        string $encryptedState,
        bool $startingSignIn = false,
    ): bool {
        $sql = $startingSignIn
            // BAUTHTOKEN first: assignments run left to right and must see the old mode.
            ? "UPDATE BMCPSERVERS SET BAUTHTOKEN = IF(BAUTHMODE = 'oauth', BAUTHTOKEN, ''), BAUTHMODE = 'oauth', BOAUTH = :state, BUPDATED = :updated
               WHERE BID = :id AND BINARY BURL = :url"
            : "UPDATE BMCPSERVERS SET BOAUTH = :state, BUPDATED = :updated
               WHERE BID = :id AND BINARY BURL = :url AND BAUTHMODE = 'oauth'";

        $em = $this->getEntityManager();
        $statement = $em->getConnection()->prepare($sql);
        $statement->bindValue('state', $encryptedState);
        $statement->bindValue('updated', date('YmdHis'));
        $statement->bindValue('id', (int) $server->getId(), ParameterType::INTEGER);
        $statement->bindValue('url', $expectedUrl);
        $statement->executeStatement();

        $em->refresh($server);

        return $server->getUrl() === $expectedUrl && $server->isOAuth();
    }

    public function remove(McpServerConfig $config, bool $flush = true): void
    {
        $this->getEntityManager()->remove($config);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
