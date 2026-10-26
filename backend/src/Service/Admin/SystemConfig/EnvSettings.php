<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The environment settings of the instance: the value the running process
 * sees ($_ENV, then getenv()) and edits to the .env file the next start
 * reads. Every edit backs the file up first (the five newest backups stay)
 * and replaces it in one rename — or, when the file cannot be renamed over
 * (production mounts .env as a single-file bind mount, and renaming over a
 * mounted file answers "Device or resource busy"), writes it in place so
 * the mounted file keeps its identity.
 */
final readonly class EnvSettings
{
    private const BACKUPS_KEPT = 5;
    private const BACKUPS_LISTED = 10;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private LoggerInterface $logger,
    ) {
    }

    public function value(string $key): ?string
    {
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        $value = getenv($key);

        return false !== $value ? $value : null;
    }

    /**
     * Sets KEY=value in .env, replacing its line or appending one.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    public function write(string $key, string $value): array
    {
        $envFile = $this->envFile();
        if (!file_exists($envFile)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => '.env file not found'];
        }

        if (null === $this->backup()) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to create backup'];
        }

        $content = file_get_contents($envFile);
        if (false === $content) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to read .env file'];
        }

        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $replacement = $key.'='.$this->escape($value);
        $updated = preg_match($pattern, $content)
            ? preg_replace($pattern, $replacement, $content)
            : rtrim($content)."\n".$replacement."\n";

        return $this->replace($envFile, (string) $updated);
    }

    /**
     * Removes the KEY= line from .env. An absent key is already clear and
     * needs no restart.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    public function remove(string $key): array
    {
        $envFile = $this->envFile();
        if (!file_exists($envFile)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => '.env file not found'];
        }

        $content = file_get_contents($envFile);
        if (false === $content) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to read .env file'];
        }

        $pattern = '/^'.preg_quote($key, '/').'=.*\n?/m';
        if (!preg_match($pattern, $content)) {
            return ['success' => true, 'requiresRestart' => false];
        }

        if (null === $this->backup()) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to create backup'];
        }

        $updated = preg_replace($pattern, '', $content);
        if (!\is_string($updated)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to update .env file'];
        }

        return $this->replace($envFile, $updated);
    }

    /**
     * @return list<array{id: string, timestamp: string, size: int}> the newest first
     */
    public function backups(): array
    {
        $files = is_dir($this->backupDir()) ? glob($this->backupDir().'/.env.backup.*') : false;
        if (false === $files) {
            return [];
        }

        $backups = [];
        foreach ($files as $file) {
            if (preg_match('/\.env\.backup\.(\d{8}_\d{6})$/', basename($file), $matches)) {
                $dt = \DateTime::createFromFormat('Ymd_His', $matches[1]);
                $backups[] = [
                    'id' => $matches[1],
                    'timestamp' => false !== $dt ? $dt->format('Y-m-d H:i:s') : $matches[1],
                    'size' => filesize($file) ?: 0,
                ];
            }
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['id'], $a['id']));

        return \array_slice($backups, 0, self::BACKUPS_LISTED);
    }

    /**
     * Copies a backup over .env, after backing up the current file.
     *
     * @return array{success: bool, message: string}
     */
    public function restore(string $backupId): array
    {
        $backupFile = $this->backupDir().'/.env.backup.'.$backupId;
        if (!file_exists($backupFile)) {
            return ['success' => false, 'message' => 'Backup not found'];
        }

        $this->backup();

        if (!copy($backupFile, $this->envFile())) {
            return ['success' => false, 'message' => 'Failed to restore backup'];
        }

        $this->logger->info('Restored .env from backup', ['backup_id' => $backupId]);

        return ['success' => true, 'message' => 'Backup restored successfully'];
    }

    /**
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    private function replace(string $envFile, string $content): array
    {
        $tempFile = $envFile.'.tmp';
        if (false !== $this->attempt(static fn () => file_put_contents($tempFile, $content))
            && true === $this->attempt(static fn () => rename($tempFile, $envFile))) {
            return ['success' => true, 'requiresRestart' => true];
        }
        $this->attempt(static fn () => unlink($tempFile));

        // The atomic rename is unavailable: production mounts .env as a
        // single file (synaplan-platform binds the NFS .env into the
        // container), and renaming over a mounted file fails with EBUSY.
        // The backup taken before every edit is the safety net, so write
        // the new content into the existing file instead — the mounted
        // file, its inode and its permissions stay as they are.
        $this->logger->info('EnvSettings: rename route unavailable for .env, writing in place');
        if (false === $this->attempt(static fn () => file_put_contents($envFile, $content))) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to save .env file'];
        }

        return ['success' => true, 'requiresRestart' => true];
    }

    /**
     * Runs one filesystem step and answers its result, or false when the
     * step failed. A failed step here is an expected route (the rename
     * over a mounted file), not an error worth a log line or a crashed
     * request, so its warning is swallowed and the caller falls back.
     */
    private function attempt(callable $operation): mixed
    {
        set_error_handler(static fn () => true);
        try {
            return $operation();
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return string|null the backup file, or null when there was nothing to copy or it failed
     */
    private function backup(): ?string
    {
        $envFile = $this->envFile();
        if (!file_exists($envFile)) {
            return null;
        }

        $backupDir = $this->backupDir();
        if (!is_dir($backupDir) && !mkdir($backupDir, 0o755, true)) {
            return null;
        }

        $backupFile = $backupDir.'/.env.backup.'.date('Ymd_His');
        if (!copy($envFile, $backupFile)) {
            return null;
        }

        $this->dropOldBackups($backupDir);

        return $backupFile;
    }

    private function dropOldBackups(string $dir): void
    {
        $files = glob($dir.'/.env.backup.*');
        if (false === $files || \count($files) <= self::BACKUPS_KEPT) {
            return;
        }

        rsort($files);
        foreach (\array_slice($files, self::BACKUPS_KEPT) as $file) {
            @unlink($file);
        }
    }

    /**
     * A value with whitespace, #, quotes or = is double-quoted, inner double
     * quotes escaped.
     */
    private function escape(string $value): string
    {
        if (preg_match('/[\s#\'"]/', $value) || str_contains($value, '=')) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }

    private function envFile(): string
    {
        return $this->projectDir.'/.env';
    }

    private function backupDir(): string
    {
        return $this->projectDir.'/var/env-backups';
    }
}
