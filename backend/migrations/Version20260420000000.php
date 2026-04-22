<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add UNIQUE(BOWNERID, BGROUP, BSETTING) on BCONFIG.
 *
 * Background: BCONFIG is used as a generic key/value config store keyed by
 * (BOWNERID, BGROUP, BSETTING). The original schema only had a non-unique
 * lookup index, which meant idempotent seeders had to use a SELECT-then-INSERT
 * pattern that is not race-safe under concurrent container starts.
 *
 * This migration:
 *   1. Deduplicates existing rows that share the same key (keeps the highest BID,
 *      which is the most recently inserted value — matches "last write wins" semantics
 *      that already existed in BConfigSeeder).
 *   2. Replaces the non-unique idx_config_lookup with a UNIQUE index of the same
 *      shape, so it still serves point-lookups AND prevents duplicates atomically.
 *
 * After this migration, BConfigSeeder switches to `INSERT IGNORE`, which is
 * race-safe under MariaDB InnoDB.
 */
final class Version20260420000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BCONFIG: deduplicate and enforce UNIQUE(BOWNERID, BGROUP, BSETTING) for race-safe seeding';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        // Drop any pre-existing rows that share the (owner, group, setting) tuple,
        // keeping only the row with the highest BID (= most recently written value).
        // We use a self-join that compares against an aggregate to stay portable across
        // MariaDB versions (no CTE / DELETE LIMIT … ORDER BY tricks).
        $this->addSql(<<<'SQL'
            DELETE c1 FROM BCONFIG c1
            INNER JOIN (
                SELECT BOWNERID, BGROUP, BSETTING, MAX(BID) AS keep_bid
                FROM BCONFIG
                GROUP BY BOWNERID, BGROUP, BSETTING
                HAVING COUNT(*) > 1
            ) c2
                ON c1.BOWNERID = c2.BOWNERID
                AND c1.BGROUP   = c2.BGROUP
                AND c1.BSETTING = c2.BSETTING
                AND c1.BID      < c2.keep_bid
        SQL);

        $this->addSql('DROP INDEX idx_config_lookup ON BCONFIG');
        $this->addSql('CREATE UNIQUE INDEX uniq_config_owner_group_setting ON BCONFIG (BOWNERID, BGROUP, BSETTING)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_config_owner_group_setting ON BCONFIG');
        $this->addSql('CREATE INDEX idx_config_lookup ON BCONFIG (BOWNERID, BGROUP, BSETTING)');
    }
}
