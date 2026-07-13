<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roll out the July-2026 pricing audit corrections to existing installs.
 *
 * The catalog (ModelCatalog) is the source of truth, and ModelSeeder rolls
 * catalog changes into BMODELS on deploy — but ONLY for rows still matching
 * their last-seeded fingerprint. Rows an operator touched via the admin UI are
 * PRESERVED and never auto-updated. Because these are billing corrections that
 * must reach every install (costs are resold at raw + markup, so a stale price
 * over/undercharges customers), this migration force-applies them via explicit,
 * idempotent UPDATEs keyed by BPROVID — the same approach as
 * Version20260712120000 / Version20260712130000 / Version20260712140000.
 *
 * Covered (all keyed by BPROVID, so chat + vision rows of a model are updated
 * together):
 *
 *   - Per-token reprices: Groq qwen3-32b / gpt-oss-20b / gpt-oss-120b,
 *     gpt-5.4-nano, Claude Sonnet 5 (introductory), Gemini 2.5 Pro,
 *     Gemini 3.5 Flash, Gemini 3 Flash, and the Claude Sonnet 4.5 output rate
 *     ($5 -> $15, it had been undercharging).
 *   - Kimi K2.5 / K2.6 / K2.7-Code: pinned to DeepInfra (providerId + the
 *     params.model string carry the `:deepinfra` router suffix) with exact
 *     resale rates, so the billed provider/price is deterministic instead of
 *     the HF router's variable `:fastest` pick.
 *   - TheHive image models: corrected from 2.5-12.5x too high to the official
 *     $/1000-image rates ($0.003 / $0.004 per image).
 *   - Veo 3.1 Fast: per-second + resolution prices bumped to current rates.
 *   - gpt-image-1 / gpt-image-1.5: OpenAI quality x size tier prices (#1315),
 *     plus gpt-image-1.5 medium 1024^2 corrected to $0.034.
 *   - Whisper (Groq v3 / v3-turbo, OpenAI whisper-1) + Voxtral Mini Transcribe:
 *     pricing_mode = per_second so duration billing is reached (#1314).
 *
 * Code-only fixes (Anthropic cache-discount case-sensitivity, long-context
 * token tiers #1319) ship with the deploy and need no migration.
 *
 * Operator-owned columns (BSELECTABLE, BACTIVE, BISDEFAULT, BSHOWWHENFREE) are
 * never touched, per the BMODELS catalog-vs-operator contract in AGENTS.md.
 * Idempotent (fixed UPDATEs / JSON_SET re-run to the same result; the Kimi
 * providerId flip is guarded on the old BPROVID so a second run is a no-op) and
 * raw-SQL only (no Schema API), so it is safe on the shared MariaDB Galera
 * cluster.
 *
 * @see \App\Model\ModelCatalog
 * @see Version20260712130000
 */
final class Version20260713190000 extends AbstractMigration
{
    /**
     * Per-token models: providerId => [newIn, newOut, oldIn, oldOut] (per 1M).
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private const TOKEN_PRICES = [
        'qwen/qwen3-32b' => [0.29, 0.59, 0.15, 0.60],
        'openai/gpt-oss-20b' => [0.075, 0.30, 0.10, 0.50],
        'openai/gpt-oss-120b' => [0.15, 0.60, 0.15, 0.75],
        'gpt-5.4-nano' => [0.20, 1.25, 0.20, 1.50],
        'claude-sonnet-5' => [2.0, 10.0, 3.0, 15.0],
        'gemini-2.5-pro' => [1.25, 10.0, 2.5, 15.0],
        'gemini-3.5-flash' => [1.50, 9.00, 0.30, 2.50],
        'gemini-3-flash-preview' => [0.50, 3.00, 0.30, 2.50],
        'claude-sonnet-4-5-20250929' => [3.0, 15.0, 3.0, 5.0],
    ];

    /**
     * TheHive per-image models: providerId => [newPrice, oldPrice].
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const THEHIVE_PRICES = [
        'flux-schnell' => [0.003, 0.01],
        'flux-schnell-enhanced' => [0.004, 0.02],
        'sdxl' => [0.003, 0.02],
        'sdxl-enhanced' => [0.004, 0.05],
        'emoji' => [0.004, 0.01],
    ];

    /**
     * Kimi models pinned to DeepInfra: oldProvid => [newProvid, newIn, newOut, oldIn, oldOut].
     *
     * @var array<string, array{0: string, 1: float, 2: float, 3: float, 4: float}>
     */
    private const KIMI_PINS = [
        'moonshotai/Kimi-K2.5' => ['moonshotai/Kimi-K2.5:deepinfra', 0.45, 2.25, 0.383, 1.72],
        'moonshotai/Kimi-K2.6' => ['moonshotai/Kimi-K2.6:deepinfra', 0.75, 3.50, 0.60, 3.00],
        'moonshotai/Kimi-K2.7-Code' => ['moonshotai/Kimi-K2.7-Code:deepinfra', 0.74, 3.50, 0.95, 4.00],
    ];

    /**
     * Transcription models billed on audio duration (#1314): providerId list.
     *
     * @var list<string>
     */
    private const PER_SECOND_STT = [
        'whisper-large-v3',
        'whisper-large-v3-turbo',
        'whisper-1',
        'voxtral-mini-latest',
    ];

    public function getDescription(): string
    {
        return 'Roll out the July-2026 pricing audit corrections to BMODELS (per-token reprices, '
            .'Kimi DeepInfra pinning, TheHive rates, Veo 3.1 Fast, gpt-image quality tiers, '
            .'Whisper/Voxtral per-second) so existing installs bill correctly regardless of the seeder fingerprint.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TOKEN_PRICES as $provid => [$in, $out]) {
            $this->addSql(
                'UPDATE BMODELS SET BPRICEIN = :in, BPRICEOUT = :out WHERE BPROVID = :provid',
                ['in' => $in, 'out' => $out, 'provid' => $provid]
            );
        }

        foreach (self::THEHIVE_PRICES as $provid => [$price]) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BPRICEOUT = :price,
                       BJSON = JSON_SET(
                                   COALESCE(BJSON, '{}'),
                                   '$.mode_prices', JSON_OBJECT('output_cost_per_image', :price)
                               )
                 WHERE BPROVID = :provid
                SQL, ['price' => $price, 'provid' => $provid]);
        }

        foreach (self::KIMI_PINS as $oldProvid => [$newProvid, $in, $out]) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BPROVID   = :new,
                       BPRICEIN  = :in,
                       BPRICEOUT = :out,
                       BJSON     = JSON_SET(COALESCE(BJSON, '{}'), '$.params.model', :new)
                 WHERE BPROVID = :old
                SQL, ['new' => $newProvid, 'in' => $in, 'out' => $out, 'old' => $oldProvid]);
        }

        foreach (self::PER_SECOND_STT as $provid) {
            $this->addSql(
                "UPDATE BMODELS SET BJSON = JSON_SET(COALESCE(BJSON, '{}'), '\$.pricing_mode', 'per_second') WHERE BPROVID = :provid",
                ['provid' => $provid]
            );
        }

        // Veo 3.1 Fast: per-second resolution prices bumped to current rates.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEOUT = 0.15,
                   BJSON = JSON_SET(
                               COALESCE(BJSON, '{}'),
                               '$.resolution_prices', JSON_OBJECT('720p', 0.15, '1080p', 0.18, '4K', 0.45)
                           )
             WHERE BPROVID = 'veo-3.1-fast-generate-preview'
            SQL);

        // gpt-image-1: add OpenAI quality x size tier prices (#1315). BPRICEOUT
        // (flat fallback) stays at the medium 1024^2 headline rate of $0.042.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BJSON = JSON_SET(
                               COALESCE(BJSON, '{}'),
                               '$.default_quality', 'medium',
                               '$.default_size', '1024x1024',
                               '$.mode_prices', JSON_OBJECT('output_cost_per_image', 0.042),
                               '$.quality_prices', JSON_OBJECT(
                                   'low', JSON_OBJECT('1024x1024', 0.011, '1024x1536', 0.016, '1536x1024', 0.016),
                                   'medium', JSON_OBJECT('1024x1024', 0.042, '1024x1536', 0.063, '1536x1024', 0.063),
                                   'high', JSON_OBJECT('1024x1024', 0.167, '1024x1536', 0.25, '1536x1024', 0.25)
                               )
                           )
             WHERE BPROVID = 'gpt-image-1'
            SQL);

        // gpt-image-1.5: correct medium 1024^2 to $0.034 + add tier prices.
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEOUT = 0.034,
                   BJSON = JSON_SET(
                               COALESCE(BJSON, '{}'),
                               '$.default_quality', 'medium',
                               '$.default_size', '1024x1024',
                               '$.mode_prices', JSON_OBJECT('output_cost_per_image', 0.034),
                               '$.quality_prices', JSON_OBJECT(
                                   'low', JSON_OBJECT('1024x1024', 0.009, '1024x1536', 0.013, '1536x1024', 0.013),
                                   'medium', JSON_OBJECT('1024x1024', 0.034, '1024x1536', 0.05, '1536x1024', 0.05),
                                   'high', JSON_OBJECT('1024x1024', 0.133, '1024x1536', 0.20, '1536x1024', 0.20)
                               )
                           )
             WHERE BPROVID = 'gpt-image-1.5'
            SQL);
    }

    public function down(Schema $schema): void
    {
        foreach (self::TOKEN_PRICES as $provid => [, , $oldIn, $oldOut]) {
            $this->addSql(
                'UPDATE BMODELS SET BPRICEIN = :in, BPRICEOUT = :out WHERE BPROVID = :provid',
                ['in' => $oldIn, 'out' => $oldOut, 'provid' => $provid]
            );
        }

        foreach (self::THEHIVE_PRICES as $provid => [, $oldPrice]) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BPRICEOUT = :price,
                       BJSON = JSON_SET(
                                   COALESCE(BJSON, '{}'),
                                   '$.mode_prices', JSON_OBJECT('output_cost_per_image', :price)
                               )
                 WHERE BPROVID = :provid
                SQL, ['price' => $oldPrice, 'provid' => $provid]);
        }

        foreach (self::KIMI_PINS as $oldProvid => [$newProvid, , , $oldIn, $oldOut]) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BPROVID   = :old,
                       BPRICEIN  = :in,
                       BPRICEOUT = :out,
                       BJSON     = JSON_SET(COALESCE(BJSON, '{}'), '$.params.model', :old)
                 WHERE BPROVID = :new
                SQL, ['old' => $oldProvid, 'in' => $oldIn, 'out' => $oldOut, 'new' => $newProvid]);
        }

        foreach (self::PER_SECOND_STT as $provid) {
            $this->addSql(
                "UPDATE BMODELS SET BJSON = JSON_REMOVE(COALESCE(BJSON, '{}'), '\$.pricing_mode') WHERE BPROVID = :provid",
                ['provid' => $provid]
            );
        }

        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEOUT = 0.10,
                   BJSON = JSON_SET(
                               COALESCE(BJSON, '{}'),
                               '$.resolution_prices', JSON_OBJECT('720p', 0.10, '1080p', 0.12, '4K', 0.30)
                           )
             WHERE BPROVID = 'veo-3.1-fast-generate-preview'
            SQL);

        // Drop the gpt-image tier keys; leave the flat mode_prices/BPRICEOUT
        // as the pre-tier fallback ($0.042 / $0.04).
        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BJSON = JSON_REMOVE(
                               JSON_REMOVE(
                                   JSON_REMOVE(COALESCE(BJSON, '{}'), '$.quality_prices'),
                                   '$.default_size'
                               ),
                               '$.default_quality'
                           )
             WHERE BPROVID = 'gpt-image-1'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE BMODELS
               SET BPRICEOUT = 0.04,
                   BJSON = JSON_SET(
                               JSON_REMOVE(
                                   JSON_REMOVE(
                                       JSON_REMOVE(COALESCE(BJSON, '{}'), '$.quality_prices'),
                                       '$.default_size'
                                   ),
                                   '$.default_quality'
                               ),
                               '$.mode_prices', JSON_OBJECT('output_cost_per_image', 0.04)
                           )
             WHERE BPROVID = 'gpt-image-1.5'
            SQL);
    }
}
