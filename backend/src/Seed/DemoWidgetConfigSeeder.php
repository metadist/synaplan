<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Seeds the example widget configuration (BCONFIG group=widget_1, ownerId=2).
 *
 * Only runs in dev/test environments — in prod, this is demo data that should
 * never be auto-injected. Uses insert-if-missing so it stays idempotent across
 * container restarts.
 */
final readonly class DemoWidgetConfigSeeder
{
    /**
     * @var list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private const ROWS = [
        ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'color',       'value' => '#007bff'],
        ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'position',    'value' => 'bottom-right'],
        ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'autoMessage', 'value' => 'Hello! How can I help you today?'],
        ['ownerId' => 2, 'group' => 'widget_1', 'setting' => 'prompt',      'value' => 'general'],
    ];

    public function __construct(
        private Connection $connection,
        private string $environment,
    ) {
    }

    public function seed(): SeedResult
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            return new SeedResult('demo_widget', inserted: 0, skipped: count(self::ROWS));
        }

        return BConfigSeeder::insertIfMissing($this->connection, 'demo_widget', self::ROWS);
    }
}
