<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Names of a user's searchable documents, newest first.
 */
final readonly class LibraryFileNameQuery
{
    private const LIMIT = 2000;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function searchableForUser(int $userId): array
    {
        $statement = $this->connection->prepare(
            'SELECT BID, BORIGINALNAME, BFILENAME FROM BFILES'
            .' WHERE BUSERID = :user AND BEPHEMERAL = 0 AND BVECTORSTATE IN (:vectorized, :stale)'
            .' ORDER BY BID DESC LIMIT '.self::LIMIT,
        );
        $statement->bindValue('user', $userId, ParameterType::INTEGER);
        $statement->bindValue('vectorized', File::VECTOR_STATE_VECTORIZED);
        $statement->bindValue('stale', File::VECTOR_STATE_STALE);
        $rows = $statement->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['BID'],
            'name' => (string) (($row['BORIGINALNAME'] ?? '') ?: $row['BFILENAME']),
        ], $rows);
    }
}
