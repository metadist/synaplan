<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roll out OpenAI's 2026-07-30 GPT-5.6 price cut to existing installs.
 *
 * OpenAI cut GPT-5.6 API prices on 2026-07-30
 * (https://developers.openai.com/api/docs/pricing):
 *
 *   - Terra: 2.50 / 15.00 -> 2.00 / 12.00 per 1M tokens (-20%)
 *   - Luna:  1.00 /  6.00 -> 0.20 /  1.20 per 1M tokens (-80%)
 *   - Sol:   unchanged.
 *
 * ModelCatalog is the source of truth and ModelSeeder rolls the new values into
 * BMODELS on deploy — but only for rows still matching their last-seeded
 * fingerprint. Rows an operator edited via the admin UI are PRESERVED and never
 * auto-updated. Because these are billing corrections that must reach every
 * install (costs are resold at raw + markup, so the stale higher price
 * OVERCHARGES customers on every Terra/Luna call), this migration force-applies
 * the new base rates via explicit, idempotent UPDATEs keyed by BPROVID — the
 * same approach as Version20260713190000. Keying by BPROVID updates the chat and
 * vision rows of each model together.
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
 * @see Version20260713190000
 */
final class Version20260803120000 extends AbstractMigration
{
    /**
     * providerId => [newIn, newOut, oldIn, oldOut] (per 1M tokens).
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private const TOKEN_PRICES = [
        'gpt-5.6-terra' => [2.00, 12.00, 2.50, 15.00],
        'gpt-5.6-luna' => [0.20, 1.20, 1.00, 6.00],
    ];

    public function getDescription(): string
    {
        return "Roll out OpenAI's 2026-07-30 GPT-5.6 price cut (Terra 2.50/15 -> 2.00/12, "
            .'Luna 1.00/6 -> 0.20/1.20) to BMODELS so existing installs bill the current rates '
            .'regardless of the seeder fingerprint.';
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
