<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content moderation MVP (Apple App Review Guideline 1.2).
 *
 * - Adds an account-status column to BUSER so abusive users can be
 *   suspended/banned (gated in the security UserChecker + login).
 * - Creates BCONTENT_REPORTS to persist user reports of objectionable content.
 *
 * Galera-safe: no Schema API introspection, only idempotent raw SQL
 * (ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS), no FKs to avoid
 * cross-node comparator issues and parent/child delete ordering.
 */
final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Content moderation: BUSER.BACCOUNTSTATUS + BCONTENT_REPORTS table';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE BUSER ADD COLUMN IF NOT EXISTS BACCOUNTSTATUS VARCHAR(16) DEFAULT 'active' NOT NULL AFTER BUSERLEVEL"
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BCONTENT_REPORTS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BREPORTERID BIGINT NOT NULL,
              BCONTENTTYPE VARCHAR(24) NOT NULL,
              BCONTENTID BIGINT NOT NULL,
              BREPORTEDUSERID BIGINT DEFAULT NULL,
              BREASON VARCHAR(48) NOT NULL,
              BDETAILS TEXT DEFAULT NULL,
              BSTATUS VARCHAR(24) DEFAULT 'open' NOT NULL,
              BCREATED VARCHAR(20) NOT NULL,
              BREVIEWEDBY BIGINT DEFAULT NULL,
              BREVIEWEDAT VARCHAR(20) DEFAULT NULL,
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_CONTENT_REPORT_STATUS ON BCONTENT_REPORTS (BSTATUS)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_CONTENT_REPORT_REPORTER ON BCONTENT_REPORTS (BREPORTERID)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_CONTENT_REPORT_REPORTED_USER ON BCONTENT_REPORTS (BREPORTEDUSERID)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BCONTENT_REPORTS');
        $this->addSql('ALTER TABLE BUSER DROP COLUMN IF EXISTS BACCOUNTSTATUS');
    }
}
