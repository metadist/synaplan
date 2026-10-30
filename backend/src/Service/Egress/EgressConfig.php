<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\Repository\ConfigRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads `EGRESS.MODE` and `EGRESS.ALLOW`, and the proxy environment.
 * An empty `EGRESS_PROXY` leaves Symfony's own `HTTPS_PROXY` / `HTTP_PROXY`
 * behaviour alone. A set value overrides them.
 */
final class EgressConfig implements ResetInterface
{
    public const CONFIG_GROUP = 'EGRESS';
    public const KEY_MODE = 'MODE';
    public const KEY_ALLOW = 'ALLOW';

    private static ?EgressMode $modeOverride = null;

    /** @var list<string>|null */
    private static ?array $allowOverride = null;

    public function __construct(
        private readonly ?ConfigRepository $configRepository = null,
    ) {
    }

    public static function setModeOverride(?EgressMode $mode): void
    {
        self::$modeOverride = $mode;
    }

    /**
     * @param list<string>|null $rules
     */
    public static function setAllowOverride(?array $rules): void
    {
        self::$allowOverride = $rules;
    }

    public function mode(): EgressMode
    {
        if (null !== self::$modeOverride) {
            return self::$modeOverride;
        }
        if (null === $this->configRepository) {
            return EgressMode::Off;
        }
        try {
            $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_MODE);
        } catch (\Throwable) {
            return EgressMode::Off;
        }

        return EgressMode::fromRaw(\is_string($raw) ? $raw : null);
    }

    /**
     * Administrator entries. JSON array, or one host per line.
     *
     * @return list<string>
     */
    public function allowRules(): array
    {
        if (null !== self::$allowOverride) {
            return self::$allowOverride;
        }
        if (null === $this->configRepository) {
            return [];
        }
        try {
            $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ALLOW);
        } catch (\Throwable) {
            return [];
        }

        return self::parseAllow(\is_string($raw) ? $raw : '');
    }

    public function gateway(): bool
    {
        $value = getenv('EGRESS_GATEWAY');

        return \is_string($value) && \in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    public function proxy(): ?string
    {
        $value = getenv('EGRESS_PROXY');
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    public function noProxy(): ?string
    {
        foreach (['EGRESS_NO_PROXY', 'NO_PROXY', 'no_proxy'] as $name) {
            $value = getenv($name);
            if (\is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Hosts named by the no-proxy list, so `enforce` does not refuse the
     * services the proxy itself is told to skip.
     *
     * @return list<string>
     */
    public function noProxyHosts(): array
    {
        $raw = $this->noProxy();
        if (null === $raw) {
            return [];
        }
        $hosts = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $part) {
            $part = strtolower(trim($part));
            if ('' === $part || str_contains($part, '://')) {
                continue;
            }
            $hosts[] = $part;
        }

        return $hosts;
    }

    public function reset(): void
    {
        self::$modeOverride = null;
        self::$allowOverride = null;
    }

    /**
     * @return list<string>
     */
    public static function parseAllow(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }
        if (str_starts_with($raw, '[')) {
            try {
                $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            if (!\is_array($decoded)) {
                return [];
            }
            $rules = [];
            foreach ($decoded as $item) {
                if (\is_string($item) && '' !== trim($item)) {
                    $rules[] = trim($item);
                }
            }

            return $rules;
        }

        $rules = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ('' !== $part) {
                $rules[] = $part;
            }
        }

        return $rules;
    }
}
