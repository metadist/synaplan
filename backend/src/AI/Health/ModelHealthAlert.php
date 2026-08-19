<?php

declare(strict_types=1);

namespace App\AI\Health;

/**
 * One thing worth waking an operator for, already aggregated per provider.
 *
 * Aggregation is the point: a revoked OpenAI key breaks every OpenAI model at
 * once, and forty separate emails about the same key would train the operator
 * to ignore the alert channel.
 */
final readonly class ModelHealthAlert
{
    public const KIND_OFFLINE = 'offline';
    public const KIND_DEGRADED = 'degraded';
    public const KIND_CREDENTIAL = 'credential';

    /**
     * @param list<string> $modelNames names of the affected models, for the alert body
     */
    public function __construct(
        public string $kind,
        public string $provider,
        public array $modelNames,
        public string $reason,
    ) {
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
                $this->provider,
                $this->modelCount()
            ),
            self::KIND_OFFLINE => sprintf(
                '%d %s model(s) no longer available',
                $this->modelCount(),
                $this->provider
            ),
            default => sprintf(
                '%d %s model(s) failing',
                $this->modelCount(),
                $this->provider
            ),
        };
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
