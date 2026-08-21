<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds BUSER.BMUSTCHANGEPW — the one-time-password flag.
 *
 * Deployments that generate an admin password per instance (the AWS Marketplace
 * AMI does) must force that password to be replaced at first sign-in. Existing
 * accounts default to 0, so nothing changes for installs where a human chose
 * the password.
 *
 * Galera-safe: raw ADD COLUMN IF NOT EXISTS, no Schema API introspection.
 */
final class Version20260811090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BUSER.BMUSTCHANGEPW to force a password change after a deployment-generated password.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE BUSER ADD COLUMN IF NOT EXISTS BMUSTCHANGEPW TINYINT(1) NOT NULL DEFAULT 0 AFTER BEMAILVERIFIED',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BUSER DROP COLUMN IF EXISTS BMUSTCHANGEPW');
    }
}
