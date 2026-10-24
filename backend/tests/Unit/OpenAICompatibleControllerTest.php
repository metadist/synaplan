<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Service\AiFacade;
use App\Controller\OpenAICompatibleController;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Api\OpenAiToolCallingGate;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

class OpenAICompatibleControllerTest extends TestCase
{
    // Intersection types so PHPStan sees both the collaborator API and PHPUnit's
    // mock API (`method()`/`expects()`) — avoids method.notFound baseline churn
    // every time a test stubs another method.
    private AiFacade&MockObject $aiFacade;
    private ModelRepository&MockObject $modelRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private RateLimitService&MockObject $rateLimitService;
    private MessagesGatewayConfig&MockObject $messagesGatewayConfig;
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private OpenAiToolCallingGate&MockObject $toolCallingGate;
    private OpenAiGatewayToolLoop&MockObject $toolLoop;
    private OpenAICompatibleController $controller;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->messagesGatewayConfig = $this->createMock(MessagesGatewayConfig::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->toolCallingGate = $this->createMock(OpenAiToolCallingGate::class);
        $this->toolLoop = $this->createMock(OpenAiGatewayToolLoop::class);

        $this->controller = new OpenAICompatibleController(
            $this->aiFacade,
            $this->modelRepository,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->messagesGatewayConfig,
            $this->messageBus,
            $this->logger,
            $this->toolCallingGate,
            $this->toolLoop,
        );
    }

    public function testChatCompletionsRequiresAuth(): void
    {
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], '{}');

        $response = $this->controller->chatCompletions($request, null);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('invalid_api_key', $data['error']['code']);
    }

    public function testChatCompletionsRequiresMessages(): void
    {
        $user = $this->createMockUser();
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], json_encode(['model' => 'gpt-4o']));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('missing_messages', $data['error']['code']);
    }

    public function testChatCompletionsRejectsInvalidJson(): void
    {
        $user = $this->createMockUser();
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], 'not-json');

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('invalid_json', $data['error']['code']);
    }

    public function testChatCompletionsNonStreamingReturnsOpenAIFormat(): void
    {
        $user = $this->createMockUser();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->rateLimitService->method('checkCostBudget')->willReturn(['allowed' => true]);

        // A resolvable model must exist — the controller no longer falls back
        // to a hardcoded gpt-4o (#1320). Resolve via the CHAT default.
        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('gpt-4o');
        $model->method('getId')->willReturn(5);
        $this->modelConfigService->method('getDefaultModel')->willReturn(5);
        $this->modelRepository->method('find')->willReturn($model);

        $this->aiFacade->method('chat')->willReturn([
            'content' => 'Hello! How can I help?',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'usage' => [],
        ]);

        $body = json_encode([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'Hello!']],
        ]);
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertSame('chat.completion', $data['object']);
        $this->assertStringStartsWith('chatcmpl-synaplan-', $data['id']);
        $this->assertIsInt($data['created']);
        $this->assertSame('gpt-4o', $data['model']);
        $this->assertCount(1, $data['choices']);
        $this->assertSame('assistant', $data['choices'][0]['message']['role']);
        $this->assertSame('Hello! How can I help?', $data['choices'][0]['message']['content']);
        $this->assertSame('stop', $data['choices'][0]['finish_reason']);
        $this->assertArrayHasKey('usage', $data);
        $this->assertArrayHasKey('prompt_tokens', $data['usage']);
    }

    public function testChatCompletionsRateLimitReturnsOpenAIError(): void
    {
        $user = $this->createMockUser();

        $this->rateLimitService->method('checkLimit')->willReturn([
            'allowed' => false,
            'limit' => 100,
            'used' => 100,
            'remaining' => 0,
        ]);

        $body = json_encode([
            'messages' => [['role' => 'user', 'content' => 'Hello!']],
        ]);
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], $body);

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(429, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('rate_limit_exceeded', $data['error']['code']);
    }

    public function testChatCompletionsWithAnExhaustedBudgetNeverReachesTheProvider(): void
    {
        $user = $this->createMockUser();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->rateLimitService->method('checkCostBudget')->willReturn(['allowed' => false]);
        $this->aiFacade->expects($this->never())->method('chat');

        $body = json_encode([
            'messages' => [['role' => 'user', 'content' => 'Hello!']],
        ]);
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], $body);

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('insufficient_quota', $data['error']['code']);
    }

    public function testListModelsRequiresAuth(): void
    {
        $response = $this->controller->listModels(null);

        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('invalid_api_key', $data['error']['code']);
    }

    public function testListModelsReturnsOpenAIFormat(): void
    {
        $user = $this->createMockUser();

        $model = $this->createMock(Model::class);
        $model->method('getProviderId')->willReturn('gpt-4o');
        $model->method('getName')->willReturn('GPT-4o');
        $model->method('getService')->willReturn('OpenAI');

        $this->modelRepository->expects($this->once())
            ->method('findBy')
            ->with(['active' => 1])
            ->willReturn([$model]);

        $response = $this->controller->listModels($user);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertSame('list', $data['object']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('gpt-4o', $data['data'][0]['id']);
        $this->assertSame('model', $data['data'][0]['object']);
        $this->assertSame('openai', $data['data'][0]['owned_by']);
        $this->assertIsInt($data['data'][0]['created']);
    }

    public function testErrorResponseMatchesOpenAIFormat(): void
    {
        $user = $this->createMockUser();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->rateLimitService->method('checkCostBudget')->willReturn(['allowed' => true]);

        // Resolve a model so the request reaches the provider call that throws
        // (the point of this test) rather than short-circuiting on model lookup.
        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('gpt-4o');
        $model->method('getId')->willReturn(5);
        $this->modelConfigService->method('getDefaultModel')->willReturn(5);
        $this->modelRepository->method('find')->willReturn($model);

        $this->aiFacade->method('chat')->willThrowException(new \RuntimeException('Provider unavailable'));

        $body = json_encode([
            'messages' => [['role' => 'user', 'content' => 'Hello!']],
        ]);
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], $body);

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(500, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data['error']);
        $this->assertArrayHasKey('type', $data['error']);
        $this->assertArrayHasKey('code', $data['error']);
        $this->assertSame('server_error', $data['error']['type']);
    }

    public function testChatCompletionsReturns404WhenNoModelResolves(): void
    {
        $user = $this->createMockUser();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->rateLimitService->method('checkCostBudget')->willReturn(['allowed' => true]);
        // No requested model matches and no CHAT default is configured: the
        // controller must 404 rather than silently route to a hardcoded model
        // and record usage with a null model_id (#1320).
        $this->modelRepository->method('find')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $body = json_encode([
            'model' => 'nonexistent-model',
            'messages' => [['role' => 'user', 'content' => 'Hello!']],
        ]);
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('model_not_found', $data['error']['code']);
    }

    public function testChatCompletionsPrefersUserConfiguredDefaultModelAndForwardsReasoningEffort(): void
    {
        $user = $this->createMockUser();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->rateLimitService->method('checkCostBudget')->willReturn(['allowed' => true]);

        $configuredModel = $this->createMock(Model::class);
        $configuredModel->method('getService')->willReturn('OpenAICompatible');
        $configuredModel->method('getTag')->willReturn('chat');
        $configuredModel->method('getProviderId')->willReturn('gpt-oss-120b');
        $configuredModel->method('getName')->willReturn('FreeAPI · Cerebras GPT OSS 120B');
        $configuredModel->method('getId')->willReturn(500);

        $catalogDuplicate = $this->createMock(Model::class);
        $catalogDuplicate->method('getService')->willReturn('Cerebras');
        $catalogDuplicate->method('getProviderId')->willReturn('gpt-oss-120b');
        $catalogDuplicate->method('getId')->willReturn(385);

        $this->modelConfigService->method('getConfiguredDefaultModel')
            ->willReturnCallback(static fn (string $cap, ?int $uid): ?int => 'SORT' === $cap && 1 === $uid ? 500 : null);
        $this->modelRepository->method('find')
            ->willReturnCallback(static fn (int $id): ?Model => 500 === $id ? $configuredModel : null);
        $this->modelRepository->method('findOneBy')->willReturn($catalogDuplicate);

        $capturedOptions = null;
        $this->aiFacade->expects($this->once())
            ->method('chat')
            ->willReturnCallback(static function (array $messages, ?int $uid, array $options) use (&$capturedOptions): array {
                $capturedOptions = $options;

                return [
                    'content' => '{"BTOPIC":"general"}',
                    'provider' => 'openaicompatible',
                    'model' => 'gpt-oss-120b',
                    'usage' => [],
                ];
            });

        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], (string) json_encode([
            'model' => 'gpt-oss-120b',
            'reasoning_effort' => 'low',
            'messages' => [['role' => 'user', 'content' => 'Sort this']],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($capturedOptions);
        $this->assertSame('openaicompatible', $capturedOptions['provider']);
        $this->assertSame('gpt-oss-120b', $capturedOptions['model']);
        $this->assertSame('low', $capturedOptions['reasoning_effort']);
    }

    public function testChatCompletionsIgnoresPic2TextDefaultThatSharesProviderId(): void
    {
        $user = $this->createMockUser();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->rateLimitService->method('checkCostBudget')->willReturn(['allowed' => true]);

        $chatDefault = $this->modelStub(10, 'other-chat', 'chat', 'Groq');
        $sortDefault = $this->modelStub(11, 'other-sort', 'chat', 'Groq');
        $visionDefault = $this->modelStub(384, 'qwen-3.8-27b', 'pic2text', 'Cerebras');
        $chatRow = $this->modelStub(383, 'qwen-3.8-27b', 'chat', 'Cerebras');

        $this->modelConfigService->method('getConfiguredDefaultModel')
            ->willReturnCallback(static fn (string $cap, ?int $uid): ?int => 1 !== $uid ? null : match ($cap) {
                'CHAT' => 10,
                'SORT' => 11,
                'PIC2TEXT' => 384,
                default => null,
            });
        $this->modelRepository->method('find')
            ->willReturnCallback(static function (int $id) use ($chatDefault, $sortDefault, $visionDefault): ?Model {
                return match ($id) {
                    10 => $chatDefault,
                    11 => $sortDefault,
                    384 => $visionDefault,
                    default => null,
                };
            });
        $this->modelRepository->method('findActiveByTagAndIdentity')->willReturn($chatRow);
        $this->modelRepository->method('findOneBy')->willReturn($visionDefault);
        $this->toolCallingGate->method('allows')
            ->willReturnCallback(static fn (Model $model): bool => 383 === (int) $model->getId());

        $meteredModelId = null;
        $this->rateLimitService->method('recordUsage')
            ->willReturnCallback(static function (User $user, string $action, array $metadata) use (&$meteredModelId): RecordedUsage {
                $meteredModelId = 1 === $user->getId() && 'MESSAGES' === $action ? ($metadata['model_id'] ?? null) : null;

                return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
            });

        $capturedOptions = null;
        $this->toolLoop->expects($this->once())
            ->method('complete')
            ->willReturnCallback(static function (User $user, array $messages, array $options) use (&$capturedOptions): array {
                $capturedOptions = $options;

                return [
                    'content' => 'ok',
                    'provider' => 'cerebras',
                    'model' => 'qwen-3.8-27b',
                    'usage' => [],
                ];
            });

        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], (string) json_encode([
            'model' => 'qwen-3.8-27b',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                ],
            ]],
        ]));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->controller->chatCompletions($request, $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($capturedOptions);
        $this->assertSame('cerebras', $capturedOptions['provider']);
        $this->assertSame('qwen-3.8-27b', $capturedOptions['model']);
        $this->assertSame(383, $meteredModelId);
    }

    public function testIncognitoCompletionDoesNotEnqueueSessionSummary(): void
    {
        $user = $this->createMockUser();
        $this->allowChat();
        $this->messagesGatewayConfig->method('isSessionSummaryEnabled')->willReturn(true);
        $this->messageBus->expects($this->never())->method('dispatch');

        $response = $this->controller->chatCompletions($this->completionRequest([
            'model' => 'echo',
            'incognito' => true,
            'messages' => [['role' => 'user', 'content' => 'third party record']],
        ]), $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCompletionWithoutIncognitoStillEnqueuesSessionSummary(): void
    {
        $user = $this->createMockUser();
        $this->allowChat();
        $this->messagesGatewayConfig->method('isSessionSummaryEnabled')->willReturn(true);
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(\App\Message\SummarizeApiSessionCommand::class));

        $response = $this->controller->chatCompletions($this->completionRequest([
            'model' => 'echo',
            'messages' => [['role' => 'user', 'content' => 'remember this session']],
        ]), $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function completionRequest(array $body): Request
    {
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], (string) json_encode($body));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function allowChat(): void
    {
        $model = $this->modelStub(9, 'echo', 'chat', 'Test');
        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->rateLimitService->method('checkCostBudget')->willReturn(['allowed' => true]);
        $this->modelRepository->method('findActiveByTagAndIdentity')->willReturn($model);
        $this->aiFacade->method('chat')->willReturn([
            'content' => 'ok',
            'provider' => 'test',
            'model' => 'echo',
            'usage' => [],
        ]);
    }

    private function modelStub(int $id, string $providerId, string $tag, string $service): Model&MockObject
    {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($providerId);
        $model->method('getTag')->willReturn($tag);
        $model->method('getService')->willReturn($service);

        return $model;
    }

    private function createMockUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }
}
