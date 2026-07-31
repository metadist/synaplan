<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

/**
 * Reads the Ollama model-download status written by the backend entrypoint
 * (`var/ollama-download.json`) so the UI can show pull progress.
 *
 * The entrypoint owns writes; this service only reads. When the file is missing
 * the status is "idle" (no auto-download running, or already finished before
 * the file existed).
 */
final readonly class LocalAiDownloadStatusService
{
    public const STATUS_IDLE = 'idle';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    /**
     * An in-progress status this old is treated as idle. The entrypoint writes a
     * milestone every 10%, so a longer gap means the pull died with the
     * container — without this the UI would show a download that never ends.
     */
    private const STALE_AFTER_SECONDS = 1800;

    public function __construct(
        private string $statusFilePath,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     currentModel: ?string,
     *     percent: ?int,
     *     message: ?string,
     *     models: list<array{name: string, state: string, percent: ?int}>,
     *     updatedAt: ?string
     * }
     */
    public function getStatus(): array
    {
        $empty = [
            'status' => self::STATUS_IDLE,
            'currentModel' => null,
            'percent' => null,
            'message' => null,
            'models' => [],
            'updatedAt' => null,
        ];

        if (!is_file($this->statusFilePath) || !is_readable($this->statusFilePath)) {
            return $empty;
        }

        $raw = @file_get_contents($this->statusFilePath);
        if (false === $raw || '' === trim($raw)) {
            return $empty;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $empty;
        }

        if (!is_array($decoded)) {
            return $empty;
        }

        $status = is_string($decoded['status'] ?? null) ? $decoded['status'] : self::STATUS_IDLE;
        $models = [];
        if (isset($decoded['models']) && is_array($decoded['models'])) {
            foreach ($decoded['models'] as $row) {
                if (!is_array($row) || !is_string($row['name'] ?? null)) {
                    continue;
                }
                $models[] = [
                    'name' => $row['name'],
                    'state' => is_string($row['state'] ?? null) ? $row['state'] : 'unknown',
                    'percent' => isset($row['percent']) && is_numeric($row['percent']) ? (int) $row['percent'] : null,
                ];
            }
        }

        $percent = isset($decoded['percent']) && is_numeric($decoded['percent']) ? (int) $decoded['percent'] : null;
        $updatedAt = is_string($decoded['updatedAt'] ?? null) ? $decoded['updatedAt'] : null;

        if (in_array($status, [self::STATUS_WAITING, self::STATUS_DOWNLOADING], true)
            && $this->isStale($updatedAt)) {
            return $empty;
        }

        return [
            'status' => $status,
            'currentModel' => is_string($decoded['currentModel'] ?? null) ? $decoded['currentModel'] : null,
            'percent' => $percent,
            'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : null,
            'models' => $models,
            'updatedAt' => $updatedAt,
        ];
    }

    private function isStale(?string $updatedAt): bool
    {
        if (null === $updatedAt) {
            return true;
        }

        try {
            $written = new \DateTimeImmutable($updatedAt);
        } catch (\Exception) {
            return true;
        }

        return (time() - $written->getTimestamp()) > self::STALE_AFTER_SECONDS;
    }

    public function isActivelyDownloading(): bool
    {
        $status = $this->getStatus()['status'];

        return self::STATUS_WAITING === $status || self::STATUS_DOWNLOADING === $status;
    }
}
