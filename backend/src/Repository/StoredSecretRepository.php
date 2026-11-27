<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\SecretColumn;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads and replaces stored ciphertexts across tables, for the job that moves
 * them to the current format. A replacement names the value it expects to
 * find, so a secret its owner saved in the meantime is never overwritten.
 */
final readonly class StoredSecretRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $groups
     *
     * @return list<array{id: int, ownerId: int, group: string, setting: string, value: string}>
     */
    public function configRows(array $groups): array
    {
        if ([] === $groups) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT BID, BOWNERID, BGROUP, BSETTING, BVALUE FROM BCONFIG WHERE BGROUP IN (:groups) AND BVALUE <> '' ORDER BY BID",
            ['groups' => $groups],
            ['groups' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['BID'],
            'ownerId' => (int) $row['BOWNERID'],
            'group' => (string) $row['BGROUP'],
            'setting' => (string) $row['BSETTING'],
            'value' => (string) $row['BVALUE'],
        ], $rows);
    }

    public function replaceConfigValue(int $id, string $expected, string $replacement): bool
    {
        $statement = $this->connection->prepare('UPDATE BCONFIG SET BVALUE = :replacement WHERE BID = :id AND BVALUE = :expected');
        $statement->bindValue('replacement', $replacement);
        $statement->bindValue('id', $id, ParameterType::INTEGER);
        $statement->bindValue('expected', $expected);

        return 1 === $statement->executeStatement();
    }

    /**
     * @return list<array{id: int, ownerId: int, value: string}>
     */
    public function columnRows(SecretColumn $column): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf(
            "SELECT BID, %s AS OWNERID, %s AS STORED FROM %s WHERE %s IS NOT NULL AND %s <> '' ORDER BY BID",
            $column->ownerColumn(),
            $column->column(),
            $column->table(),
            $column->column(),
            $column->column(),
        ));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['BID'],
            'ownerId' => (int) $row['OWNERID'],
            'value' => (string) $row['STORED'],
        ], $rows);
    }

    public function replaceColumnValue(SecretColumn $column, int $id, string $expected, string $replacement): bool
    {
        $statement = $this->connection->prepare(sprintf(
            'UPDATE %s SET %s = :replacement WHERE BID = :id AND %s = :expected',
            $column->table(),
            $column->column(),
            $column->column(),
        ));
        $statement->bindValue('replacement', $replacement);
        $statement->bindValue('id', $id, ParameterType::INTEGER);
        $statement->bindValue('expected', $expected);

        return 1 === $statement->executeStatement();
    }
}
