<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turn research on for existing installs.
 *
 * The seeder only inserts missing rows, so an install that already holds
 * RESEARCH.ENABLED = 0 keeps it without this update. An install without the
 * row gets it from the seeder. RESEARCH_ENABLED=0 in the environment still
 * forces research off. Raw SQL only (Galera-safe; no Schema API).
 */
final class Version20261028110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Turn RESEARCH.ENABLED on for existing installs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE BCONFIG SET BVALUE = '1' WHERE BOWNERID = 0 AND BGROUP = 'RESEARCH' AND BSETTING = 'ENABLED' AND BVALUE <> '1'");
    }

    public function down(Schema $schema): void
    {
        // The row stays on: an install that had turned research on before
        // this migration cannot be told apart from one this migration changed.
    }
}
