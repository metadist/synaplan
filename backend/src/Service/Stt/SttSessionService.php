<?php

declare(strict_types=1);

namespace App\Service\Stt;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Exception\RateLimitExceededException;
use App\Service\RateLimitService;
use App\Service\Stt\Exception\SttSessionClosedException;
use App\Service\Stt\Exception\SttSessionNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * External, API-key-scoped speech-to-text sessions.
 *
 * Local programs open a session per client (client 123 vs client 321 on the
 * same key), stream audio chunks, and read incremental transcripts via poll
 * or SSE. Each commit window is transcribed through {@see AiFacade} so every
 * SOUND2TEXT model (local whisper.cpp, Groq, OpenAI, Mistral, xAI, …) works.
 */
final readonly class SttSessionService
{
    public const MAX_OPEN_SESSIONS = 32;
    public const MAX_CHUNK_BYTES = 5_242_880;
    public const MAX_PENDING_BYTES = 8_388_608;
    public const DEFAULT_COMMIT_AFTER_BYTES = 96_000;
    public const DEFAULT_SAMPLE_RATE = 16_000;
    public const CLIENT_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    public function __construct(
        private SttSessionStore $store,
        private SttAudioAssembler $assembler,
        private SttModelResolver $modelResolver,
        private AiFacade $aiFacade,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{
     *     client_id?: string,
     *     model?: string|null,
     *     language?: string|null,
     *     prompt?: string|null,
     *     encoding?: string|null,
     *     sample_rate?: int,
     *     channels?: int,
     *     commit_after_bytes?: int,
     *     reuse?: bool
     * } $options
     */
    public function create(User $user, int $apiKeyId, array $options = []): SttSession
    {
        $clientId = $this->normalizeClientId($options['client_id'] ?? null);
        $reuse = (bool) ($options['reuse'] ?? false);

        if ($reuse) {
            $existing = $this->store->findOpenForClient($apiKeyId, $clientId);
            if (null !== $existing) {
                return $existing;
            }
        }

        if ($this->store->countOpenForApiKey($apiKeyId) >= self::MAX_OPEN_SESSIONS) {
            throw new \InvalidArgumentException(sprintf('This API key already has %d open transcription sessions. Close one first.', self::MAX_OPEN_SESSIONS));
        }

        $resolved = $this->modelResolver->resolve(
            $this->optionalString($options['model'] ?? null),
            (int) $user->getId(),
        );

        $encoding = $this->normalizeEncoding($options['encoding'] ?? null);
        $sampleRate = (int) ($options['sample_rate'] ?? self::DEFAULT_SAMPLE_RATE);
        $channels = (int) ($options['channels'] ?? 1);
        $commitAfter = (int) ($options['commit_after_bytes'] ?? self::DEFAULT_COMMIT_AFTER_BYTES);
        $language = $this->optionalString($options['language'] ?? null);
        $prompt = $this->optionalString($options['prompt'] ?? null);

        $now = time();
        $session = new SttSession(
            id: 'stt_sess_'.bin2hex(random_bytes(16)),
            clientId: $clientId,
            apiKeyId: $apiKeyId,
            userId: (int) $user->getId(),
            model: $resolved['displayModel'],
            provider: $resolved['provider'],
            modelId: $resolved['model_id'],
            language: $language,
            prompt: $prompt,
            status: SttSession::STATUS_OPEN,
            encoding: $encoding,
            sampleRate: max(8000, min(48000, $sampleRate)),
            channels: max(1, min(2, $channels)),
            commitAfterBytes: max(8_000, min(self::MAX_PENDING_BYTES, $commitAfter)),
            createdAt: $now,
            updatedAt: $now,
            expiresAt: $now + 7200,
        );
        $session->pushEvent('session.created', [
            'id' => $session->id,
            'client_id' => $session->clientId,
            'api_key_id' => $session->apiKeyId,
            'model' => $session->model,
        ]);

        $this->store->save($session);

        $this->logger->info('STT session created', [
            'session_id' => $session->id,
            'client_id' => $session->clientId,
            'api_key_id' => $session->apiKeyId,
            'user_id' => $session->userId,
            'model' => $session->model,
        ]);

        return $session;
    }

    public function getOwned(string $sessionId, int $apiKeyId): SttSession
    {
        $session = $this->store->get($sessionId);
        if (null === $session || $session->apiKeyId !== $apiKeyId) {
            throw new SttSessionNotFoundException($sessionId);
        }

        return $session;
    }

    /**
     * @return list<SttSession>
     */
    public function listOwned(int $apiKeyId, ?string $clientId = null, ?string $status = null): array
    {
        $normalizedClient = null;
        if (null !== $clientId && '' !== trim($clientId)) {
            $normalizedClient = $this->normalizeClientId($clientId);
        }

        $sessions = $this->store->listForApiKey($apiKeyId, $normalizedClient);
        if (null === $status || '' === $status) {
            return $sessions;
        }

        return array_values(array_filter(
            $sessions,
            static fn (SttSession $session): bool => $session->status === $status,
        ));
    }

    /**
     * @return array{session: SttSession, committed: bool, bytes_appended: int}
     */
    public function appendAudio(User $user, int $apiKeyId, string $sessionId, string $chunk, bool $commit = false): array
    {
        if ('' === $chunk) {
            throw new \InvalidArgumentException('Audio chunk is empty');
        }
        if (strlen($chunk) > self::MAX_CHUNK_BYTES) {
            throw new \InvalidArgumentException(sprintf('Audio chunk is too large (%d bytes, max %d)', strlen($chunk), self::MAX_CHUNK_BYTES));
        }

        /** @var array{session: SttSession, committed: bool, bytes_appended: int} $result */
        $result = $this->store->withLock($sessionId, function () use ($user, $apiKeyId, $sessionId, $chunk, $commit): array {
            $session = $this->getOwned($sessionId, $apiKeyId);
            $this->assertOpen($session);

            $pendingPath = $this->assembler->pendingPath($this->store->sessionDir($session->id));
            if ($session->pendingBytes + strlen($chunk) > self::MAX_PENDING_BYTES && $session->pendingBytes > 0) {
                $this->commitLocked($user, $apiKeyId, $session->id, false);
                $session = $this->getOwned($sessionId, $apiKeyId);
            }
            if ($session->pendingBytes + strlen($chunk) > self::MAX_PENDING_BYTES) {
                throw new \InvalidArgumentException('Audio chunk exceeds the pending buffer limit');
            }

            $written = $this->assembler->append($pendingPath, $chunk);
            $session = $this->getOwned($sessionId, $apiKeyId);
            $session->bytesReceived += $written;
            $session->pendingBytes = $this->assembler->pendingSize($pendingPath);
            $this->store->save($session);

            $shouldCommit = $commit || $session->pendingBytes >= $session->commitAfterBytes;
            if ($shouldCommit) {
                $session = $this->commitLocked($user, $apiKeyId, $session->id, false);
            }

            return [
                'session' => $session,
                'committed' => $shouldCommit,
                'bytes_appended' => $written,
            ];
        });

        return $result;
    }

    public function commit(User $user, int $apiKeyId, string $sessionId, bool $close = false): SttSession
    {
        /** @var SttSession $session */
        $session = $this->store->withLock($sessionId, function () use ($user, $apiKeyId, $sessionId, $close): SttSession {
            return $this->commitLocked($user, $apiKeyId, $sessionId, $close);
        });

        return $session;
    }

    private function commitLocked(User $user, int $apiKeyId, string $sessionId, bool $close): SttSession
    {
        $session = $this->getOwned($sessionId, $apiKeyId);
        $this->assertOpen($session);

        $pendingPath = $this->assembler->pendingPath($this->store->sessionDir($session->id));
        $pendingBytes = $this->assembler->pendingSize($pendingPath);

        if ($pendingBytes > 0) {
            $this->assertRateLimit($user);
            $tempFile = $this->assembler->buildTranscribeFile(
                $pendingPath,
                $session->encoding,
                $session->sampleRate,
                $session->channels,
            );

            try {
                $options = [
                    'provider' => $session->provider,
                    'model' => $session->model,
                ];
                if (null !== $session->language) {
                    $options['language'] = $session->language;
                }
                if (null !== $session->prompt) {
                    $options['prompt'] = $session->prompt;
                }

                $result = $this->aiFacade->transcribe($tempFile, $session->userId, $options);
            } finally {
                if (is_file($tempFile)) {
                    unlink($tempFile);
                }
            }

            $newText = trim((string) ($result['text'] ?? ''));
            $language = (string) ($result['language'] ?? $session->language ?? 'unknown');
            $duration = (float) ($result['duration'] ?? 0);

            $session = $this->getOwned($sessionId, $apiKeyId);
            if ('' !== $newText) {
                $session->text = trim($session->text.' '.$newText);
            }
            $session->durationSeconds += max(0.0, $duration);
            if ('unknown' !== $language && '' !== $language && null === $session->language) {
                $session->language = $language;
            }

            $segment = [
                'id' => 'seg_'.($session->cursor + 1),
                'text' => $newText,
                'is_final' => true,
                'language' => $language,
                'duration' => $duration,
                'created_at' => time(),
            ];
            $session->segments[] = $segment;
            $session->pushEvent('transcript', [
                'id' => $segment['id'],
                'session_id' => $session->id,
                'client_id' => $session->clientId,
                'api_key_id' => $session->apiKeyId,
                'text' => $newText,
                'full_text' => $session->text,
                'is_final' => true,
                'language' => $language,
                'duration' => $duration,
                'model' => $session->model,
            ]);

            $this->assembler->clearPending($pendingPath);
            $session->pendingBytes = 0;

            $this->logger->info('STT session committed', [
                'session_id' => $session->id,
                'client_id' => $session->clientId,
                'api_key_id' => $session->apiKeyId,
                'bytes' => $pendingBytes,
                'text_length' => strlen($newText),
                'model' => $session->model,
            ]);
        }

        if ($close) {
            $session = $this->closeSession($session);
        } else {
            $this->store->save($session);
        }

        return $session;
    }

    public function close(int $apiKeyId, string $sessionId): SttSession
    {
        /** @var SttSession $session */
        $session = $this->store->withLock($sessionId, function () use ($apiKeyId, $sessionId): SttSession {
            $session = $this->getOwned($sessionId, $apiKeyId);
            if (!$session->isOpen()) {
                return $session;
            }

            return $this->closeSession($session);
        });

        return $session;
    }

    /**
     * One-shot file transcription (OpenAI `/v1/audio/transcriptions`).
     *
     * @param array{model?: string|null, language?: string|null, prompt?: string|null, client_id?: string|null} $options
     *
     * @return array<string, mixed>
     */
    public function transcribeFile(User $user, int $apiKeyId, string $audioPath, array $options = []): array
    {
        if (!is_file($audioPath)) {
            throw new \InvalidArgumentException('Audio file is missing');
        }

        $this->assertRateLimit($user);
        $resolved = $this->modelResolver->resolve(
            $this->optionalString($options['model'] ?? null),
            (int) $user->getId(),
        );

        $transcribeOptions = [
            'provider' => $resolved['provider'],
            'model' => $resolved['providerModelId'],
        ];
        $language = $this->optionalString($options['language'] ?? null);
        $prompt = $this->optionalString($options['prompt'] ?? null);
        if (null !== $language) {
            $transcribeOptions['language'] = $language;
        }
        if (null !== $prompt) {
            $transcribeOptions['prompt'] = $prompt;
        }

        $result = $this->aiFacade->transcribe($audioPath, (int) $user->getId(), $transcribeOptions);
        $clientId = null;
        $requestedClient = $this->optionalString($options['client_id'] ?? null);
        if (null !== $requestedClient) {
            $clientId = $this->normalizeClientId($requestedClient);
        }

        return [
            'id' => 'transcribe_'.bin2hex(random_bytes(12)),
            'object' => 'transcription',
            'text' => (string) ($result['text'] ?? ''),
            'language' => (string) ($result['language'] ?? $language ?? 'unknown'),
            'duration' => (float) ($result['duration'] ?? 0),
            'model' => $resolved['displayModel'],
            'provider' => $resolved['provider'],
            'model_id' => $resolved['model_id'],
            'client_id' => $clientId,
            'api_key_id' => $apiKeyId,
            'user_id' => (int) $user->getId(),
            'segments' => is_array($result['segments'] ?? null) ? $result['segments'] : [],
        ];
    }

    private function closeSession(SttSession $session): SttSession
    {
        $session->status = SttSession::STATUS_CLOSED;
        $session->pushEvent('done', [
            'session_id' => $session->id,
            'client_id' => $session->clientId,
            'api_key_id' => $session->apiKeyId,
            'text' => $session->text,
        ]);
        $this->store->save($session);
        $this->store->deleteAudio($session->id);

        return $session;
    }

    private function assertOpen(SttSession $session): void
    {
        if (!$session->isOpen()) {
            throw new SttSessionClosedException($session->id);
        }
    }

    private function assertRateLimit(User $user): void
    {
        $check = $this->rateLimitService->checkLimit($user, 'FILE_ANALYSIS');
        if (!($check['allowed'] ?? false)) {
            throw new RateLimitExceededException('FILE_ANALYSIS', (int) ($check['used'] ?? 0), (int) ($check['limit'] ?? 0));
        }
    }

    private function normalizeClientId(?string $clientId): string
    {
        $value = trim((string) $clientId);
        if ('' === $value) {
            return 'default';
        }
        if (1 !== preg_match(self::CLIENT_ID_PATTERN, $value)) {
            throw new \InvalidArgumentException('client_id must be 1–64 characters of letters, digits, `.`, `_`, `-`, or `:`');
        }

        return $value;
    }

    private function normalizeEncoding(?string $encoding): string
    {
        $value = strtolower(trim((string) $encoding));
        if ('' === $value) {
            return SttAudioAssembler::ENCODING_AUTO;
        }
        if (!in_array($value, SttAudioAssembler::encodings(), true)) {
            throw new \InvalidArgumentException('encoding must be one of: '.implode(', ', SttAudioAssembler::encodings()));
        }

        return $value;
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
