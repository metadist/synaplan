<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converge the text2sound (TTS) models to per_character billing.
 *
 * Three TTS models were seeded with chat-style per-1M-token pricing and no
 * pricing_mode (Gemini 2.5 / 3.1 Flash TTS) or an explicit per_token mode with
 * a per-1M price mislabelled as characters (Voxtral). Because
 * CostCalculationService::getPricingMode() treats a missing mode as per_token
 * and the TTS path only records the input character count (never audio token
 * usage), all three billed $0 in BUSELOG.
 *
 * The TTS billing path meters INPUT characters (media_usage['characters']), so
 * the correct shape is per_character on BPRICEIN (matching OpenAI tts-1 /
 * tts-1-hd). Provider list prices are token-based for Google, so they are
 * converted to an effective per-input-character rate using each model's
 * audio-token rate and an assumed ~15 chars/sec speech rate:
 *
 *   gemini-2.5-flash-preview-tts  $10/1M audio tok, 32 tok/s -> ~$0.000022/char
 *   gemini-3.1-flash-tts-preview  $20/1M audio tok, 25 tok/s -> ~$0.0000336/char
 *   voxtral-mini-tts-2603         $16/1M characters (direct) ->  $0.000016/char
 *
 * (Piper is self-hosted/free and OpenAI tts-1/tts-1-hd are already correct, so
 * they are not touched.)
 *
 * Idempotent and guarded by BPROVID. Operator-owned columns (BSELECTABLE,
 * BACTIVE, BISDEFAULT) are never touched, per the BMODELS catalog-vs-operator
 * contract in AGENTS.md. Uses only raw addSql() with no Schema API, so it is
 * safe on the shared MariaDB Galera cluster.
 *
 * @see \App\Model\ModelCatalog
 * @see \App\Service\CostCalculationService::calculateMediaCost()
 */
final class Version20260712140000 extends AbstractMigration
{
    /**
     * providerId => effective per-input-character price.
     *
     * @var array<string, float>
     */
    private const TTS_MODEL_PRICES = [
        'gemini-2.5-flash-preview-tts' => 0.000022,
        'gemini-3.1-flash-tts-preview' => 0.0000336,
        'voxtral-mini-tts-2603' => 0.000016,
    ];

    /**
     * Previous per-1M shape, used to reverse the migration.
     *
     * providerId => [priceIn, priceOut, outUnit].
     *
     * @var array<string, array{float, float, string}>
     */
    private const PREVIOUS_SHAPE = [
        'gemini-2.5-flash-preview-tts' => [0.1, 0.4, 'per1M'],
        'gemini-3.1-flash-tts-preview' => [0.1, 0.4, 'per1M'],
        'voxtral-mini-tts-2603' => [16.0, 0.0, '-'],
    ];

    public function getDescription(): string
    {
        return 'Bill Gemini/Voxtral TTS models per_character so the taximeter records a real '
            .'cost for speech generation instead of $0 (converges chat-style per-token TTS rows).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TTS_MODEL_PRICES as $provid => $price) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BPRICEIN  = :price,
                       BINUNIT   = 'perChar',
                       BPRICEOUT = 0,
                       BOUTUNIT  = 'perChar',
                       BJSON     = JSON_SET(
                                       COALESCE(BJSON, '{}'),
                                       '$.pricing_mode', 'per_character',
                                       '$.mode_prices', JSON_OBJECT('input_cost_per_character', :price)
                                   )
                 WHERE BPROVID = :provid
                SQL, [
                'price' => $price,
                'provid' => $provid,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::PREVIOUS_SHAPE as $provid => [$priceIn, $priceOut, $outUnit]) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BPRICEIN  = :price_in,
                       BINUNIT   = 'per1M',
                       BPRICEOUT = :price_out,
                       BOUTUNIT  = :out_unit,
                       BJSON     = JSON_REMOVE(
                                       JSON_REMOVE(COALESCE(BJSON, '{}'), '$.mode_prices'),
                                       '$.pricing_mode'
                                   )
                 WHERE BPROVID = :provid
                SQL, [
                'price_in' => $priceIn,
                'price_out' => $priceOut,
                'out_unit' => $outUnit,
                'provid' => $provid,
            ]);
        }
    }
}
