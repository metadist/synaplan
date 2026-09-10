<?php

declare(strict_types=1);

namespace App\Module\Contract;

/**
 * Live state of one module for the admin Feature status page and the
 * capability inventory.
 *
 * `configured` is the module's own `isConfigured()`; `healthy` is only
 * meaningful when configured (an absent module is neither healthy nor
 * unhealthy). `details` carries the feature-specific extras the status page
 * already shows today (url, version, models_available, …) so the existing
 * JSON contract can be reproduced byte for byte.
 */
final readonly class ModuleStatus
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public bool $configured,
        public bool $healthy,
        public string $message,
        public array $details = [],
    ) {
    }

    public static function absent(string $message): self
    {
        return new self(configured: false, healthy: false, message: $message);
    }

    /**
     * Maps onto CapabilityState: configured+healthy → available,
     * configured+unhealthy → needs_setup, absent → absent.
     */
    public function state(): string
    {
        if (!$this->configured) {
            return 'absent';
        }

        return $this->healthy ? 'available' : 'needs_setup';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'healthy' => $this->healthy,
            'state' => $this->state(),
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
