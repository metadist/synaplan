<?php

declare(strict_types=1);

namespace App\AI\Health;

/**
 * One thing worth waking an operator for, already aggregated per provider.
 *
 * Aggregation is the point: a revoked key breaks every model behind it at
 * once, and forty separate emails about the same key would train the operator
 * to ignore the alert channel. The quiet period is still per model, so a
 * different model that fails later the same day is its own message.
 */
final readonly class ModelHealthAlert
{
    public const KIND_OFFLINE = 'offline';
    public const KIND_DEGRADED = 'degraded';
    public const KIND_CREDENTIAL = 'credential';

    /**
     * @param string       $provider    internal service key; the open-incident key, so it
     *                                  must stay stable and must not be a display string
     * @param list<string> $modelNames  names of the affected models, same order as $modelIds
     * @param list<int>    $modelIds    catalog row ids. The quiet period is per id, so the
     *                                  same model is not reported again until it has elapsed
     * @param string|null  $displayName the provider's branded name, for the alert text
     */
    public function __construct(
        public string $kind,
        public string $provider,
        public array $modelNames,
        public string $reason,
        public ?string $displayName = null,
        public array $modelIds = [],
    ) {
    }

    /** The provider as it should appear in the alert, never the raw service key. */
    public function name(): string
    {
        return null === $this->displayName || '' === $this->displayName
            ? $this->provider
            : $this->displayName;
    }

    public function modelCount(): int
    {
        return count($this->modelNames);
    }

    /** Short one-liner used as the email subject and the Discord title. */
    public function headline(): string
    {
        return match ($this->kind) {
            self::KIND_CREDENTIAL => sprintf(
                '%s credentials rejected — %d model(s) unavailable',
                $this->name(),
                $this->modelCount()
            ),
            self::KIND_OFFLINE => sprintf(
                '%d %s model(s) no longer available',
                $this->modelCount(),
                $this->name()
            ),
            default => sprintf(
                '%d %s model(s) failing',
                $this->modelCount(),
                $this->name()
            ),
        };
    }

    /**
     * Told with every incident so a repeat of the same model is not expected
     * the same day, and a different model is not assumed to be covered.
     */
    public function quietPeriodNotice(): string
    {
        return 'No further message about these models for 24 hours. A different model is still reported the same day.';
    }

    /** What the operator is expected to do about it. */
    public function actionRequired(): string
    {
        return match ($this->kind) {
            self::KIND_CREDENTIAL => 'Check the API key and the account balance for this provider. Every model behind it is affected.',
            self::KIND_OFFLINE => 'The provider no longer offers these models. Pick replacements in the model settings.',
            default => 'The provider is answering but failing often. If this does not clear up on its own, switch to another model.',
        };
    }

    /** At most this many model names go into an alert body; the rest are summarised. */
    public function previewNames(int $limit = 10): string
    {
        if ($this->modelCount() <= $limit) {
            return implode(', ', $this->modelNames);
        }

        return implode(', ', array_slice($this->modelNames, 0, $limit))
            .sprintf(' and %d more', $this->modelCount() - $limit);
    }
}
