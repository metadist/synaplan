<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pin a project to the top of the sidebar list: BPROJECTS.BPINNEDAT is the
 * time it was pinned, NULL while it is not.
 *
 * Idempotent: ADD / DROP COLUMN with IF [NOT] EXISTS.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BPROJECTS.BPINNEDAT (pinned projects)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPROJECTS ADD COLUMN IF NOT EXISTS BPINNEDAT DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPROJECTS DROP COLUMN IF EXISTS BPINNEDAT');
    }
}
