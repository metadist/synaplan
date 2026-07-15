<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #1311: Repair BID 170 when production still carries the aborted
 * "Gemini 3.5 Flash" rename. Catalog pins BID 170 to Gemini 2.5 Flash and
 * publishes 3.5 Flash as BID 237, but the seeder fingerprint guard correctly
 * refused to overwrite the drifted prod row — leaving two "Gemini 3.5 Flash"
 * entries in the picker.
 *
 * Only updates BID 170 while it still looks like the stale 3.5 Flash rename
 * (BNAME / BPROVID). Operator toggles (BSELECTABLE, BACTIVE, BISDEFAULT) are
 * never touched. Raw addSql only (Galera-safe).
 */
final class Version20260715120000 extends AbstractMigration
{
    private const BID = 170;

    /** Catalog fingerprint for Google / Gemini 2.5 Flash / chat (BID 170). */
    private const FINGERPRINT = 'dc1c7fb7c02e163a316d91523beb65f0ee9484cfecbb12af54e2b4b4c98727f4';

    public function getDescription(): string
    {
        return 'Restore BID 170 to Gemini 2.5 Flash when it still carries the stale 3.5 Flash rename (#1311)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $json = json_encode([
            'description' => 'Google Gemini 2.5 Flash - best price-performance model, 1M token context, reasoning, vision, audio.',
            'max_tokens' => 65536,
            'params' => ['model' => 'gemini-2.5-flash'],
            'features' => ['reasoning', 'vision', 'audio'],
            'meta' => ['context_window' => '1000000', 'max_output' => '65536'],
            '__catalog_fingerprint' => self::FINGERPRINT,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $this->addSql(
            <<<'SQL'
            UPDATE BMODELS
               SET BNAME = 'Gemini 2.5 Flash',
                   BPROVID = 'gemini-2.5-flash',
                   BPRICEIN = 0.30,
                   BINUNIT = 'per1M',
                   BPRICEOUT = 2.50,
                   BOUTUNIT = 'per1M',
                   BQUALITY = 9,
                   BRATING = 1,
                   BJSON = :json
             WHERE BID = :bid
               AND BSERVICE = 'Google'
               AND BTAG = 'chat'
               AND (
                    BNAME LIKE '%3.5 Flash%'
                 OR BPROVID LIKE 'gemini-3.5-flash%'
               )
            SQL,
            [
                'json' => $json,
                'bid' => self::BID,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty: rolling back would reintroduce the duplicate picker bug.
    }
}
