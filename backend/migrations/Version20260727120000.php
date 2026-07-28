<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the old Anthropic Claude generations after Claude Opus 5 was added to
 * {@see \App\Model\ModelCatalog} (Opus 5, Sonnet 5, Fable 5, Opus 4.8 and Haiku 4.5
 * stay).
 *
 * Two groups are covered:
 *
 *   1. Rows removed from the catalog in the same change: Sonnet 4.5, Opus 4.6,
 *      Sonnet 4.6, Opus 4.7.
 *   2. Rows that left the catalog in earlier releases WITHOUT a deactivation
 *      migration and therefore still sit in existing databases as selectable,
 *      billable models: Opus 4.1 (69/93) and Opus 4.5 (121). Opus 4.1 is deprecated
 *      upstream and Anthropic retires it on 2026-08-05, after which requests fail.
 *
 * The seeder never deletes BMODELS rows and historical BMESSAGES rows still reference
 * these BIDs via FK, so we deactivate instead of deleting (same contract as
 * {@see Version20260508120000}).
 *
 * Every {@see \App\Entity\Config} row with BGROUP = 'DEFAULTMODEL' pointing at a
 * retired BID (global defaults and per-user overrides) is repointed to the surviving
 * model of the same tier and BTAG, guarded by an EXISTS subquery so nothing routes at
 * a missing BID:
 *
 *   - Sonnet 4.5 / Sonnet 4.6            → Claude Sonnet 5 (chat 249, vision 250)
 *   - Opus 4.1 / 4.5 / 4.6 / 4.7         → Claude Opus 4.8 (chat 238, vision 239)
 *
 * The successors are deliberately models that already exist in every deployed
 * database: migrations run BEFORE `app:seed` on container start, so the brand-new
 * Claude Opus 5 rows (257/258) do not exist yet while this migration executes.
 *
 * The MEM-tagged row BID 222 (previously Opus 4.6) is NOT touched here. It moves to
 * Claude Sonnet 5 through the catalog, which keeps the BID — and therefore any
 * operator's MEM selection — intact. Rows an admin edited in the UI are preserved by
 * ModelSeeder and keep calling Opus 4.6, which Anthropic still serves as a legacy
 * model.
 */
final class Version20260727120000 extends AbstractMigration
{
    private const SUCCESSOR_SONNET_5_CHAT_BID = 249;
    private const SUCCESSOR_SONNET_5_VISION_BID = 250;
    private const SUCCESSOR_OPUS_48_CHAT_BID = 238;
    private const SUCCESSOR_OPUS_48_VISION_BID = 239;

    /**
     * Retired BID => [upstream API model id (guard), successor BID].
     *
     * The BPROVID guard makes the deactivation a no-op when the row was manually
     * repurposed, and makes a re-run idempotent.
     *
     * @var array<int, array{string, int}>
     */
    private const RETIRED_MODELS = [
        112 => ['claude-sonnet-4-5-20250929', self::SUCCESSOR_SONNET_5_CHAT_BID],
        109 => ['claude-sonnet-4-5-20250929', self::SUCCESSOR_SONNET_5_VISION_BID],
        161 => ['claude-sonnet-4-6', self::SUCCESSOR_SONNET_5_CHAT_BID],
        163 => ['claude-sonnet-4-6', self::SUCCESSOR_SONNET_5_VISION_BID],
        160 => ['claude-opus-4-6', self::SUCCESSOR_OPUS_48_CHAT_BID],
        164 => ['claude-opus-4-6', self::SUCCESSOR_OPUS_48_VISION_BID],
        165 => ['claude-opus-4-7', self::SUCCESSOR_OPUS_48_CHAT_BID],
        166 => ['claude-opus-4-7', self::SUCCESSOR_OPUS_48_VISION_BID],
        // Left the catalog in earlier releases but stayed active in existing DBs.
        69 => ['claude-opus-4-1-20250805', self::SUCCESSOR_OPUS_48_CHAT_BID],
        93 => ['claude-opus-4-1-20250805', self::SUCCESSOR_OPUS_48_VISION_BID],
        121 => ['claude-opus-4-5', self::SUCCESSOR_OPUS_48_CHAT_BID],
    ];

    public function getDescription(): string
    {
        return 'Deactivate the Claude Opus 4.1/4.5/4.6/4.7 and Sonnet 4.5/4.6 rows and repoint DEFAULTMODEL bindings (any owner) to Claude Opus 4.8 / Sonnet 5.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RETIRED_MODELS as $retiredBid => [$providerId, $successorBid]) {
            $this->addSql(<<<'SQL'
                UPDATE BCONFIG
                   SET BVALUE = :successor
                 WHERE BGROUP = 'DEFAULTMODEL'
                   AND BVALUE = :retired
                   AND EXISTS (SELECT 1 FROM BMODELS WHERE BID = :successor)
            SQL, [
                'retired' => (string) $retiredBid,
                'successor' => (string) $successorBid,
            ]);

            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BACTIVE = 0,
                       BSELECTABLE = 0,
                       BISDEFAULT = 0
                 WHERE BID = :retired
                   AND BPROVID = :providerId
            SQL, [
                'retired' => $retiredBid,
                'providerId' => $providerId,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Reactivate the retired rows so they reappear in the admin UI. We
        // intentionally do NOT undo the BCONFIG repoints from up(): we cannot know
        // which bindings were auto-migrated vs. deliberately set to the successor
        // afterwards. Same contract as Version20260508120000::down().
        foreach (self::RETIRED_MODELS as $retiredBid => [$providerId]) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BACTIVE = 1,
                       BSELECTABLE = 1
                 WHERE BID = :retired
                   AND BPROVID = :providerId
            SQL, [
                'retired' => $retiredBid,
                'providerId' => $providerId,
            ]);
        }
    }
}
