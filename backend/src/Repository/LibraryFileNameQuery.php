<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

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
        $rows = $this->connection->executeQuery(
            'SELECT BID, BORIGINALNAME, BFILENAME FROM BFILES'
            .' WHERE BUSERID = :user AND BEPHEMERAL = 0 AND BVECTORSTATE IN (:states)'
            .' ORDER BY BID DESC LIMIT '.self::LIMIT,
            ['user' => $userId, 'states' => [File::VECTOR_STATE_VECTORIZED, File::VECTOR_STATE_STALE]],
            ['states' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['BID'],
            'name' => (string) (($row['BORIGINALNAME'] ?? '') ?: $row['BFILENAME']),
        ], $rows);
    }
}
