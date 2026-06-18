<?php

declare(strict_types=1);

namespace App\Seed;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for global default-model configuration in BCONFIG (ownerId=0).
 *
 * Inserts initial DEFAULTMODEL bindings (CHAT, TOOLS, SUMMARIZE, ...) and the
 * `ai.default_chat_provider` flag if and only if the row does not exist yet.
 *
 * Defaults reference catalog entries by `service:providerId:tag` keys (resolved
 * via ModelCatalog::findBidByKey) instead of hard-coded BIDs, so renumbering or
 * reorganising the catalog cannot silently break the routing.
 *
 * NEVER overwrites operator-tuned values, even if they differ from the seed defaults.
 */
final readonly class DefaultModelConfigSeeder
{
    /**
     * Production defaults: every capability points at a stable catalog key. The
     * seeder resolves each key to its current BID at runtime; if a key cannot be
     * resolved (catalog drift / typo), the seeder throws so the developer notices
     * immediately instead of routing requests at a missing model.
     *
     * @var list<array{group: string, setting: string, modelKey: string}>
     */
    private const PROD_MODEL_DEFAULTS = [
        ['group' => 'DEFAULTMODEL', 'setting' => 'CHAT',       'modelKey' => 'anthropic:claude-sonnet-4-6:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TOOLS',      'modelKey' => 'anthropic:claude-sonnet-4-6:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'SORT',       'modelKey' => 'groq:openai/gpt-oss-120b:chat'],
        // Multi-task routing planner. Same fast/cheap tier as SORT but a
        // dedicated binding so it can be tuned without touching the legacy
        // sorter. TaskPlanner falls back to SORT if this row is absent.
        ['group' => 'DEFAULTMODEL', 'setting' => 'PLAN',       'modelKey' => 'groq:openai/gpt-oss-120b:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE',  'modelKey' => 'groq:openai/gpt-oss-120b:chat'],
        // Phase 2d: dedicated MEM tag so memory extraction never inherits the
        // user's heavy chat model (Gemini Pro etc.). Resolves to the new
        // ModelCatalog row id 220 ("Memory extraction model") which is a
        // system-only clone of Groq gpt-oss-120b — fast and cheap.
        ['group' => 'DEFAULTMODEL', 'setting' => 'MEM',        'modelKey' => 'groq:openai/gpt-oss-120b:mem'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC',   'modelKey' => 'google:gemini-3.1-flash-image-preview:text2pic'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'PIC2PIC',    'modelKey' => 'google:gemini-3.1-flash-image-preview:text2pic'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID',   'modelKey' => 'google:veo-3.1-generate-preview:text2vid'],
        // IMG2VID (animate an attached image). Defaults to Higgsfield DoP
        // Standard — an image-to-video model. Shares the text2vid BTAG (see
        // ModelCatalog::CAPABILITY_TAGS) but has its own default slot.
        ['group' => 'DEFAULTMODEL', 'setting' => 'IMG2VID',    'modelKey' => 'higgsfield:higgsfield-ai/dop/standard:text2vid'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TEXT2SOUND', 'modelKey' => 'piper:piper-multi:text2sound'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT',   'modelKey' => 'groq:meta-llama/llama-4-scout-17b-16e-instruct:pic2text'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'SOUND2TEXT', 'modelKey' => 'groq:whisper-large-v3:sound2text'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'ANALYZE',    'modelKey' => 'anthropic:claude-sonnet-4-6:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE',  'modelKey' => 'ollama:bge-m3:vectorize'],
    ];

    /**
     * Plain key/value flags that are not model-bound.
     *
     * @var list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private const PROD_FLAGS = [
        ['ownerId' => 0, 'group' => 'ai', 'setting' => 'default_chat_provider', 'value' => 'anthropic'],
    ];

    /**
     * Test-env defaults route every capability at the matching TestProvider model
     * (negative IDs from ModelSeeder::TEST_MODELS) so PHPUnit/E2E suites run without
     * real provider API keys. Test BIDs are stable because they are defined alongside
     * the seeder, so we keep them as literal values here.
     *
     * @var list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private const TEST_DEFAULTS = [
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'CHAT',       'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TOOLS',      'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SORT',       'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PLAN',       'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE',  'value' => '-1'],
        // Phase 2d: MEM tag in test routes through the test stub chat model
        // so PHPUnit doesn't need real Groq access for memory extraction.
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'MEM',        'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'ANALYZE',    'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE',         'value' => '-2'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT',          'value' => '-3'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC',   'value' => '-4'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2PIC',    'value' => '-4'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID',   'value' => '-5'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'IMG2VID',    'value' => '-5'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SOUND2TEXT', 'value' => '-6'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2SOUND', 'value' => '-7'],

        ['ownerId' => 0, 'group' => 'ai', 'setting' => 'default_chat_provider', 'value' => 'test'],
    ];

    public function __construct(
        private Connection $connection,
        private string $environment,
    ) {
    }

    public function seed(): SeedResult
    {
        if ('test' === $this->environment) {
            return BConfigSeeder::insertIfMissing($this->connection, 'default_model_config', self::TEST_DEFAULTS);
        }

        return BConfigSeeder::insertIfMissing(
            $this->connection,
            'default_model_config',
            [...self::resolveProdDefaults(), ...self::PROD_FLAGS],
        );
    }

    /**
     * Resolve every PROD_MODEL_DEFAULTS entry to a `{capability => BID}` map.
     *
     * Used by the "reset to defaults" API endpoint so the frontend button
     * restores exactly the same bindings that a fresh deployment receives.
     * Throws if any key cannot be resolved (catalog/binding mismatch).
     *
     * @return array<string, int> e.g. ['CHAT' => 180, 'TOOLS' => 180, ...]
     */
    public static function getRecommendedDefaults(): array
    {
        $defaults = [];
        foreach (self::PROD_MODEL_DEFAULTS as $row) {
            $bid = ModelCatalog::findBidByKey($row['modelKey']);
            if (null === $bid) {
                throw new \RuntimeException(sprintf("DefaultModelConfigSeeder: model key '%s' does not resolve to exactly one entry in ModelCatalog (referenced by DEFAULTMODEL.%s).", $row['modelKey'], $row['setting']));
            }
            $defaults[$row['setting']] = $bid;
        }

        return $defaults;
    }

    /**
     * Resolve every modelKey in PROD_MODEL_DEFAULTS to a concrete BID at seed time.
     *
     * Throws if any key cannot be resolved — that would indicate the catalog and the
     * default-bindings are out of sync, which we want to surface immediately rather
     * than route to a non-existent model id.
     *
     * @return list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private static function resolveProdDefaults(): array
    {
        $rows = [];
        foreach (self::PROD_MODEL_DEFAULTS as $row) {
            $bid = ModelCatalog::findBidByKey($row['modelKey']);
            if (null === $bid) {
                throw new \RuntimeException(sprintf("DefaultModelConfigSeeder: model key '%s' does not resolve to exactly one entry in ModelCatalog (referenced by DEFAULTMODEL.%s). Check ModelCatalog::MODELS and the key format 'service:providerId:tag'.", $row['modelKey'], $row['setting']));
            }
            $rows[] = [
                'ownerId' => 0,
                'group' => $row['group'],
                'setting' => $row['setting'],
                'value' => (string) $bid,
            ];
        }

        return $rows;
    }
}
