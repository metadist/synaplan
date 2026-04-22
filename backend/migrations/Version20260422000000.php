<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop unused BRATELIMITS_CONFIG table.
 *
 * Background: The table was created for an in-memory-cache + DB-config rate
 * limiter (`App\Service\RateLimiterService` + `RateLimitConfigRepository` +
 * `RateLimitConfig` entity). That service was registered in `services.yaml`
 * but never injected anywhere — only referenced as "future work" in
 * `_devextras/planning/outlook-plugin-evaluation.md`. The active limiter is
 * `App\Service\RateLimitService`, which reads `RATELIMITS_*` rows from
 * `BCONFIG` and is fully covered by `RateLimitConfigSeeder`.
 *
 * The table has been empty in every environment since it was created, so
 * dropping it is non-destructive. The matching entity, repository and service
 * are removed in the same commit.
 *
 * The down() migration recreates the table verbatim from the baseline schema
 * (Version20260417000000) so a rollback restores the prior structure exactly,
 * even though it has no callers anymore.
 */
final class Version20260422000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused BRATELIMITS_CONFIG table (dead rate-limiter feature, never wired in)';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BRATELIMITS_CONFIG');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE BRATELIMITS_CONFIG (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BSCOPE VARCHAR(64) NOT NULL,
              BPLAN VARCHAR(32) NOT NULL,
              BLIMIT INT NOT NULL,
              BWINDOW INT NOT NULL,
              BDESCRIPTION LONGTEXT NOT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              INDEX idx_ratelimit_scope (BSCOPE),
              INDEX idx_ratelimit_plan (BPLAN),
              UNIQUE INDEX unique_scope_plan (BSCOPE, BPLAN),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }
}
