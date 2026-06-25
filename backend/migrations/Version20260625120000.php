<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the per-plan MAX_OUTPUT_TOKENS cap for authenticated tiers.
 *
 * Background: the ChatHandler used to clamp a model's output to
 * min(plan_limit, model_max), which truncated long answers at the plan's
 * MAX_OUTPUT_TOKENS value. We now let every authenticated tier
 * (NEW/PRO/TEAM/BUSINESS) use the selected model's full max_tokens; spend is
 * bounded by the cost-budget gate (registered users) and message-count limits
 * instead. Only ANONYMOUS keeps a hard output cap.
 *
 * BCONFIG seeder defaults are bootstrap-only (INSERT IGNORE), so removing the
 * rows from {@see \App\Seed\RateLimitConfigSeeder} does NOT propagate to existing
 * installs. This migration explicitly deletes the legacy rows so the new
 * behaviour rolls out everywhere. The ANONYMOUS row is intentionally left intact.
 *
 * Pure, idempotent addSql (no Schema introspection) — safe on the prod Galera
 * cluster (see AGENTS_DEV.md "Production Platform Specifics").
 */
final class Version20260625120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove per-plan MAX_OUTPUT_TOKENS for NEW/PRO/TEAM/BUSINESS so authenticated tiers use the model full max_tokens (ANONYMOUS cap retained).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM BCONFIG
             WHERE BOWNERID = 0
               AND BSETTING = 'MAX_OUTPUT_TOKENS'
               AND BGROUP IN (
                   'RATELIMITS_NEW',
                   'RATELIMITS_PRO',
                   'RATELIMITS_TEAM',
                   'RATELIMITS_BUSINESS'
               )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Restore the legacy caps. INSERT IGNORE is race-safe against the
        // uniq_config_owner_group_setting UNIQUE index and a no-op if an
        // operator has already re-created the rows.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
            VALUES
                (0, 'RATELIMITS_NEW',      'MAX_OUTPUT_TOKENS', '4096'),
                (0, 'RATELIMITS_PRO',      'MAX_OUTPUT_TOKENS', '16384'),
                (0, 'RATELIMITS_TEAM',     'MAX_OUTPUT_TOKENS', '32768'),
                (0, 'RATELIMITS_BUSINESS', 'MAX_OUTPUT_TOKENS', '65536')
        SQL);
    }
}
