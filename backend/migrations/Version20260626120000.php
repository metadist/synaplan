<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add BJOBKEY to BMESSAGE_TASKS — links a persisted task card to its Redis
 * {@see \App\Service\Media\MediaJob} while a render is still in flight
 * (Release 4.0 Feature 1 Sprint B). Additive only; nullable.
 */
final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BJOBKEY to BMESSAGE_TASKS for async media job relink on reload';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMESSAGE_TASKS ADD COLUMN IF NOT EXISTS BJOBKEY VARCHAR(64) DEFAULT NULL AFTER BMODELID');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BMESSAGE_TASKS DROP COLUMN IF EXISTS BJOBKEY');
    }
}
