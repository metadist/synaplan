<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AudioTranscriptionController;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Service\Stt\Exception\SttSessionNotFoundException;
use App\Service\Stt\SttModelResolver;
use App\Service\Stt\SttSession;
use App\Service\Stt\SttSessionService;
use App\Service\Stt\SttSseWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AudioTranscriptionControllerTest extends TestCase
{
    private SttSessionService&MockObject $service;
    private SttModelResolver&MockObject $resolver;
    private AudioTranscriptionController $controller;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->service = $this->createMock(SttSessionService::class);
        $this->resolver = $this->createMock(SttModelResolver::class);
        $this->controller = new AudioTranscriptionController(
            $this->service,
            $this->resolver,
            new SttSseWriter(),
            new NullLogger(),
        );
        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(42);
    }

    public function testCreateSessionRequiresAuth(): void
    {
        $response = $this->controller->createSession(new Request(), null);

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_api_key', $data['error']['code']);
    }

    public function testCreateSessionReturnsClientAndApiKeyIds(): void
    {
        $session = $this->session('stt_sess_abc', '123', 1);
        $this->service->expects($this->once())
            ->method('create')
            ->with(
                $this->user,
                1,
                $this->callback(static fn (array $opts): bool => '123' === ($opts['client_id'] ?? null)),
            )
            ->willReturn($session);

        $request = Request::create(
            '/v1/audio/transcriptions/sessions',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['client_id' => '123', 'model' => 'whisper'], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('api_key', $this->apiKey(1));

        $response = $this->controller->createSession($request, $this->user);
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('stt_sess_abc', $data['id']);
        $this->assertSame('123', $data['client_id']);
        $this->assertSame(1, $data['api_key_id']);
        $this->assertSame('transcription.session', $data['object']);
    }

    public function testGetSessionUnknownIs404(): void
    {
        $this->service->method('getOwned')->willThrowException(new SttSessionNotFoundException('missing'));
        $request = new Request();
        $request->attributes->set('api_key', $this->apiKey(1));

        $response = $this->controller->getSession($request, 'missing', $this->user);

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('not_found', $data['error']['code']);
    }

    public function testAppendAudioAcceptsRawBody(): void
    {
        $session = $this->session('stt_sess_abc', '321', 1);
        $this->service->expects($this->once())
            ->method('appendAudio')
            ->with($this->user, 1, 'stt_sess_abc', 'PCMDATA', false)
            ->willReturn([
                'session' => $session,
                'committed' => false,
                'bytes_appended' => 8,
            ]);

        $request = Request::create(
            '/v1/audio/transcriptions/sessions/stt_sess_abc/audio',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            'PCMDATA',
        );
        $request->attributes->set('api_key', $this->apiKey(1));

        $response = $this->controller->appendAudio($request, 'stt_sess_abc', $this->user);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($data['committed']);
        $this->assertSame(8, $data['bytes_appended']);
        $this->assertSame('321', $data['client_id']);
    }

    public function testOneShotRequiresFile(): void
    {
        $request = Request::create('/v1/audio/transcriptions', 'POST');
        $request->attributes->set('api_key', $this->apiKey(1));

        $response = $this->controller->transcribe($request, $this->user);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testOneShotReturnsOpenAiText(): void
    {
        $request = Request::create(
            '/v1/audio/transcriptions?model=whisper&client_id=123',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            'RIFF....WAVE',
        );
        $request->attributes->set('api_key', $this->apiKey(1));

        $this->service->expects($this->once())
            ->method('transcribeFile')
            ->willReturn([
                'id' => 'transcribe_abc',
                'text' => 'hello',
                'language' => 'en',
                'duration' => 1.0,
                'model' => 'whisper',
                'provider' => 'whisper',
                'model_id' => 330,
                'client_id' => '123',
                'api_key_id' => 1,
                'user_id' => 42,
                'segments' => [],
            ]);

        $response = $this->controller->transcribe($request, $this->user);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame('hello', $data['text']);
        $this->assertSame('123', $data['client_id']);
        $this->assertSame(1, $data['api_key_id']);
    }

    public function testOneShotStreamReturnsSse(): void
    {
        $request = Request::create(
            '/v1/audio/transcriptions?stream=true',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream'],
            'RIFF....WAVE',
        );
        $request->attributes->set('api_key', $this->apiKey(1));

        $this->service->method('transcribeFile')->willReturn([
            'id' => 'transcribe_abc',
            'text' => 'hello',
            'language' => 'en',
            'duration' => 1.0,
            'model' => 'whisper',
            'provider' => 'whisper',
            'model_id' => 330,
            'client_id' => null,
            'api_key_id' => 1,
            'user_id' => 42,
            'segments' => [],
        ]);

        $response = $this->controller->transcribe($request, $this->user);
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }

    public function testListModels(): void
    {
        $this->resolver->method('listModels')->willReturn([
            ['id' => 'whisper', 'object' => 'model', 'created' => 1700000000, 'owned_by' => 'whisper', 'tag' => 'sound2text'],
        ]);

        $response = $this->controller->listModels($this->user);
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame('list', $data['object']);
        $this->assertSame('whisper', $data['data'][0]['id']);
    }

    private function session(string $id, string $clientId, int $apiKeyId): SttSession
    {
        $now = time();

        return new SttSession(
            id: $id,
            clientId: $clientId,
            apiKeyId: $apiKeyId,
            userId: 42,
            model: 'whisper',
            provider: 'whisper',
            modelId: 330,
            language: 'en',
            prompt: null,
            status: SttSession::STATUS_OPEN,
            encoding: 'auto',
            sampleRate: 16000,
            channels: 1,
            commitAfterBytes: 96000,
            createdAt: $now,
            updatedAt: $now,
            expiresAt: $now + 3600,
        );
    }

    private function apiKey(int $id): ApiKey
    {
        $key = $this->createMock(ApiKey::class);
        $key->method('getId')->willReturn($id);

        return $key;
    }
}
