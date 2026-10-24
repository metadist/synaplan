<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What an instance reports about its app, whether Synaplan can reach it,
 * which private hostnames an administrator approved for delivery, and the
 * cloud's own instance id (CF2.1).
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add instance app version, reachability, OIDC client, approved hosts and server id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BREACHABLE TINYINT DEFAULT NULL');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BOIDCCLIENTID VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BAPPVERSION VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BAPPROVEDHOSTS JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES ADD COLUMN IF NOT EXISTS BSERVERID VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BSERVERID');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BAPPROVEDHOSTS');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BAPPVERSION');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BOIDCCLIENTID');
        $this->addSql('ALTER TABLE BPLATFORMINSTANCES DROP COLUMN IF EXISTS BREACHABLE');
    }
}
