<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Queries the Synaplan Demo client needs that no entity repository answers:
 * the model rows imported for one OpenAI-compatible endpoint, the default
 * bindings that point at them, and whether any vectors exist yet.
 */
final readonly class FreeApiRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<int> BMODELS ids routed to the endpoint (active or not)
     */
    public function modelIdsForEndpoint(string $service, string $endpoint): array
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT BID FROM BMODELS WHERE BSERVICE = :service AND JSON_UNQUOTE(JSON_EXTRACT(BJSON, '$.endpoint')) = :endpoint ORDER BY BID",
            ['service' => $service, 'endpoint' => $endpoint],
        );

        return array_map('intval', $ids);
    }

    /**
     * DEFAULTMODEL rows of every owner that point at one of the models.
     *
     * @param list<int> $modelIds
     *
     * @return list<array{ownerId: int, capability: string, modelId: int}>
     */
    public function bindingsTo(array $modelIds): array
    {
        if ([] === $modelIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT BOWNERID, BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BVALUE IN (:ids)",
            ['ids' => array_map('strval', $modelIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): array => [
            'ownerId' => (int) $row['BOWNERID'],
            'capability' => (string) $row['BSETTING'],
            'modelId' => (int) $row['BVALUE'],
        ], $rows);
    }

    /**
     * Removes the DEFAULTMODEL rows of members (owner > 0) that point at one of
     * the models, so they fall back to the instance defaults.
     *
     * @param list<int> $modelIds
     *
     * @return int rows removed
     */
    public function deleteMemberBindingsTo(array $modelIds): int
    {
        if ([] === $modelIds) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BOWNERID > 0 AND BVALUE IN (:ids)",
            ['ids' => array_map('strval', $modelIds)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * True once any document has been embedded. BFILES.BCHUNKCOUNT is the
     * chunk cache for vectors stored in Qdrant, where BRAG stays empty; BRAG
     * is the MariaDB copy. An active BUSERMEMORIES row is text only.
     */
    public function hasStoredVectors(): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM BFILES WHERE BCHUNKCOUNT > 0 LIMIT 1')
            || false !== $this->connection->fetchOne('SELECT 1 FROM BRAG LIMIT 1');
    }
}
