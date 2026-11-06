<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command;

/**
 * Non-secret, per-user plugin defaults (mirrors the manifest config group
 * P_synafastbill). Credentials are deliberately NOT part of this object.
 */
final readonly class PluginConfig
{
    public function __construct(
        public string $language = 'en',
        public string $currency = 'EUR',
        public float $vatPercent = 19.0,
        public string $unit = 'hours',
    ) {
    }

    public function language(): string
    {
        return in_array($this->language, ['en', 'de', 'es', 'tr'], true) ? $this->language : 'en';
    }
}
