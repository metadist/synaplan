<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the Groq models shut down upstream in July/August 2026 and roll out
 * their replacement, Qwen 3.6 27B (https://console.groq.com/docs/deprecations):
 *
 *   - BID 9   llama-3.3-70b-versatile                    (chat)     shut down 08/16/26
 *   - BID 236 llama-3.1-8b-instant                       (chat)     shut down 08/16/26
 *   - BID 17  meta-llama/llama-4-scout-17b-16e-instruct  (pic2text) shut down 07/17/26
 *   - BID 53  qwen/qwen3-32b                             (chat)     shut down 07/17/26
 *
 * Requests to these model ids now return errors, so every install still bound
 * to them has broken chat / vision. Deactivated, never deleted: BMESSAGES rows
 * reference the BIDs via FK. Same contract as {@see Version20260727180000}.
 *
 * The successor rows (Groq Qwen 3.6 27B chat BID 324 + vision BID 325, also
 * added to ModelCatalog in this release) are inserted HERE, not left to the
 * seeder: migrations run before `app:seed` on container start, and the
 * DEFAULTMODEL repoints below need the successor BIDs to exist. The insert is
 * idempotent and carries the catalog fingerprint (same payload/algorithm as
 * ModelCatalog::fingerprint), so ModelSeeder treats the rows as catalog-managed
 * and keeps updating them on future releases.
 *
 * DEFAULTMODEL bindings (global and per-user) are repointed to the successor of
 * the same capability, guarded by an EXISTS subquery:
 *
 *   - llama-3.3-70b-versatile → Qwen 3.6 27B        (BID 324) — Groq's recommended replacement
 *   - qwen/qwen3-32b          → Qwen 3.6 27B        (BID 324)
 *   - llama-4-scout (vision)  → Qwen 3.6 27B Vision (BID 325) — only Groq vision model left
 *   - llama-3.1-8b-instant    → gpt-oss-20b         (BID 75)  — Groq's recommended fast/cheap tier
 */
final class Version20260819080000 extends AbstractMigration
{
    private const QWEN_CHAT_BID = 324;
    private const QWEN_VISION_BID = 325;
    private const GROQ_GPT_OSS_20B_BID = 75;

    /** Must match ModelCatalog::FINGERPRINT_FLOAT_PRECISION. */
    private const FINGERPRINT_FLOAT_PRECISION = 6;

    /**
     * Retired BID => [upstream API model id (guard), successor BID].
     *
     * The BPROVID guard makes the deactivation a no-op when the row was manually
     * repurposed, and makes a re-run idempotent.
     *
     * @var array<int, array{string, int}>
     */
    private const RETIRED_MODELS = [
        9 => ['llama-3.3-70b-versatile', self::QWEN_CHAT_BID],
        17 => ['meta-llama/llama-4-scout-17b-16e-instruct', self::QWEN_VISION_BID],
        53 => ['qwen/qwen3-32b', self::QWEN_CHAT_BID],
        236 => ['llama-3.1-8b-instant', self::GROQ_GPT_OSS_20B_BID],
    ];

    public function getDescription(): string
    {
        return 'Add Groq Qwen 3.6 27B (chat BID 324 + vision BID 325), repoint DEFAULTMODEL bindings, '
            .'and deactivate the Groq models shut down upstream (llama-3.3-70b-versatile, '
            .'llama-3.1-8b-instant, llama-4-scout, qwen3-32b).';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->successorModels() as $model) {
            $json = $model['json'];
            $json['__catalog_fingerprint'] = $this->fingerprint($model);

            $this->addSql(<<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, :service, :name, :tag, :selectable, :active, :providerId, :priceIn, :inUnit, :priceOut, :outUnit, :quality, :rating, 0, 0, :json)
                ON DUPLICATE KEY UPDATE
                    BSERVICE = VALUES(BSERVICE), BNAME = VALUES(BNAME), BTAG = VALUES(BTAG),
                    BPROVID = VALUES(BPROVID), BPRICEIN = VALUES(BPRICEIN),
                    BINUNIT = VALUES(BINUNIT), BPRICEOUT = VALUES(BPRICEOUT),
                    BOUTUNIT = VALUES(BOUTUNIT), BQUALITY = VALUES(BQUALITY),
                    BRATING = VALUES(BRATING), BJSON = VALUES(BJSON)
            SQL, [
                'id' => $model['id'],
                'service' => $model['service'],
                'name' => $model['name'],
                'tag' => $model['tag'],
                'selectable' => $model['selectable'],
                'active' => $model['active'],
                'providerId' => $model['providerId'],
                'priceIn' => $model['priceIn'],
                'inUnit' => $model['inUnit'],
                'priceOut' => $model['priceOut'],
                'outUnit' => $model['outUnit'],
                'quality' => $model['quality'],
                'rating' => $model['rating'],
                'json' => json_encode($json, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            ]);
        }

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
        // intentionally do NOT undo the BCONFIG repoints (we cannot tell an
        // auto-migrated binding from one deliberately set to the successor
        // afterwards) and do NOT delete the Qwen rows (BMESSAGES rows may
        // already reference them). Same contract as Version20260727180000.
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

    /**
     * Snapshot of the two successor rows exactly as authored in ModelCatalog
     * on 2026-08-19 (values AND json key order — the fingerprint depends on it).
     *
     * @return list<array<string, mixed>>
     */
    private function successorModels(): array
    {
        return [
            [
                'id' => self::QWEN_CHAT_BID,
                'service' => 'Groq',
                'name' => 'Qwen 3.6 27B',
                'tag' => 'chat',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'qwen/qwen3.6-27b',
                'priceIn' => 0.60,
                'inUnit' => 'per1M',
                'priceOut' => 3.00,
                'outUnit' => 'per1M',
                'quality' => 9,
                'rating' => 5,
                'json' => [
                    'description' => 'Groq Qwen 3.6 27B - flagship-level reasoning and agentic coding in a compact dense model (~500 t/s). Successor to Llama 3.3 70B and Qwen3 32B on Groq. Supports tool use and JSON mode; reasoning is hidden from the output.',
                    'max_tokens' => 16384,
                    'params' => [
                        'model' => 'qwen/qwen3.6-27b',
                        'reasoning_format' => 'hidden',
                    ],
                    'meta' => ['context_window' => '131072', 'max_output' => '16384', 'quantization' => 'TruePoint Numerics'],
                ],
            ],
            [
                'id' => self::QWEN_VISION_BID,
                'service' => 'Groq',
                'name' => 'Qwen 3.6 27B Vision',
                'tag' => 'pic2text',
                'selectable' => 1,
                'active' => 1,
                'providerId' => 'qwen/qwen3.6-27b',
                'priceIn' => 0.60,
                'inUnit' => 'per1M',
                'priceOut' => 3.00,
                'outUnit' => 'per1M',
                'quality' => 8,
                'rating' => 0,
                'json' => [
                    'description' => 'Groq Qwen 3.6 27B vision - 131K context, up to 3 images (20 MB each), supports tool use and JSON mode. Replaces Llama 4 Scout.',
                    'params' => [
                        'model' => 'qwen/qwen3.6-27b',
                        'max_completion_tokens' => 1024,
                    ],
                ],
            ],
        ];
    }

    /**
     * Local copy of ModelCatalog::fingerprint() frozen at authoring time, so the
     * migration stays self-contained (migrations must not import app code that
     * can drift). Stored alongside the row, it makes ModelSeeder recognise the
     * row as catalog-managed: stored === recomputed, so future catalog changes
     * roll in as clean UPDATEs instead of the row being preserved as an edit.
     *
     * @param array<string, mixed> $row
     */
    private function fingerprint(array $row): string
    {
        $payload = [
            'service' => (string) $row['service'],
            'name' => (string) $row['name'],
            'tag' => (string) $row['tag'],
            'providerId' => (string) $row['providerId'],
            'priceIn' => round((float) $row['priceIn'], self::FINGERPRINT_FLOAT_PRECISION),
            'inUnit' => (string) $row['inUnit'],
            'priceOut' => round((float) $row['priceOut'], self::FINGERPRINT_FLOAT_PRECISION),
            'outUnit' => (string) $row['outUnit'],
            'quality' => round((float) $row['quality'], self::FINGERPRINT_FLOAT_PRECISION),
            'rating' => round((float) $row['rating'], self::FINGERPRINT_FLOAT_PRECISION),
            'json' => $row['json'],
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
        );
    }
}
