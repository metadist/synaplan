<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\Service\Admin\SystemConfig\SystemConfigWriter;
use App\Service\Iam\AuditLogWriter;

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

    public function __construct(
        private EgressConfig $config,
        private SystemConfigWriter $writer,
        private AuditLogWriter $audit,
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
        $rules = $this->config->allowRules();
        if (null !== self::indexOf($rules, $label)) {
            return $rules;
        }

        $rules[] = $label;
        $this->store($rules, $actorId);
        $this->audit->record($actorId, self::ACTION_ADDED, 'egress_rule', $label);

        return $rules;
    }

    /**
     * @return list<string> the administrator's list after the change
     *
     * @throws EgressAllowListException
     */
    public function remove(string $rule, int $actorId): array
    {
        $label = self::label($rule);
        $rules = $this->config->allowRules();
        $index = self::indexOf($rules, $label);
        if (null === $index) {
            return $rules;
        }

        array_splice($rules, $index, 1);
        $this->store($rules, $actorId);
        $this->audit->record($actorId, self::ACTION_REMOVED, 'egress_rule', $label);

        return $rules;
    }

    /**
     * @throws EgressAllowListException
     */
    private static function label(string $rule): string
    {
        $parsed = EgressRule::parse($rule);
        if (null === $parsed) {
            throw EgressAllowListException::invalidRule();
        }

        return $parsed->label();
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
