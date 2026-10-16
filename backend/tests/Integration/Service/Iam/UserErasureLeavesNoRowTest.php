<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Entity\User;
use App\Service\Iam\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deleting an account leaves no row with its id behind. Every user-owned
 * table ({@see UserOwnedTables}) gets one row for the account, written
 * straight from its Doctrine mapping, so a table added later is seeded
 * without editing this test.
 */
final class UserErasureLeavesNoRowTest extends KernelTestCase
{
    private const PLACEHOLDER_DATETIME = '2026-01-01 00:00:00';
    private const PLACEHOLDER_DATE = '2026-01-01';
    private const DEFAULT_TEXT_LENGTH = 32;

    public function testADeletedAccountLeavesNoRowInAnyUserOwnedTable(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->persistUser($em);
        $userId = (int) $user->getId();
        $tables = UserOwnedTables::in($em);
        $this->seedOneRowPerTable($em, $tables, $userId);

        static::getContainer()->get(UserDeletionService::class)->deleteUser($user);

        $left = [];
        foreach ($tables as $class => $ownerColumn) {
            if ($this->countRows($em->getConnection(), $em->getClassMetadata($class)->getTableName(), $ownerColumn, $userId) > 0) {
                $left[] = $class;
            }
        }
        $expected = UserErasureCoverageTest::NOT_ERASED_YET;
        sort($expected);

        self::assertSame($expected, $left, 'These user-owned tables still hold a row of the deleted account.');
    }

    private function persistUser(EntityManagerInterface $em): User
    {
        $user = new User();
        $user->setMail('erasure-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setProviderId('local');
        $user->setPw('dummy_hash');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(true);
        $user->setCreated(date('YmdHis'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Foreign keys are off while seeding, so a required reference to another
     * table may point nowhere; the deletion itself runs with them on.
     *
     * @param array<class-string, string> $tables
     */
    private function seedOneRowPerTable(EntityManagerInterface $em, array $tables, int $userId): void
    {
        $db = $em->getConnection();
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $class => $ownerColumn) {
                $this->insertRow($db, $em->getClassMetadata($class), $ownerColumn, $userId);
            }
        } finally {
            $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The user id in the owner column, a placeholder in every other required
     * column, and the database default everywhere else.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function insertRow(Connection $db, ClassMetadata $metadata, string $ownerColumn, int $userId): void
    {
        $values = [$ownerColumn => $userId];
        $expressions = [];
        foreach ($metadata->getFieldNames() as $field) {
            $mapping = $metadata->getFieldMapping($field);
            $generatedId = $metadata->isIdentifier($field) && $metadata->usesIdGenerator();
            if ($generatedId || isset($values[$mapping->columnName]) || true === $mapping->nullable || isset($mapping->options['default'])) {
                continue;
            }
            $values[$mapping->columnName] = self::placeholder($mapping->type, $mapping->length);
            if ('vector' === $mapping->type) {
                $expressions[$mapping->columnName] = 'VEC_FromText(?)';
            }
        }
        foreach ($metadata->getAssociationMappings() as $association) {
            if (!$association->isToOneOwningSide()) {
                continue;
            }
            foreach ($association->joinColumns as $joinColumn) {
                if (!isset($values[$joinColumn->name]) && false === $joinColumn->nullable) {
                    $values[$joinColumn->name] = 0;
                }
            }
        }

        $columns = array_keys($values);
        $db->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $metadata->getTableName(),
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $column): string => $expressions[$column] ?? '?', $columns)),
            ),
            array_values($values),
        );
    }

    private static function placeholder(string $type, ?int $length): int|string
    {
        return match ($type) {
            'integer', 'smallint', 'bigint', 'boolean', 'float', 'decimal' => 0,
            'string', 'text', 'ascii_string', 'guid' => substr(bin2hex(random_bytes(16)), 0, $length ?? self::DEFAULT_TEXT_LENGTH),
            'json' => '[]',
            'datetime', 'datetime_immutable' => self::PLACEHOLDER_DATETIME,
            'date', 'date_immutable' => self::PLACEHOLDER_DATE,
            'vector' => '['.implode(',', array_fill(0, $length ?? 1024, '0')).']',
            default => throw new \LogicException(sprintf('No placeholder for column type "%s".', $type)),
        };
    }

    private function countRows(Connection $db, string $table, string $ownerColumn, int $userId): int
    {
        return (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $ownerColumn), [$userId]);
    }
}
