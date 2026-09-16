<?php

declare(strict_types=1);

namespace App\AI\Health;

/**
 * How a failed AI call should be counted by the health monitor.
 *
 * The distinction is what keeps self-healing from doing damage: a rate limit
 * and a retired model both surface as "the call failed", but reacting to them
 * the same way would switch off the busiest model during a traffic spike.
 */
enum FailureKind: string
{
    /** Provider hiccup: rate limit, timeout, 5xx, open circuit. Recovers on its own. */
    case Transient = 'transient';

    /** The model itself is gone or permanently rejected: retired, renamed, unknown to the provider. */
    case Permanent = 'permanent';

    /** Credentials or billing: bad key, revoked key, exhausted credits. Hits every model of that provider. */
    case Credential = 'credential';

    /** Caused by the request, not the model: content filter, context length exceeded. */
    case UserError = 'user_error';

    /** The user pressed stop. The provider was healthy. */
    case Cancelled = 'cancelled';

    /**
     * Does this count towards the model's own error rate?
     *
     * User errors and cancellations say nothing about model health, and a
     * credential problem belongs to the provider — attributing it to whichever
     * model happened to be called first would disable an innocent model.
     */
    public function countsAgainstModel(): bool
    {
        return self::Transient === $this || self::Permanent === $this;
    }

    /**
     * May the automation switch the model off for this?
     *
     * Only for failures that will still be there after a retry. Everything
     * else has to heal on its own or be fixed by an operator.
     */
    public function justifiesAutoDisable(): bool
    {
        return self::Permanent === $this;
    }

    /** Does this affect every model of the provider rather than a single one? */
    public function isProviderWide(): bool
    {
        return self::Credential === $this;
    }

    /** Operator-facing label; the UI translates on top of this. */
    public function label(): string
    {
        return match ($this) {
            self::Transient => 'Temporarily unavailable',
            self::Permanent => 'No longer offered by the provider',
            self::Credential => 'Credentials or credit problem',
            self::UserError => 'Rejected because of the request',
            self::Cancelled => 'Cancelled by the user',
        };
    }
}
