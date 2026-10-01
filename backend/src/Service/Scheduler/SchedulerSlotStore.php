<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;

/**
 * Atomic scheduler slot claims in BCONFIG (owner 0, group SCHEDULER).
 *
 * BVALUE is the unix timestamp of the last winning claim. The conditional
 * UPDATE matches that exact value, and INSERT IGNORE creates the first row.
 * UNIQUE(BOWNERID, BGROUP, BSETTING) makes the insert race-safe across Galera
 * nodes. A certification conflict or deadlock is a {@see RetryableException}
 * and counts as a lost claim — the caller must not run the slot.
 */
final readonly class SchedulerSlotStore
{
    public const GROUP = 'SCHEDULER';
    public const OWNER_ID = 0;
    public const SETTING_HOURLY = 'SLOT_HOURLY';
    public const SETTING_DAILY = 'SLOT_DAILY';
    public const SETTING_HEALTH = 'SLOT_HEALTH';

    /**
     * @var array<string, string>
     */
    public const SETTINGS = [
        'hourly' => self::SETTING_HOURLY,
        'daily' => self::SETTING_DAILY,
        'health' => self::SETTING_HEALTH,
    ];

    private const SELECT_SQL = <<<'SQL'
        SELECT BVALUE FROM BCONFIG
        WHERE BOWNERID = 0 AND BGROUP = 'SCHEDULER' AND BSETTING = :setting
        SQL;

    private const UPDATE_SQL = <<<'SQL'
        UPDATE BCONFIG SET BVALUE = :now
        WHERE BOWNERID = 0 AND BGROUP = 'SCHEDULER' AND BSETTING = :setting AND BVALUE = :old
        SQL;

    private const INSERT_SQL = <<<'SQL'
        INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
        VALUES (0, 'SCHEDULER', :setting, :now)
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function read(string $setting): SchedulerSlotSnapshot
    {
        $statement = $this->connection->prepare(self::SELECT_SQL);
        $statement->bindValue('setting', $setting);
        $value = $statement->executeQuery()->fetchOne();

        if (false === $value || null === $value) {
            return new SchedulerSlotSnapshot(false, null, null);
        }

        if (!is_string($value) && !is_int($value)) {
            throw new \RuntimeException(sprintf('Scheduler slot "%s" has an unreadable BVALUE.', $setting));
        }

        $raw = (string) $value;

        return new SchedulerSlotSnapshot(true, $this->timestampOrNull($raw), $raw);
    }

    /**
     * Compare-and-set. True only when this call wrote the row.
     * A {@see RetryableException} is a lost claim, not a failure to report.
     */
    public function claim(string $setting, bool $rowExists, ?string $expectedRaw, int $now): bool
    {
        try {
            if (!$rowExists) {
                return 1 === $this->insert($setting, $now);
            }

            if (!is_string($expectedRaw)) {
                throw new \InvalidArgumentException(sprintf('Scheduler slot "%s" cannot be claimed without the current BVALUE.', $setting));
            }

            return 1 === $this->update($setting, $expectedRaw, $now);
        } catch (RetryableException) {
            return false;
        }
    }

    private function update(string $setting, string $expectedRaw, int $now): int
    {
        $statement = $this->connection->prepare(self::UPDATE_SQL);
        $statement->bindValue('now', (string) $now);
        $statement->bindValue('setting', $setting);
        $statement->bindValue('old', $expectedRaw);

        return (int) $statement->executeStatement();
    }

    private function insert(string $setting, int $now): int
    {
        $statement = $this->connection->prepare(self::INSERT_SQL);
        $statement->bindValue('setting', $setting);
        $statement->bindValue('now', (string) $now);

        return (int) $statement->executeStatement();
    }

    private function timestampOrNull(string $raw): ?int
    {
        if (1 !== preg_match('/\A[0-9]+\z/', $raw)) {
            return null;
        }

        $timestamp = (int) $raw;
        if ((string) $timestamp !== $raw) {
            return null;
        }

        return $timestamp;
    }
}
