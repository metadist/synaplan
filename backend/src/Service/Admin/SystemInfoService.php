<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * System Info Service.
 *
 * Collects non-sensitive runtime/server diagnostics for the admin dashboard:
 * PHP version, memory settings + usage, request limits, and disk space for the
 * application directory.
 *
 * SECURITY: Returns only coarse, non-sensitive runtime facts. It deliberately
 * does NOT expose phpinfo(), loaded credentials, env values, or absolute paths
 * beyond the app root — safe to surface to ROLE_ADMIN in any environment.
 */
final readonly class SystemInfoService
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    /**
     * @return array{
     *     php: array{version: string, sapi: string, opcacheEnabled: bool},
     *     memory: array{limit: string, limitBytes: int, currentUsageBytes: int, peakUsageBytes: int},
     *     limits: array{uploadMaxFilesize: string, postMaxSize: string, maxExecutionTime: int},
     *     disk: array{freeBytes: int|null, totalBytes: int|null, usedBytes: int|null, usedPercent: float|null},
     *     server: array{os: string, software: string|null, hostname: string|null},
     *     serverTime: string
     * }
     */
    public function collect(): array
    {
        $free = @disk_free_space($this->projectDir);
        $total = @disk_total_space($this->projectDir);
        $freeBytes = is_float($free) ? (int) $free : null;
        $totalBytes = is_float($total) ? (int) $total : null;

        $usedBytes = null;
        $usedPercent = null;
        if (null !== $freeBytes && null !== $totalBytes && $totalBytes > 0) {
            $usedBytes = $totalBytes - $freeBytes;
            $usedPercent = round($usedBytes / $totalBytes * 100, 1);
        }

        return [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'opcacheEnabled' => $this->isOpcacheEnabled(),
            ],
            'memory' => [
                'limit' => (string) ini_get('memory_limit'),
                'limitBytes' => $this->parseBytes((string) ini_get('memory_limit')),
                'currentUsageBytes' => memory_get_usage(true),
                'peakUsageBytes' => memory_get_peak_usage(true),
            ],
            'limits' => [
                'uploadMaxFilesize' => (string) ini_get('upload_max_filesize'),
                'postMaxSize' => (string) ini_get('post_max_size'),
                'maxExecutionTime' => (int) ini_get('max_execution_time'),
            ],
            'disk' => [
                'freeBytes' => $freeBytes,
                'totalBytes' => $totalBytes,
                'usedBytes' => $usedBytes,
                'usedPercent' => $usedPercent,
            ],
            'server' => [
                'os' => PHP_OS_FAMILY,
                'software' => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : null,
                'hostname' => false !== gethostname() ? gethostname() : null,
            ],
            'serverTime' => date('c'),
        ];
    }

    private function isOpcacheEnabled(): bool
    {
        return \extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable');
    }

    /**
     * Parse a PHP shorthand byte value (e.g. "512M", "2G") into bytes.
     * Returns -1 for the "unlimited" sentinel.
     */
    private function parseBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '-1' === $value) {
            return -1;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
