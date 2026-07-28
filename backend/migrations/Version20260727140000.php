<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persist user memories in MariaDB so Qdrant is a rebuildable vector index.
 *
 * Galera-safe: raw idempotent SQL only, without Schema API access.
 */
final class Version20260727140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the authoritative BUSERMEMORIES store for user memories';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS BUSERMEMORIES (
                    BID BIGINT NOT NULL,
                    BUSERID INT NOT NULL,
                    BCATEGORY VARCHAR(100) NOT NULL,
                    BKEY VARCHAR(255) NOT NULL,
                    BVALUE LONGTEXT NOT NULL,
                    BSOURCE VARCHAR(32) NOT NULL,
                    BMESSAGEID BIGINT DEFAULT NULL,
                    BNAMESPACE VARCHAR(100) DEFAULT NULL,
                    BACTIVE TINYINT(1) NOT NULL DEFAULT 1,
                    BCREATED BIGINT NOT NULL,
                    BUPDATED BIGINT NOT NULL,
                    PRIMARY KEY (BID),
                    INDEX idx_memory_user_active_updated (BUSERID, BACTIVE, BUPDATED),
                    INDEX idx_memory_user_category (BUSERID, BCATEGORY)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BUSERMEMORIES');
    }
}
