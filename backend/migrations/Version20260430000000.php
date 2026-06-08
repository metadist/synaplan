<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add BKEYWORDS and BENABLED columns to BPROMPTS.
 *
 * Historic migration from the retired embedding-based routing experiment.
 * Both columns are dropped again in Version20260608000000; this migration is
 * kept so installs that already ran it stay on a consistent history.
 *
 * - BKEYWORDS: comma/newline-separated synonyms folded into the routing
 *   embedding text.
 * - BENABLED: soft-disable flag for the routing pool.
 */
final class Version20260430000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BKEYWORDS and BENABLED to BPROMPTS';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE BPROMPTS
                ADD COLUMN BKEYWORDS LONGTEXT DEFAULT NULL AFTER BSELECTION_RULES,
                ADD COLUMN BENABLED TINYINT(1) NOT NULL DEFAULT 1 AFTER BKEYWORDS
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE BPROMPTS
                DROP COLUMN BKEYWORDS,
                DROP COLUMN BENABLED
        SQL);
    }
}
