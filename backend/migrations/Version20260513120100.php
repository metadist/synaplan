<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Align OpenAI tts-1 (BID 41) and tts-1-hd (BID 83) catalog shape with
 * live production.
 *
 * Both rows previously authored their input price as
 * `priceIn=0.015 / 0.03` in `per1000chars` units with no `pricing_mode`
 * flag in `BJSON`. That made `CostCalculationService::calculateMediaCost()`
 * fall through to the per-token path and silently bill $0.000000 even
 * though `MediaGenerationHandler` was passing `media_usage['characters']`.
 * Live production (`_devextras/db-init/20260513_BMODELS.sql`) carries the
 * normalised per-character shape:
 *   tts-1     priceIn=0.000015  / inUnit=perChar / pricing_mode=per_character
 *   tts-1-hd  priceIn=0.00003   / inUnit=perChar / pricing_mode=per_character
 *
 * This migration force-rolls those values out to existing developer /
 * staging databases. Idempotent: re-running is a no-op once the rows
 * already match. Operator-owned columns (BSELECTABLE, BACTIVE,
 * BISDEFAULT) are deliberately NOT touched.
 */
final class Version20260513120100 extends AbstractMigration
{
    private const TTS1_BID = 41;
    private const TTS1_PROVID = 'tts-1';
    private const TTS1_PRICE_PER_CHAR = 0.000015;

    private const TTS1HD_BID = 83;
    private const TTS1HD_PROVID = 'tts-1-hd';
    private const TTS1HD_PRICE_PER_CHAR = 0.00003;

    public function getDescription(): string
    {
        return 'Align OpenAI tts-1 (BID 41) and tts-1-hd (BID 83) catalog '
            .'with live production: priceIn in perChar units and BJSON.pricing_mode=per_character.';
    }

    public function up(Schema $schema): void
    {
        // tts-1: $0.015 per 1000 chars → $0.000015 per char.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = :price_per_char,
                   BINUNIT   = 'perChar',
                   BPRICEOUT = 0,
                   BOUTUNIT  = 'perChar',
                   BJSON     = JSON_SET(
                                   COALESCE(BJSON, '{}'),
                                   '$.pricing_mode', 'per_character',
                                   '$.mode_prices.input_cost_per_character', :price_per_char
                               )
             WHERE BID = :bid
               AND BPROVID = :provid
            SQL, [
            'bid' => self::TTS1_BID,
            'provid' => self::TTS1_PROVID,
            'price_per_char' => self::TTS1_PRICE_PER_CHAR,
        ]);

        // tts-1-hd: $0.03 per 1000 chars → $0.00003 per char.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = :price_per_char,
                   BINUNIT   = 'perChar',
                   BPRICEOUT = 0,
                   BOUTUNIT  = 'perChar',
                   BJSON     = JSON_SET(
                                   COALESCE(BJSON, '{}'),
                                   '$.pricing_mode', 'per_character',
                                   '$.mode_prices.input_cost_per_character', :price_per_char
                               )
             WHERE BID = :bid
               AND BPROVID = :provid
            SQL, [
            'bid' => self::TTS1HD_BID,
            'provid' => self::TTS1HD_PROVID,
            'price_per_char' => self::TTS1HD_PRICE_PER_CHAR,
        ]);
    }

    public function down(Schema $schema): void
    {
        // Restore the pre-fix per-1000-char shape with no pricing_mode flag,
        // matching the catalog default the codebase shipped before this PR.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 0.015,
                   BINUNIT   = 'per1000chars',
                   BPRICEOUT = 0,
                   BOUTUNIT  = '-',
                   BJSON     = JSON_REMOVE(
                                   JSON_REMOVE(
                                       COALESCE(BJSON, '{}'),
                                       '$.mode_prices'
                                   ),
                                   '$.pricing_mode'
                               )
             WHERE BID = :bid
               AND BPROVID = :provid
            SQL, [
            'bid' => self::TTS1_BID,
            'provid' => self::TTS1_PROVID,
        ]);

        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEIN  = 0.03,
                   BINUNIT   = 'per1000chars',
                   BPRICEOUT = 0,
                   BOUTUNIT  = '-',
                   BJSON     = JSON_REMOVE(
                                   JSON_REMOVE(
                                       COALESCE(BJSON, '{}'),
                                       '$.mode_prices'
                                   ),
                                   '$.pricing_mode'
                               )
             WHERE BID = :bid
               AND BPROVID = :provid
            SQL, [
            'bid' => self::TTS1HD_BID,
            'provid' => self::TTS1HD_PROVID,
        ]);
    }
}
