<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Controller\OpenAICompatibleController;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class OpenAICompatibleControllerTest extends TestCase
{
    private AiFacade $aiFacade;
    private ModelRepository $modelRepository;
    private ModelConfigService $modelConfigService;
    private RateLimitService $rateLimitService;
    private LoggerInterface $logger;
    private OpenAICompatibleController $controller;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new OpenAICompatibleController(
            $this->aiFacade,
            $this->modelRepository,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->logger,
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
        $this->modelRepository->method('find')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

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

        $this->modelRepository->method('findBy')
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

    private function createMockUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }
}
