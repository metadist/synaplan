<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

/**
 * Minimal file-backed snapshot store for the routing characterization harness.
 *
 * The repo has no snapshot-assertion library (verified Sprint 0), and pulling a
 * dependency in just for this would need sign-off. This helper is deliberately
 * tiny: it persists a map of {caseId => normalized routing result} as pretty,
 * key-sorted JSON so a regression shows up as a readable git/PHPUnit diff.
 *
 * Recording: set env UPDATE_ROUTING_SNAPSHOTS=1 to (re)write the baseline.
 * Otherwise a missing baseline is a hard failure (so CI never passes vacuously).
 */
final class RoutingSnapshot
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public static function updateMode(): bool
    {
        $flag = getenv('UPDATE_ROUTING_SNAPSHOTS');

        return false !== $flag && '' !== $flag && '0' !== $flag;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $raw = file_get_contents($this->path);
        if (false === $raw) {
            throw new \RuntimeException("Cannot read routing snapshot: {$this->path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function write(array $data): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create snapshot dir: {$dir}");
        }

        self::ksortRecursive($data);

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        file_put_contents($this->path, $json."\n");
    }

    /**
     * Stable, human-diffable JSON for a single case (keys sorted recursively).
     *
     * @param array<string, mixed> $value
     */
    public static function encodeCase(array $value): string
    {
        self::ksortRecursive($value);

        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);
    }
}
