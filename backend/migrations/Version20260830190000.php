<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roll out OpenAI's 2026-08-21 GPT-5.6 Sol price cut to existing installs (#1561).
 *
 * OpenAI cut GPT-5.6 Sol API prices on 2026-08-21, verified against the official
 * OpenAI pricing page (https://openai.com/api/pricing/) on 2026-08-30:
 *
 *   - Sol: 5.00 / 30.00 -> 4.00 / 20.00 per 1M tokens (input -20%, output -33%).
 *   - Cached input 0.50 -> 0.40; long-context (>272k) 10/45 -> 8/30.
 *   - Terra, Luna and the other GPT-5.x rows are unchanged.
 *
 * OpenAI marks the new card promotional "at least through 2026-11-21"; the
 * reminder to re-verify a possible revert lives in docs/PRICING_MAINTENANCE.md.
 *
 * ModelCatalog is the source of truth and ModelSeeder rolls the new values into
 * BMODELS on deploy — but only for rows still matching their last-seeded
 * fingerprint. Rows an operator edited via the admin UI are PRESERVED and never
 * auto-updated. Because these are billing corrections that must reach every
 * install (costs are resold at raw + markup, so the stale higher price
 * OVERCHARGES customers on every Sol call), this migration force-applies the new
 * base rates via explicit, idempotent UPDATEs keyed by BPROVID — the same
 * approach as Version20260803120000. Keying by BPROVID updates the chat and
 * vision rows together.
 *
 * The long-context (>272k) tier lives only in ModelCatalog::CONTEXT_PRICING and
 * is read from the current catalog at billing time, so the tier update ships
 * with the deploy and needs no migration.
 *
 * Operator-owned columns (BSELECTABLE, BACTIVE, BISDEFAULT, BSHOWWHENFREE) are
 * never touched. Idempotent (fixed UPDATEs re-run to the same result) and
 * raw-SQL only (no Schema API), so it is safe on the shared MariaDB Galera
 * cluster.
 *
 * @see \App\Model\ModelCatalog
 * @see Version20260803120000
 */
final class Version20260830190000 extends AbstractMigration
{
    /**
     * providerId => [newIn, newOut, oldIn, oldOut] (per 1M tokens).
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private const TOKEN_PRICES = [
        'gpt-5.6-sol' => [4.00, 20.00, 5.00, 30.00],
    ];

    public function getDescription(): string
    {
        return "Roll out OpenAI's 2026-08-21 GPT-5.6 Sol price cut (5/30 -> 4/20) to BMODELS so "
            .'existing installs bill the current rate regardless of the seeder fingerprint (#1561).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TOKEN_PRICES as $provid => [$in, $out]) {
            $this->addSql(
                'UPDATE BMODELS SET BPRICEIN = :in, BPRICEOUT = :out WHERE BPROVID = :provid',
                ['in' => $in, 'out' => $out, 'provid' => $provid]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TOKEN_PRICES as $provid => [, , $oldIn, $oldOut]) {
            $this->addSql(
                'UPDATE BMODELS SET BPRICEIN = :in, BPRICEOUT = :out WHERE BPROVID = :provid',
                ['in' => $oldIn, 'out' => $oldOut, 'provid' => $provid]
            );
        }
    }
}
