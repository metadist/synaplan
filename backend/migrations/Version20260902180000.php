<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Structured document revisions. Galera-safe: raw addSql only.
 */
final class Version20260902180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BDOCUMENT_REVISIONS for structured office editing (Phase B)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BDOCUMENT_REVISIONS (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BFILEID BIGINT NOT NULL,
                BUSERID BIGINT NOT NULL,
                BVERSION INT NOT NULL,
                BSCHEMAVERSION INT NOT NULL DEFAULT 1,
                BMODEL LONGTEXT NOT NULL,
                BSUMMARY TEXT NOT NULL,
                BSOURCE VARCHAR(16) NOT NULL DEFAULT 'model',
                BBINARYSHA VARCHAR(64) DEFAULT NULL,
                BCREATED BIGINT NOT NULL,
                PRIMARY KEY (BID),
                INDEX idx_docrev_file_version (BFILEID, BVERSION),
                INDEX idx_docrev_user (BUSERID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BDOCUMENT_REVISIONS');
    }
}
