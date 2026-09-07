<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agent Builder S1: BAGENTS + BAGENTVERSIONS.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md).
 *
 * Version id is 20260907220000 so it does not collide with
 * Version20260907120000 (Veo 3.1 Fast price correction on main).
 */
final class Version20260907220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BAGENTS and BAGENTVERSIONS (Agent Builder S1)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BAGENTS (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BOWNERID BIGINT NOT NULL,
              BPROMPTID BIGINT NOT NULL,
              BSLUG VARCHAR(96) NOT NULL,
              BNAME VARCHAR(128) NOT NULL,
              BDESCRIPTION TEXT NULL,
              BICON VARCHAR(64) NOT NULL DEFAULT '',
              BSTATUS VARCHAR(16) NOT NULL DEFAULT 'draft',
              BDRAFT JSON NOT NULL,
              BPUBLISHEDVERSIONID BIGINT NULL,
              BPARENTID BIGINT NULL,
              BSOURCE VARCHAR(64) NOT NULL DEFAULT 'manual',
              BROUTABLE TINYINT(1) NOT NULL DEFAULT 0,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uq_agents_owner_slug (BOWNERID, BSLUG),
              KEY idx_agents_owner (BOWNERID),
              KEY idx_agents_prompt (BPROMPTID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BAGENTVERSIONS (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BAGENTID BIGINT NOT NULL,
              BVERSION INT NOT NULL,
              BDEFINITION JSON NOT NULL,
              BPROMPTTEXT MEDIUMTEXT NOT NULL,
              BCHANGELOG TEXT NULL,
              BPUBLISHEDBY BIGINT NOT NULL,
              BCREATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uq_agentversions_agent_version (BAGENTID, BVERSION)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BAGENTVERSIONS');
        $this->addSql('DROP TABLE IF EXISTS BAGENTS');
    }
}
