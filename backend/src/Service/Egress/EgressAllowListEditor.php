<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\Service\Admin\SystemConfig\SystemConfigWriter;
use App\Service\Iam\AuditLogWriter;
use Symfony\Component\Lock\LockFactory;

/**
 * Allow and Remove on the capacity page's Outbound tab. Both edit the same
 * `EGRESS.ALLOW` list as Admin › System settings › Outbound traffic, through
 * the same writer, and leave one audit row. Taking effect needs no restart.
 */
final readonly class EgressAllowListEditor
{
    public const SETTING = 'EGRESS_ALLOW';
    public const ACTION_ADDED = 'egress.allow_added';
    public const ACTION_REMOVED = 'egress.allow_removed';

    private const LOCK_NAME = 'egress-allow-list';
    private const LOCK_TTL_SECONDS = 10.0;

    public function __construct(
        private EgressConfig $config,
        private SystemConfigWriter $writer,
        private AuditLogWriter $audit,
        private LockFactory $locks,
    ) {
    }

    /**
     * @return list<string> the administrator's list after the change
     *
     * @throws EgressAllowListException
     */
    public function add(string $rule, int $actorId): array
    {
        $label = self::label($rule);

        return $this->locked(function () use ($label, $actorId): array {
            $rules = $this->config->allowRules();
            if (null !== self::indexOf($rules, $label)) {
                return $rules;
            }

            $rules[] = $label;
            $this->store($rules, $actorId);
            $this->audit->record($actorId, self::ACTION_ADDED, 'egress_rule', $label);

            return $rules;
        });
    }

    /**
     * @return list<string> the administrator's list after the change
     *
     * @throws EgressAllowListException
     */
    public function remove(string $rule, int $actorId): array
    {
        $label = self::label($rule);

        return $this->locked(function () use ($label, $actorId): array {
            $rules = $this->config->allowRules();
            $index = self::indexOf($rules, $label);
            if (null === $index) {
                return $rules;
            }

            array_splice($rules, $index, 1);
            $this->store($rules, $actorId);
            $this->audit->record($actorId, self::ACTION_REMOVED, 'egress_rule', $label);

            return $rules;
        });
    }

    /**
     * Two administrators pressing Allow or Remove at once would otherwise
     * each save their own copy of the list, and one change would be lost.
     *
     * @param callable(): list<string> $edit
     *
     * @return list<string>
     *
     * @throws EgressAllowListException
     */
    private function locked(callable $edit): array
    {
        $lock = $this->locks->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            return $edit();
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws EgressAllowListException
     */
    private static function label(string $rule): string
    {
        $parsed = EgressRule::parse($rule);
        if (null === $parsed || !self::isHost($parsed->host)) {
            throw EgressAllowListException::invalidRule();
        }

        return $parsed->label();
    }

    /**
     * An IP address, with or without brackets, or a DNS name (one label is
     * fine: `redis`; Docker names may hold `_`). The leading dot of a suffix
     * rule is not part of it.
     */
    private static function isHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return true;
        }
        $host = ltrim($host, '.');

        return \strlen($host) <= 253
            && 1 === preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $host);
    }

    /**
     * @param list<string> $rules
     */
    private static function indexOf(array $rules, string $label): ?int
    {
        foreach ($rules as $index => $existing) {
            if (EgressRule::parse($existing)?->label() === $label) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<string> $rules
     *
     * @throws EgressAllowListException
     */
    private function store(array $rules, int $actorId): void
    {
        $result = $this->writer->write(
            self::SETTING,
            json_encode($rules, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            $actorId,
        );
        if (!$result['success']) {
            throw EgressAllowListException::notSaved();
        }
    }
}
