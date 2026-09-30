<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Smart Search index: one row per searchable item a user owns (chats, files,
 * widgets, assistants, saved tasks) plus the global catalog (BUSERID = 0).
 * FULLTEXT serves the keyword tier; BEMBED serves the semantic tier.
 *
 * BEMBED stays nullable and carries no VECTOR INDEX: MariaDB only indexes
 * NOT NULL vector columns, and every query filters by BUSERID first, so the
 * distance runs over one user's rows. BEMBEDMODELID records which model wrote
 * the vector, so an embedding switch can re-index without a gap. BHASH skips
 * re-embedding rows whose text did not change.
 *
 * Galera-safe: raw addSql only, CREATE TABLE IF NOT EXISTS, no Schema API,
 * no foreign keys (see docs/MIGRATIONS.md). No Doctrine entity; listed in
 * app.doctrine.schema_filter.
 */
final class Version20260930100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BSEARCHINDEX (Smart Search keyword + vector index)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BSEARCHINDEX (
              BID BIGINT NOT NULL AUTO_INCREMENT,
              BUSERID BIGINT NOT NULL,
              BKIND VARCHAR(16) NOT NULL,
              BREFID VARCHAR(96) NOT NULL,
              BTITLE VARCHAR(255) NOT NULL,
              BBODY TEXT NOT NULL,
              BLANG VARCHAR(8) NULL,
              BHASH CHAR(40) NOT NULL,
              BEMBED VECTOR(1024) NULL,
              BEMBEDMODELID BIGINT NULL,
              BUPDATED BIGINT NOT NULL,
              PRIMARY KEY (BID),
              UNIQUE KEY uq_searchindex_ref (BUSERID, BKIND, BREFID),
              INDEX idx_searchindex_user_kind (BUSERID, BKIND),
              INDEX idx_searchindex_embedmodel (BEMBEDMODELID),
              FULLTEXT KEY ft_searchindex_title (BTITLE),
              FULLTEXT KEY ft_searchindex_all (BTITLE, BBODY)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BSEARCHINDEX');
    }
}
