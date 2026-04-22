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
        ['group' => 'DEFAULTMODEL', 'setting' => 'CHAT',       'modelKey' => 'openai:gpt-5.4:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TOOLS',      'modelKey' => 'openai:gpt-5.4:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'SORT',       'modelKey' => 'groq:openai/gpt-oss-120b:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE',  'modelKey' => 'groq:openai/gpt-oss-120b:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC',   'modelKey' => 'google:gemini-3.1-flash-image-preview:text2pic'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'PIC2PIC',    'modelKey' => 'google:gemini-3.1-flash-image-preview:text2pic'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID',   'modelKey' => 'google:veo-3.1-generate-preview:text2vid'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'TEXT2SOUND', 'modelKey' => 'piper:piper-multi:text2sound'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT',   'modelKey' => 'groq:meta-llama/llama-4-scout-17b-16e-instruct:pic2text'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'SOUND2TEXT', 'modelKey' => 'groq:whisper-large-v3:sound2text'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'ANALYZE',    'modelKey' => 'openai:gpt-5.4:chat'],
        ['group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE',  'modelKey' => 'ollama:bge-m3:vectorize'],
    ];

    /**
     * Plain key/value flags that are not model-bound.
     *
     * @var list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private const PROD_FLAGS = [
        ['ownerId' => 0, 'group' => 'ai', 'setting' => 'default_chat_provider', 'value' => 'openai'],
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
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE',  'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'ANALYZE',    'value' => '-1'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE',  'value' => '-2'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT',   'value' => '-3'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC',   'value' => '-4'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2PIC',    'value' => '-4'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID',   'value' => '-5'],
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
