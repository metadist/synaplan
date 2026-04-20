<?php

declare(strict_types=1);

namespace App\Seed;

use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;

/**
 * Seeds the example widget configuration (BCONFIG group=widget_1) for the demo user.
 *
 * Only runs in dev/test environments — in prod, this is demo data that should
 * never be auto-injected. Uses insert-if-missing so it stays idempotent across
 * container restarts.
 *
 * The owner is resolved at runtime by looking up the demo user's email instead of
 * hard-coding a BID, so reordering UserFixtures cannot orphan these BCONFIG rows.
 */
final readonly class DemoWidgetConfigSeeder
{
    /**
     * Email of the demo user that owns the example widget config. Created by
     * App\DataFixtures\UserFixtures in dev/test.
     */
    private const DEMO_USER_EMAIL = 'demo@synaplan.com';

    /**
     * @var list<array{group: string, setting: string, value: string}>
     */
    private const ROWS = [
        ['group' => 'widget_1', 'setting' => 'color',       'value' => '#007bff'],
        ['group' => 'widget_1', 'setting' => 'position',    'value' => 'bottom-right'],
        ['group' => 'widget_1', 'setting' => 'autoMessage', 'value' => 'Hello! How can I help you today?'],
        ['group' => 'widget_1', 'setting' => 'prompt',      'value' => 'general'],
    ];

    public function __construct(
        private Connection $connection,
        private UserRepository $userRepository,
        private string $environment,
    ) {
    }

    public function seed(): SeedResult
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            // Not attempted at all in prod — explicitly report 0/0/0 instead of a
            // misleading "skipped = count(ROWS)" that would suggest existing rows.
            return new SeedResult('demo_widget', inserted: 0, skipped: 0);
        }

        $demoUser = $this->userRepository->findByEmail(self::DEMO_USER_EMAIL);
        if (null === $demoUser) {
            // Fixtures haven't run yet (e.g. very first boot before UserFixtures completed,
            // or someone deleted the demo user). Nothing to attach the config to — skip.
            return new SeedResult('demo_widget', inserted: 0, skipped: count(self::ROWS));
        }

        $ownerId = (int) $demoUser->getId();
        $rows = array_map(
            static fn (array $row): array => [
                'ownerId' => $ownerId,
                'group' => $row['group'],
                'setting' => $row['setting'],
                'value' => $row['value'],
            ],
            self::ROWS,
        );

        return BConfigSeeder::insertIfMissing($this->connection, 'demo_widget', $rows);
    }
}
