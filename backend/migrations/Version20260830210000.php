<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create BDESKTOPJOBS — the out-of-band job queue a paired computer leases over
 * MCP check-in and reports results for (Sprint A3, DS11).
 *
 * v1 carries one job type only (`skill.run`) for a skill the device has
 * installed and the user enabled. The closed enum and the "ignore extra keys"
 * rule are the reason a server bug can never become remote code execution — the
 * device only ever executes `{skill, prompt, fileIds}`.
 *
 * BDEVICEID is nullable: NULL means "any of the user's devices". There is no
 * ON DELETE CASCADE (Galera FK limits), so a future delete-devices migration
 * must remove the matching job rows first.
 *
 * Galera-safe: raw idempotent DDL only, no Schema API (AGENTS.md).
 */
final class Version20260830210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BDESKTOPJOBS table for the Synaplan Desktop skill.run job queue';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS BDESKTOPJOBS (
                    BID BIGINT NOT NULL AUTO_INCREMENT,
                    BOWNERID BIGINT NOT NULL,
                    BDEVICEID BIGINT NULL,
                    BTYPE VARCHAR(32) NOT NULL DEFAULT 'skill.run',
                    BINPUT JSON NULL,
                    BSTATUS VARCHAR(16) NOT NULL DEFAULT 'queued',
                    BLEASETOKEN VARCHAR(64) NULL,
                    BLEASEEXPIRES BIGINT NOT NULL DEFAULT 0,
                    BATTEMPT INT NOT NULL DEFAULT 0,
                    BMAXATTEMPTS INT NOT NULL DEFAULT 3,
                    BIDEMPOTENCY VARCHAR(128) NULL,
                    BRESULT JSON NULL,
                    BERRORCODE VARCHAR(32) NULL,
                    BCHATID BIGINT NULL,
                    BMESSAGEID BIGINT NULL,
                    BCREATED BIGINT NOT NULL,
                    BUPDATED BIGINT NOT NULL DEFAULT 0,
                    PRIMARY KEY (BID),
                    UNIQUE KEY uniq_desktop_job_idem (BOWNERID, BIDEMPOTENCY),
                    KEY idx_desktop_job_owner (BOWNERID),
                    KEY idx_desktop_job_device (BDEVICEID),
                    KEY idx_desktop_job_lease (BSTATUS, BDEVICEID)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BDESKTOPJOBS');
    }
}
