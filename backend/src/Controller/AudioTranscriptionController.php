<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Service\Exception\RateLimitExceededException;
use App\Service\Stt\Exception\SttModelNotFoundException;
use App\Service\Stt\Exception\SttSessionClosedException;
use App\Service\Stt\Exception\SttSessionNotFoundException;
use App\Service\Stt\SttModelResolver;
use App\Service\Stt\SttSession;
use App\Service\Stt\SttSessionService;
use App\Service\Stt\SttSseWriter;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * External speech-to-text: OpenAI-compatible one-shot plus session streaming.
 *
 * Lives on `/v1/audio` (API-key firewall) so a local program can transcribe
 * with any configured SOUND2TEXT model and keep client 123 vs client 321
 * apart on the same key.
 */
#[OA\Tag(name: 'Audio Transcription', description: 'External speech-to-text (one-shot and streaming sessions)')]
class AudioTranscriptionController extends AbstractController
{
    public function __construct(
        private SttSessionService $sttSessionService,
        private SttModelResolver $sttModelResolver,
        private SttSseWriter $sseWriter,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/audio/models', name: 'openai_audio_models', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/audio/models',
        summary: 'List speech-to-text models',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Response(response: 200, description: 'SOUND2TEXT models')]
    public function listModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $this->sttModelResolver->listModels(),
        ]);
    }

    #[Route('/v1/audio/transcriptions/sessions', name: 'openai_audio_sessions_create', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/audio/transcriptions/sessions',
        summary: 'Open a transcription session',
        description: 'Creates a session scoped to this API key and a caller-chosen client_id (e.g. client 123 vs 321 on the same key).',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'client_id', type: 'string', example: '123'),
                new OA\Property(property: 'model', type: 'string', example: 'whisper'),
                new OA\Property(property: 'language', type: 'string', example: 'en'),
                new OA\Property(property: 'encoding', type: 'string', example: 'pcm_s16le'),
                new OA\Property(property: 'sample_rate', type: 'integer', example: 16000),
                new OA\Property(property: 'channels', type: 'integer', example: 1),
                new OA\Property(property: 'commit_after_bytes', type: 'integer', example: 96000),
                new OA\Property(property: 'reuse', type: 'boolean', example: false),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Session created')]
    public function createSession(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        try {
            $session = $this->sttSessionService->create(
                $user,
                $this->apiKeyId($request),
                $this->sessionOptions($request),
            );

            return new JsonResponse($session->toPublicArray(), Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    #[Route('/v1/audio/transcriptions/sessions', name: 'openai_audio_sessions_list', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/audio/transcriptions/sessions',
        summary: 'List transcription sessions for this API key',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Sessions')]
    public function listSessions(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        $clientId = $request->query->get('client_id');
        $status = $request->query->get('status');

        try {
            $sessions = $this->sttSessionService->listOwned(
                $this->apiKeyId($request),
                is_string($clientId) ? $clientId : null,
                is_string($status) ? $status : null,
            );
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => array_map(static fn (SttSession $session): array => $session->toPublicArray(), $sessions),
        ]);
    }

    #[Route('/v1/audio/transcriptions/sessions/{id}', name: 'openai_audio_sessions_get', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/audio/transcriptions/sessions/{id}',
        summary: 'Get a transcription session',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Response(response: 200, description: 'Session')]
    public function getSession(Request $request, string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        try {
            $session = $this->sttSessionService->getOwned($id, $this->apiKeyId($request));
            $cursor = (int) $request->query->get('cursor', '0');

            return new JsonResponse($session->toPublicArray() + [
                'events' => $session->eventsAfter($cursor),
            ]);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    #[Route('/v1/audio/transcriptions/sessions/{id}/audio', name: 'openai_audio_sessions_audio', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/audio/transcriptions/sessions/{id}/audio',
        summary: 'Append an audio chunk to a session',
        description: 'Accepts multipart `file`/`audio`, raw `audio/*` / octet-stream, or JSON `audio_base64`. Set commit=true to transcribe immediately.',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Response(response: 200, description: 'Chunk accepted')]
    public function appendAudio(Request $request, string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        try {
            $result = $this->sttSessionService->appendAudio(
                $user,
                $this->apiKeyId($request),
                $id,
                $this->readAudioBytes($request),
                $this->truthy($request, 'commit'),
            );

            return new JsonResponse($result['session']->toPublicArray() + [
                'committed' => $result['committed'],
                'bytes_appended' => $result['bytes_appended'],
            ]);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    #[Route('/v1/audio/transcriptions/sessions/{id}/commit', name: 'openai_audio_sessions_commit', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/audio/transcriptions/sessions/{id}/commit',
        summary: 'Transcribe pending audio in a session',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Response(response: 200, description: 'Session after commit')]
    public function commitSession(Request $request, string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        try {
            $session = $this->sttSessionService->commit(
                $user,
                $this->apiKeyId($request),
                $id,
                $this->truthy($request, 'close'),
            );

            return new JsonResponse($session->toPublicArray());
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    #[Route('/v1/audio/transcriptions/sessions/{id}/stream', name: 'openai_audio_sessions_stream', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/audio/transcriptions/sessions/{id}/stream',
        summary: 'SSE stream of session transcripts',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Parameter(name: 'cursor', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'timeout', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 300))]
    #[OA\Response(response: 200, description: 'text/event-stream')]
    public function streamSession(Request $request, string $id, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        try {
            $this->sttSessionService->getOwned($id, $this->apiKeyId($request));
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }

        $apiKeyId = $this->apiKeyId($request);
        $cursor = (int) $request->query->get('cursor', '0');
        $timeout = max(0, min(600, (int) $request->query->get('timeout', '300')));

        $response = new StreamedResponse(function () use ($id, $apiKeyId, $cursor, $timeout): void {
            $this->emitSessionStream($id, $apiKeyId, $cursor, $timeout);
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    #[Route('/v1/audio/transcriptions/sessions/{id}', name: 'openai_audio_sessions_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/v1/audio/transcriptions/sessions/{id}',
        summary: 'Close a transcription session',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Response(response: 200, description: 'Session closed')]
    public function deleteSession(Request $request, string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        try {
            $session = $this->sttSessionService->close($this->apiKeyId($request), $id);

            return new JsonResponse($session->toPublicArray());
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    #[Route('/v1/audio/transcriptions', name: 'openai_audio_transcriptions', methods: ['POST'])]
    #[OA\Post(
        path: '/v1/audio/transcriptions',
        summary: 'Create a transcription (OpenAI-compatible)',
        description: 'One-shot file transcription. Use sessions for live streams. `stream=true` returns SSE.',
        security: [['Bearer' => []]],
        tags: ['Audio Transcription']
    )]
    #[OA\Response(response: 200, description: 'Transcription')]
    public function transcribe(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->openAiError('Authentication required', 'invalid_request_error', 'invalid_api_key', 401);
        }

        $tempFile = null;
        try {
            $tempFile = $this->persistUploadedAudio($request);
            $result = $this->sttSessionService->transcribeFile(
                $user,
                $this->apiKeyId($request),
                $tempFile,
                $this->oneShotOptions($request),
            );

            if ($this->truthy($request, 'stream')) {
                return $this->oneShotStream($result);
            }

            return $this->oneShotResponse($request, $result);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        } finally {
            if (is_string($tempFile) && is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function oneShotResponse(Request $request, array $result): Response
    {
        $format = strtolower((string) $this->requestValue($request, 'response_format', 'json'));
        if ('text' === $format) {
            return new Response((string) $result['text'], 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ('verbose_json' === $format) {
            return new JsonResponse([
                'task' => 'transcribe',
                'language' => $result['language'],
                'duration' => $result['duration'],
                'text' => $result['text'],
                'segments' => $result['segments'],
                'id' => $result['id'],
                'model' => $result['model'],
                'provider' => $result['provider'],
                'client_id' => $result['client_id'],
                'api_key_id' => $result['api_key_id'],
            ]);
        }

        return new JsonResponse([
            'text' => $result['text'],
            'id' => $result['id'],
            'model' => $result['model'],
            'language' => $result['language'],
            'duration' => $result['duration'],
            'client_id' => $result['client_id'],
            'api_key_id' => $result['api_key_id'],
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function oneShotStream(array $result): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($result): void {
            $this->sseWriter->write('transcript', [
                'id' => $result['id'],
                'text' => $result['text'],
                'full_text' => $result['text'],
                'is_final' => true,
                'language' => $result['language'],
                'duration' => $result['duration'],
                'model' => $result['model'],
                'client_id' => $result['client_id'],
                'api_key_id' => $result['api_key_id'],
            ]);
            $this->sseWriter->write('done', [
                'id' => $result['id'],
                'text' => $result['text'],
            ]);
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function emitSessionStream(string $id, int $apiKeyId, int $cursor, int $timeout): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        $deadline = time() + $timeout;
        $lastHeartbeat = time();

        while (true) {
            if (connection_aborted()) {
                return;
            }

            try {
                $session = $this->sttSessionService->getOwned($id, $apiKeyId);
            } catch (SttSessionNotFoundException) {
                $this->sseWriter->write('error', ['message' => 'Session not found', 'code' => 'session_not_found']);

                return;
            }

            foreach ($session->eventsAfter($cursor) as $event) {
                $this->sseWriter->write($event['type'], $event['payload'] + ['cursor' => $event['cursor']]);
                $cursor = $event['cursor'];
            }

            if (!$session->isOpen() && $cursor >= $session->cursor) {
                return;
            }

            if (0 === $timeout || time() >= $deadline) {
                $this->sseWriter->write('heartbeat', ['cursor' => $cursor, 'status' => $session->status]);

                return;
            }

            if (time() - $lastHeartbeat >= 5) {
                $this->sseWriter->write('heartbeat', ['cursor' => $cursor, 'status' => $session->status]);
                $lastHeartbeat = time();
            }

            usleep(400_000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionOptions(Request $request): array
    {
        return [
            'client_id' => $this->requestValue($request, 'client_id'),
            'model' => $this->requestValue($request, 'model'),
            'language' => $this->requestValue($request, 'language'),
            'prompt' => $this->requestValue($request, 'prompt'),
            'encoding' => $this->requestValue($request, 'encoding'),
            'sample_rate' => (int) $this->requestValue($request, 'sample_rate', '16000'),
            'channels' => (int) $this->requestValue($request, 'channels', '1'),
            'commit_after_bytes' => (int) $this->requestValue($request, 'commit_after_bytes', (string) SttSessionService::DEFAULT_COMMIT_AFTER_BYTES),
            'reuse' => $this->truthy($request, 'reuse'),
        ];
    }

    /**
     * @return array{model: ?string, language: ?string, prompt: ?string, client_id: ?string}
     */
    private function oneShotOptions(Request $request): array
    {
        return [
            'model' => $this->requestValue($request, 'model'),
            'language' => $this->requestValue($request, 'language'),
            'prompt' => $this->requestValue($request, 'prompt'),
            'client_id' => $this->requestValue($request, 'client_id'),
        ];
    }

    private function persistUploadedAudio(Request $request): string
    {
        $file = $request->files->get('file') ?? $request->files->get('audio');
        if ($file instanceof UploadedFile) {
            if (!$file->isValid()) {
                throw new \InvalidArgumentException('Uploaded audio file is invalid');
            }
            $extension = $file->guessExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'wav';
            $target = sys_get_temp_dir().'/stt_upload_'.bin2hex(random_bytes(8)).'.'.$extension;
            $file->move(dirname($target), basename($target));

            return $target;
        }

        $bytes = $this->readRawOrBase64($request);
        if ('' === $bytes) {
            throw new \InvalidArgumentException('file is required');
        }

        $target = sys_get_temp_dir().'/stt_upload_'.bin2hex(random_bytes(8)).'.wav';
        file_put_contents($target, $bytes);

        return $target;
    }

    private function readAudioBytes(Request $request): string
    {
        $file = $request->files->get('file') ?? $request->files->get('audio');
        if ($file instanceof UploadedFile) {
            if (!$file->isValid()) {
                throw new \InvalidArgumentException('Uploaded audio file is invalid');
            }
            $contents = file_get_contents($file->getPathname());
            if (false === $contents || '' === $contents) {
                throw new \InvalidArgumentException('Audio chunk is empty');
            }

            return $contents;
        }

        $bytes = $this->readRawOrBase64($request);
        if ('' === $bytes) {
            throw new \InvalidArgumentException('Audio chunk is empty');
        }

        return $bytes;
    }

    private function readRawOrBase64(Request $request): string
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode($request->getContent(), true);
            if (is_array($body) && isset($body['audio_base64']) && is_string($body['audio_base64'])) {
                $decoded = base64_decode($body['audio_base64'], true);

                return false === $decoded ? '' : $decoded;
            }

            return '';
        }

        if (str_contains($contentType, 'multipart/form-data')) {
            $b64 = $request->request->get('audio_base64');

            return is_string($b64) ? (string) base64_decode($b64, true) : '';
        }

        return $request->getContent();
    }

    private function requestValue(Request $request, string $key, ?string $default = null): ?string
    {
        $query = $request->query->get($key);
        if (is_string($query) && '' !== $query) {
            return $query;
        }

        $form = $request->request->get($key);
        if (is_string($form) && '' !== $form) {
            return $form;
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode($request->getContent(), true);
            if (is_array($body) && array_key_exists($key, $body) && (is_string($body[$key]) || is_int($body[$key]) || is_float($body[$key]) || is_bool($body[$key]))) {
                return (string) $body[$key];
            }
        }

        return $default;
    }

    private function truthy(Request $request, string $key): bool
    {
        $value = $this->requestValue($request, $key);
        if (null === $value) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function apiKeyId(Request $request): int
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey instanceof ApiKey && null !== $apiKey->getId()) {
            return (int) $apiKey->getId();
        }

        return 0;
    }

    private function handleError(\Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof RateLimitExceededException => $this->openAiError($e->getMessage(), 'rate_limit_error', 'rate_limit_exceeded', 429),
            $e instanceof SttSessionNotFoundException, $e instanceof SttModelNotFoundException => $this->openAiError($e->getMessage(), 'invalid_request_error', 'not_found', 404),
            $e instanceof SttSessionClosedException => $this->openAiError($e->getMessage(), 'invalid_request_error', 'session_closed', 409),
            $e instanceof \InvalidArgumentException => $this->openAiError($e->getMessage(), 'invalid_request_error', 'invalid_request', 400),
            default => $this->logAndFail($e),
        };
    }

    private function logAndFail(\Throwable $e): JsonResponse
    {
        $this->logger->error('STT API failed', [
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);

        return $this->openAiError('Transcription failed', 'server_error', 'internal_error', 500);
    }

    private function openAiError(string $message, string $type, string $code, int $httpStatus): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => null,
                'code' => $code,
            ],
        ], $httpStatus);
    }
}
