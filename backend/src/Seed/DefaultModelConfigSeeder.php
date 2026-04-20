<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for global default-model configuration in BCONFIG (ownerId=0).
 *
 * Inserts initial DEFAULTMODEL bindings (CHAT, TOOLS, SUMMARIZE, ...) and the
 * `ai.default_chat_provider` flag if and only if the row does not exist yet.
 *
 * NEVER overwrites operator-tuned values, even if they differ from the seed defaults.
 *
 * Note: BCONFIG currently lacks a UNIQUE(BOWNERID, BGROUP, BSETTING) constraint,
 * so we use an explicit SELECT-then-INSERT pattern instead of ON DUPLICATE KEY UPDATE.
 * A follow-up migration can add that constraint and simplify this code.
 */
final readonly class DefaultModelConfigSeeder
{
    /**
     * @var list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private const PROD_DEFAULTS = [
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'CHAT',       'value' => '180'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TOOLS',      'value' => '180'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SORT',       'value' => '76'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE',  'value' => '76'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2PIC',   'value' => '190'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2PIC',    'value' => '190'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2VID',   'value' => '45'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'TEXT2SOUND', 'value' => '140'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'PIC2TEXT',   'value' => '17'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SOUND2TEXT', 'value' => '21'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'ANALYZE',    'value' => '180'],
        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'VECTORIZE',  'value' => '13'],

        ['ownerId' => 0, 'group' => 'ai', 'setting' => 'default_chat_provider', 'value' => 'openai'],
    ];

    /**
     * Test-env defaults route every capability at the matching TestProvider model
     * (negative IDs from ModelSeeder::TEST_MODELS) so PHPUnit/E2E suites run without
     * real provider API keys.
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
        $rows = 'test' === $this->environment ? self::TEST_DEFAULTS : self::PROD_DEFAULTS;

        return BConfigSeeder::insertIfMissing($this->connection, 'default_model_config', $rows);
    }
}
