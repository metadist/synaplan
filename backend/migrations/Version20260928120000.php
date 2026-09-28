<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make BUSER.BMAIL unique so two accounts cannot take the same sign-in email.
 *
 * The column collation is utf8mb4_unicode_ci, so the unique index is
 * case-insensitive and matches the application check on LOWER(BMAIL).
 * The previous BMAIL index was not unique, which let two concurrent profile
 * saves both pass the availability query.
 *
 * Idempotent and Galera-safe: raw SQL, no Schema API. Refuses to continue
 * when duplicate addresses already exist, because deleting one of those
 * accounts from a migration would drop a real person.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce a unique sign-in email on BUSER.BMAIL';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $hasUnique = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'BUSER'
               AND INDEX_NAME = 'UNIQ_BUSER_BMAIL'
               AND NON_UNIQUE = 0"
        );
        if (0 === $hasUnique) {
            $duplicates = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM (
                    SELECT BMAIL FROM BUSER GROUP BY BMAIL HAVING COUNT(*) > 1
                ) duplicates'
            );
            $this->abortIf(
                $duplicates > 0,
                'BUSER.BMAIL has duplicate values. Resolve them before adding UNIQ_BUSER_BMAIL.',
            );
            $this->addSql('CREATE UNIQUE INDEX UNIQ_BUSER_BMAIL ON BUSER (BMAIL)');
        }

        $this->addSql('DROP INDEX IF EXISTS BMAIL ON BUSER');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_BUSER_BMAIL ON BUSER');
        $this->addSql('CREATE INDEX IF NOT EXISTS BMAIL ON BUSER (BMAIL)');
    }
}
