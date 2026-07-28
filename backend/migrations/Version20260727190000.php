<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix two Ollama rows that carry a non-zero output price under the "free" unit.
 *
 * BID 3 (`deepseek-r1:32b`, 0.91) and BID 6 (`mistral:7b`, 0.475) store BOUTUNIT = '-'
 * while their input side is authored `per1M`. {@see \App\Service\CostCalculationService::normaliseToPerUnit()}
 * maps '-' (like '' and 'free') to 0.0, so every output token on these two models has
 * been billed at zero while input was charged normally — an inconsistency, not an
 * intentional free tier: every other Ollama row prices both sides `per1M`.
 *
 * Only the unit is corrected. The price values are left untouched: Ollama models are
 * operator-hosted, so BPRICEIN/BPRICEOUT are a synthetic resale basis rather than an
 * upstream invoice, and inventing new numbers here would be a pricing decision, not a
 * bug fix.
 *
 * Idempotent: the BOUTUNIT = '-' guard makes a re-run a no-op, and it leaves alone any
 * row an operator has since re-authored. Operator-owned columns (BSELECTABLE, BACTIVE,
 * BISDEFAULT, BSHOWWHENFREE) are not touched — availability is unchanged.
 *
 * Note: `per_generation` (Higgsfield video, BIDs 302–308) is NOT part of this class of
 * bug. normaliseToPerUnit() handles it explicitly as a flat per-clip fee.
 */
final class Version20260727190000 extends AbstractMigration
{
    /** BID => upstream model id, used as an extra guard. */
    private const MISPRICED_UNIT_MODELS = [
        3 => 'deepseek-r1:32b',
        6 => 'mistral:7b',
    ];

    public function getDescription(): string
    {
        return "Set BOUTUNIT = 'per1M' on the Ollama rows BID 3 and 6, whose non-zero output price was billed at zero under the '-' unit.";
    }

    public function up(Schema $schema): void
    {
        foreach (self::MISPRICED_UNIT_MODELS as $bid => $providerId) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BOUTUNIT = 'per1M'
                 WHERE BID = :bid
                   AND BPROVID = :providerId
                   AND BOUTUNIT = '-'
            SQL, [
                'bid' => $bid,
                'providerId' => $providerId,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::MISPRICED_UNIT_MODELS as $bid => $providerId) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BOUTUNIT = '-'
                 WHERE BID = :bid
                   AND BPROVID = :providerId
                   AND BOUTUNIT = 'per1M'
            SQL, [
                'bid' => $bid,
                'providerId' => $providerId,
            ]);
        }
    }
}
