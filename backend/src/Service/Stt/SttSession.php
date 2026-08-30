<?php

declare(strict_types=1);

namespace App\Service\Stt;

/**
 * In-memory / persisted state of an external transcription session.
 *
 * One API key can own many sessions (client 123 and client 321 on the same key).
 *
 * @phpstan-type SttSegment array{
 *     id: string,
 *     text: string,
 *     is_final: bool,
 *     language: string,
 *     duration: float,
 *     created_at: int
 * }
 * @phpstan-type SttEvent array{
 *     cursor: int,
 *     type: string,
 *     payload: array<string, mixed>
 * }
 */
final class SttSession
{
    public const OBJECT = 'transcription.session';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    /**
     * @param list<SttSegment> $segments
     * @param list<SttEvent>   $events
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public int $apiKeyId,
        public int $userId,
        public string $model,
        public string $provider,
        public ?int $modelId,
        public ?string $language,
        public ?string $prompt,
        public string $status,
        public string $encoding,
        public int $sampleRate,
        public int $channels,
        public int $commitAfterBytes,
        public int $createdAt,
        public int $updatedAt,
        public int $expiresAt,
        public int $bytesReceived = 0,
        public int $pendingBytes = 0,
        public float $durationSeconds = 0.0,
        public string $text = '',
        public array $segments = [],
        public array $events = [],
        public int $cursor = 0,
    ) {
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * @return list<SttEvent>
     */
    public function eventsAfter(int $cursor): array
    {
        $out = [];
        foreach ($this->events as $event) {
            if ($event['cursor'] > $cursor) {
                $out[] = $event;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function pushEvent(string $type, array $payload): void
    {
        ++$this->cursor;
        $this->events[] = [
            'cursor' => $this->cursor,
            'type' => $type,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeEvents = false): array
    {
        $data = [
            'id' => $this->id,
            'object' => self::OBJECT,
            'client_id' => $this->clientId,
            'api_key_id' => $this->apiKeyId,
            'user_id' => $this->userId,
            'model' => $this->model,
            'provider' => $this->provider,
            'model_id' => $this->modelId,
            'language' => $this->language,
            'status' => $this->status,
            'encoding' => $this->encoding,
            'sample_rate' => $this->sampleRate,
            'channels' => $this->channels,
            'commit_after_bytes' => $this->commitAfterBytes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'expires_at' => $this->expiresAt,
            'bytes_received' => $this->bytesReceived,
            'pending_bytes' => $this->pendingBytes,
            'duration' => $this->durationSeconds,
            'text' => $this->text,
            'segments' => $this->segments,
            'cursor' => $this->cursor,
        ];

        if ($includeEvents) {
            $data['events'] = $this->events;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        return $this->toPublicArray(true) + [
            'prompt' => $this->prompt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromStorageArray(array $data): self
    {
        $segments = [];
        if (isset($data['segments']) && is_array($data['segments'])) {
            foreach ($data['segments'] as $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                $segments[] = [
                    'id' => (string) ($segment['id'] ?? ''),
                    'text' => (string) ($segment['text'] ?? ''),
                    'is_final' => (bool) ($segment['is_final'] ?? true),
                    'language' => (string) ($segment['language'] ?? 'unknown'),
                    'duration' => (float) ($segment['duration'] ?? 0),
                    'created_at' => (int) ($segment['created_at'] ?? 0),
                ];
            }
        }

        $events = [];
        if (isset($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $payload = $event['payload'] ?? [];
                $events[] = [
                    'cursor' => (int) ($event['cursor'] ?? 0),
                    'type' => (string) ($event['type'] ?? ''),
                    'payload' => is_array($payload) ? $payload : [],
                ];
            }
        }

        $language = $data['language'] ?? null;
        $prompt = $data['prompt'] ?? null;
        $modelId = $data['model_id'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            clientId: (string) ($data['client_id'] ?? 'default'),
            apiKeyId: (int) ($data['api_key_id'] ?? 0),
            userId: (int) ($data['user_id'] ?? 0),
            model: (string) ($data['model'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            modelId: is_numeric($modelId) ? (int) $modelId : null,
            language: is_string($language) && '' !== $language ? $language : null,
            prompt: is_string($prompt) && '' !== $prompt ? $prompt : null,
            status: (string) ($data['status'] ?? self::STATUS_OPEN),
            encoding: (string) ($data['encoding'] ?? 'auto'),
            sampleRate: (int) ($data['sample_rate'] ?? 16000),
            channels: (int) ($data['channels'] ?? 1),
            commitAfterBytes: (int) ($data['commit_after_bytes'] ?? 96000),
            createdAt: (int) ($data['created_at'] ?? 0),
            updatedAt: (int) ($data['updated_at'] ?? 0),
            expiresAt: (int) ($data['expires_at'] ?? 0),
            bytesReceived: (int) ($data['bytes_received'] ?? 0),
            pendingBytes: (int) ($data['pending_bytes'] ?? 0),
            durationSeconds: (float) ($data['duration'] ?? 0),
            text: (string) ($data['text'] ?? ''),
            segments: $segments,
            events: $events,
            cursor: (int) ($data['cursor'] ?? 0),
        );
    }
}
