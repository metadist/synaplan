<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

use Doctrine\DBAL\Connection;

/**
 * Persists per-provider discovery state and the daily Discord claim in BCONFIG.
 *
 * State shape (setting {@see SETTING_PROVIDERS}, owner 0, group
 * {@see CONFIG_GROUP}): JSON object keyed by inventory provider name:
 *
 *   {
 *     "openai": {
 *       "baselineRecorded": true,
 *       "baselineAnnounced": false,
 *       "baselineIds": ["gpt-4o", …],
 *       "seen": { "gpt-4o": "2026-09-24", "new-id": "2026-09-25" },
 *       "announced": { "new-id": "2026-09-25" },
 *       "failingSince": null,
 *       "failureAnnounced": false
 *     }
 *   }
 *
 * `announced` maps pending listing ids (verbatim) to the Y-m-d they were first
 * posted. `failingSince` is set on the first UNREACHABLE run and cleared on the
 * next OK listing; `failureAnnounced` tracks whether that outage was posted.
 *
 * No migration and no Schema API — rows appear on first write via INSERT.
 *
 * Daily Discord claim (setting {@see SETTING_NOTIFY_CLAIM}): BVALUE holds the
 * Y-m-d of the day that already posted. {@see claimNotifyDay()} wins with a
 * conditional UPDATE (value must differ) or an INSERT IGNORE when the row is
 * absent; losers see 0 affected rows and skip the post. {@see releaseNotifyDay()}
 * clears the claim only when BVALUE still equals our day (failed post retry).
 * Galera: every web node shares one BCONFIG; the UNIQUE(BOWNERID, BGROUP,
 * BSETTING) index makes the INSERT IGNORE race-safe across nodes, and the
 * WHERE BVALUE <> :day UPDATE is certified the same way any other single-row
 * write is — exactly one certification winner per day.
 */
final readonly class ModelDiscoveryStateStore
{
    public const CONFIG_GROUP = 'MODEL_DISCOVERY';
    public const SETTING_PROVIDERS = 'PROVIDERS';
    public const SETTING_NOTIFY_CLAIM = 'NOTIFY_CLAIM';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>,
     *     announced: array<string, string>,
     *     failingSince: string|null,
     *     failureAnnounced: bool
     * }>
     */
    public function loadProviders(): array
    {
        $raw = $this->readValue(self::SETTING_PROVIDERS);
        if (null === $raw || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $provider => $state) {
            if (!is_string($provider) || !is_array($state)) {
                continue;
            }
            $out[$provider] = $this->normaliseProviderState($state);
        }

        return $out;
    }

    /**
     * @param array<string, array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>,
     *     announced: array<string, string>,
     *     failingSince: string|null,
     *     failureAnnounced: bool
     * }> $providers
     */
    public function saveProviders(array $providers): void
    {
        $this->writeValue(self::SETTING_PROVIDERS, json_encode($providers, \JSON_THROW_ON_ERROR));
    }

    /**
     * Atomically claim the right to post Discord for $day (Y-m-d).
     *
     * Returns true only for the winning node/process. Losers must still update
     * provider state idempotently but must not post.
     */
    public function claimNotifyDay(string $day): bool
    {
        $updated = (int) $this->connection->executeStatement(
            'UPDATE BCONFIG SET BVALUE = :day
             WHERE BOWNERID = 0 AND BGROUP = :group AND BSETTING = :setting
               AND BVALUE <> :day',
            [
                'day' => $day,
                'group' => self::CONFIG_GROUP,
                'setting' => self::SETTING_NOTIFY_CLAIM,
            ],
        );

        if ($updated > 0) {
            return true;
        }

        $inserted = (int) $this->connection->executeStatement(
            'INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
             VALUES (0, :group, :setting, :day)',
            [
                'group' => self::CONFIG_GROUP,
                'setting' => self::SETTING_NOTIFY_CLAIM,
                'day' => $day,
            ],
        );

        return $inserted > 0;
    }

    /**
     * Clear today's Discord claim only if we still own it (failed post retry).
     */
    public function releaseNotifyDay(string $day): void
    {
        $this->connection->executeStatement(
            'UPDATE BCONFIG SET BVALUE = \'\'
             WHERE BOWNERID = 0 AND BGROUP = :group AND BSETTING = :setting
               AND BVALUE = :day',
            [
                'day' => $day,
                'group' => self::CONFIG_GROUP,
                'setting' => self::SETTING_NOTIFY_CLAIM,
            ],
        );
    }

    /**
     * @param array<mixed> $state
     *
     * @return array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>,
     *     announced: array<string, string>,
     *     failingSince: string|null,
     *     failureAnnounced: bool
     * }
     */
    private function normaliseProviderState(array $state): array
    {
        $baselineIds = [];
        foreach ($state['baselineIds'] ?? [] as $id) {
            if (is_string($id) && '' !== $id) {
                $baselineIds[] = $id;
            }
        }

        $seen = [];
        foreach ($state['seen'] ?? [] as $id => $date) {
            if (is_string($id) && '' !== $id && is_string($date) && '' !== $date) {
                $seen[$id] = $date;
            }
        }

        $announced = [];
        foreach ($state['announced'] ?? [] as $id => $date) {
            if (is_string($id) && '' !== $id && is_string($date) && '' !== $date) {
                $announced[$id] = $date;
            }
        }

        $failingSince = $state['failingSince'] ?? null;
        if (!is_string($failingSince) || '' === $failingSince) {
            $failingSince = null;
        }

        return [
            'baselineRecorded' => (bool) ($state['baselineRecorded'] ?? false),
            'baselineAnnounced' => (bool) ($state['baselineAnnounced'] ?? false),
            'baselineIds' => array_values(array_unique($baselineIds)),
            'seen' => $seen,
            'announced' => $announced,
            'failingSince' => $failingSince,
            'failureAnnounced' => (bool) ($state['failureAnnounced'] ?? false),
        ];
    }

    private function readValue(string $setting): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT BVALUE FROM BCONFIG
             WHERE BOWNERID = 0 AND BGROUP = :group AND BSETTING = :setting',
            [
                'group' => self::CONFIG_GROUP,
                'setting' => $setting,
            ],
        );

        return false === $value || null === $value ? null : (string) $value;
    }

    private function writeValue(string $setting, string $value): void
    {
        $updated = (int) $this->connection->executeStatement(
            'UPDATE BCONFIG SET BVALUE = :value
             WHERE BOWNERID = 0 AND BGROUP = :group AND BSETTING = :setting',
            [
                'value' => $value,
                'group' => self::CONFIG_GROUP,
                'setting' => $setting,
            ],
        );

        if ($updated > 0) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
             VALUES (0, :group, :setting, :value)
             ON DUPLICATE KEY UPDATE BVALUE = VALUES(BVALUE)',
            [
                'group' => self::CONFIG_GROUP,
                'setting' => $setting,
                'value' => $value,
            ],
        );
    }
}
