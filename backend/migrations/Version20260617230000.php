<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create BUSER_TOPUPS: one-time prepaid budget top-ups (e.g. EUR 100 steps).
 *
 * A top-up raises a user's cost budget for the billing period it falls into
 * (see RateLimitService::checkCostBudget). The Stripe checkout session id is
 * unique so webhook retries cannot credit the same purchase twice.
 *
 * Raw, comparator-free SQL with IF NOT EXISTS — matches the repo's MariaDB-safe
 * migration style (the Schema arg is intentionally untouched).
 */
final class Version20260617230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BUSER_TOPUPS table for one-time prepaid cost-budget top-ups';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS BUSER_TOPUPS (
                BID BIGINT AUTO_INCREMENT NOT NULL,
                BUSERID BIGINT NOT NULL,
                BAMOUNT NUMERIC(10, 2) DEFAULT '0.00' NOT NULL,
                BCURRENCY VARCHAR(8) DEFAULT 'EUR' NOT NULL,
                BSTRIPE_SESSION_ID VARCHAR(255) DEFAULT NULL,
                BSTATUS VARCHAR(32) DEFAULT 'completed' NOT NULL,
                BCREATED BIGINT NOT NULL,
                INDEX idx_topup_user (BUSERID),
                INDEX idx_topup_created (BCREATED),
                UNIQUE INDEX uniq_topup_session (BSTRIPE_SESSION_ID),
                PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BUSER_TOPUPS');
    }
}
