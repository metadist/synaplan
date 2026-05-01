<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add BKEYWORDS and BENABLED columns to BPROMPTS for Synapse Routing v2.
 *
 * - BKEYWORDS: comma/newline-separated synonyms that get folded into the
 *   embedding text used by SynapseIndexer. Improves recall without bloating
 *   the AI fallback sort prompt.
 * - BENABLED: soft-disable flag. Disabled topics are filtered out of the
 *   routing pool (both Synapse embedding search and AI sort DYNAMICLIST)
 *   while existing message history that references them remains valid.
 */
final class Version20260430000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BKEYWORDS and BENABLED to BPROMPTS for Synapse Routing v2';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE BPROMPTS
                ADD COLUMN BKEYWORDS LONGTEXT NULL AFTER BSELECTION_RULES,
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
