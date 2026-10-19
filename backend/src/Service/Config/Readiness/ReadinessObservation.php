<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

/**
 * What one probe saw right now, before the tracker turns it into a state
 * with a history (`starting` → `failed` after ten minutes, …).
 */
final readonly class ReadinessObservation
{
    public const OFF = 'off';
    public const READY = 'ready';
    public const DOWN = 'down';
    public const DOWNLOADING = 'downloading';
    public const BROKEN = 'broken';

    private function __construct(
        public string $kind,
        public ?int $percent = null,
        public ?string $model = null,
    ) {
    }

    public static function off(): self
    {
        return new self(self::OFF);
    }

    public static function ready(): self
    {
        return new self(self::READY);
    }

    /** Configured, but did not answer the probe. */
    public static function down(): self
    {
        return new self(self::DOWN);
    }

    public static function downloading(?int $percent = null, ?string $model = null): self
    {
        $percent = null === $percent ? null : max(0, min(100, $percent));

        return new self(self::DOWNLOADING, $percent, $model);
    }

    /** It reported an error of its own (failed download, missing binary). */
    public static function broken(): self
    {
        return new self(self::BROKEN);
    }

    public static function fromHealth(bool $configured, bool $healthy): self
    {
        if (!$configured) {
            return self::off();
        }

        return $healthy ? self::ready() : self::down();
    }
}
