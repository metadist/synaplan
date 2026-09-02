<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Exception\ProviderException;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Provider\TestProvider;
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
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * T3: /v1/chat/completions speaks client tools through TestProvider.
 */
final class OpenAICompatibleControllerToolsTest extends TestCase
{
    private const WEATHER_TOOL = [
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'description' => 'Look up weather',
            'parameters' => [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
            ],
        ],
    ];

    private AiFacade&MockObject $aiFacade;
    private ModelRepository&MockObject $modelRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private OpenAiToolCallingGate&MockObject $toolCallingGate;
    private RateLimitService&MockObject $rateLimitService;
    /** @var array<string, mixed>|null */
    private ?array $metered = null;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->toolCallingGate = $this->createMock(OpenAiToolCallingGate::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->metered = null;
        $this->rateLimitService->method('recordUsage')
            ->willReturnCallback(function (User $user, string $action, array $metadata): RecordedUsage {
                $this->metered = $metadata;

                return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
            });
    }

    public function testNonStreamToolCallFromTestProvider(): void
    {
        $this->wireTestProvider();
        $this->toolCallingGate->method('allows')->willReturn(true);
        $this->stubResolvableModel($this->capableModel());

        $response = $this->controller()->chatCompletions(
            $this->jsonRequest([
                'messages' => [['role' => 'user', 'content' => 'TOOLTEST:get_weather:{"city":"Berlin"}']],
                'tools' => [self::WEATHER_TOOL],
            ]),
            $this->user(),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertNull($data['choices'][0]['message']['content']);
        self::assertSame('tool_calls', $data['choices'][0]['finish_reason']);
        self::assertSame('get_weather', $data['choices'][0]['message']['tool_calls'][0]['function']['name']);
        self::assertSame('{"city":"Berlin"}', $data['choices'][0]['message']['tool_calls'][0]['function']['arguments']);
        self::assertSame('call_test_1', $data['choices'][0]['message']['tool_calls'][0]['id']);
        self::assertIsArray($this->metered);
        self::assertSame('[tool_call get_weather({"city":"Berlin"})]', $this->metered['response_text']);
    }

    public function testTwoRoundClientToolExchange(): void
    {
        $this->wireTestProvider();
        $this->toolCallingGate->method('allows')->willReturn(true);
        $this->stubResolvableModel($this->capableModel());
        $controller = $this->controller();
        $user = $this->user();

        $first = $controller->chatCompletions(
            $this->jsonRequest([
                'messages' => [['role' => 'user', 'content' => 'TOOLTEST:get_weather:{"city":"Berlin"}']],
                'tools' => [self::WEATHER_TOOL],
            ]),
            $user,
        );
        $firstData = json_decode((string) $first->getContent(), true);
        self::assertIsArray($firstData);
        $toolCalls = $firstData['choices'][0]['message']['tool_calls'];

        $second = $controller->chatCompletions(
            $this->jsonRequest([
                'messages' => [
                    ['role' => 'user', 'content' => 'TOOLTEST:get_weather:{"city":"Berlin"}'],
                    ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls],
                    ['role' => 'tool', 'tool_call_id' => 'call_test_1', 'content' => '{"temp":18}'],
                ],
                'tools' => [self::WEATHER_TOOL],
            ]),
            $user,
        );

        $secondData = json_decode((string) $second->getContent(), true);
        self::assertIsArray($secondData);
        self::assertSame('stop', $secondData['choices'][0]['finish_reason']);
        self::assertSame('Tool result received: {"temp":18}', $secondData['choices'][0]['message']['content']);
        self::assertArrayNotHasKey('tool_calls', $secondData['choices'][0]['message']);
    }

    public function testStreamToolCallsMatchGoldenFile(): void
    {
        $this->wireTestProvider();
        $method = new \ReflectionMethod(OpenAICompatibleController::class, 'handleStream');
        $response = $method->invoke(
            $this->controller(),
            $this->user(),
            [['role' => 'user', 'content' => 'TOOLTEST:get_weather:{"city":"Berlin"}']],
            ['model' => 'test-model', 'provider' => 'test', 'tools' => [self::WEATHER_TOOL]],
            'chatcmpl-fixed',
            1700000000,
            'test-model',
            9,
        );

        self::assertInstanceOf(StreamedResponse::class, $response);
        $sse = $this->captureSse($response);
        $fixture = file_get_contents(__DIR__.'/../Fixtures/openai-compatible/tools/stream_tool_calls.sse');
        self::assertNotFalse($fixture);
        self::assertSame($fixture, $sse);
        self::assertIsArray($this->metered);
        self::assertSame('[tool_call get_weather({"city":"Berlin"})]', $this->metered['response_text']);
    }

    public function testStreamIncludeUsageAppendsTrailingChunk(): void
    {
        $this->wireTestProvider();
        $method = new \ReflectionMethod(OpenAICompatibleController::class, 'handleStream');
        $response = $method->invoke(
            $this->controller(),
            $this->user(),
            [['role' => 'user', 'content' => 'TOOLTEST:get_weather:{"city":"Berlin"}']],
            [
                'model' => 'test-model',
                'provider' => 'test',
                'tools' => [self::WEATHER_TOOL],
                'include_usage' => true,
            ],
            'chatcmpl-fixed',
            1700000000,
            'test-model',
            9,
        );

        $sse = $this->captureSse($response);
        self::assertStringContainsString('"finish_reason":"tool_calls"', $sse);
        self::assertStringContainsString('"usage":{"prompt_tokens":10,"completion_tokens":8,"total_tokens":18}', $sse);
        self::assertStringEndsWith("data: [DONE]\n\n", $sse);
    }

    public function testMalformedToolsAreInvalidRequest(): void
    {
        $response = $this->controller()->chatCompletions(
            $this->jsonRequest([
                'model' => 'test-model',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => [['type' => 'broken']],
            ]),
            $this->user(),
        );

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('invalid_tools', $data['error']['code']);
        self::assertSame('invalid_request_error', $data['error']['type']);
    }

    public function testUnsupportedProviderReturnsToolsNotSupported(): void
    {
        $this->toolCallingGate->method('allows')->willReturn(false);
        $this->stubResolvableModel($this->capableModel());

        $response = $this->controller()->chatCompletions(
            $this->jsonRequest([
                'messages' => [['role' => 'user', 'content' => 'TOOLTEST:get_weather:{}']],
                'tools' => [self::WEATHER_TOOL],
            ]),
            $this->user(),
        );

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('tools_not_supported', $data['error']['code']);
        self::assertStringContainsString('test-model', (string) $data['error']['message']);
    }

    public function testModelMissingToolUseReturnsToolsNotSupported(): void
    {
        $this->toolCallingGate->method('allows')->willReturn(false);
        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Ollama');
        $model->method('getProviderId')->willReturn('llama3.1:8b');
        $model->method('getId')->willReturn(3);
        $model->method('hasFeature')->willReturn(false);
        $this->stubResolvableModel($model);

        $response = $this->controller()->chatCompletions(
            $this->jsonRequest([
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => [self::WEATHER_TOOL],
            ]),
            $this->user(),
        );

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('tools_not_supported', $data['error']['code']);
        self::assertStringContainsString('llama3.1:8b', (string) $data['error']['message']);
    }

    public function testUpstreamToolsFourXxMapsToToolsNotSupported(): void
    {
        $this->toolCallingGate->method('allows')->willReturn(true);
        $this->stubResolvableModel($this->capableModel());
        $this->aiFacade->method('chat')->willThrowException(new ProviderException(
            'tools is not supported for this model',
            'openai',
            null,
            400,
        ));

        $response = $this->controller()->chatCompletions(
            $this->jsonRequest([
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => [self::WEATHER_TOOL],
            ]),
            $this->user(),
        );

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('tools_not_supported', $data['error']['code']);
    }

    public function testListModelsAdvertisesCapabilityOnlyWhenGatePasses(): void
    {
        $capable = $this->capableModel();
        $plain = $this->createMock(Model::class);
        $plain->method('getProviderId')->willReturn('llama3.1:8b');
        $plain->method('getName')->willReturn('Llama');
        $plain->method('getService')->willReturn('Ollama');

        $this->modelRepository->expects(self::any())->method('findBy')->with(['active' => 1])->willReturn([$capable, $plain]);
        $this->toolCallingGate->method('allows')->willReturnCallback(
            static fn (Model $model): bool => 'test-model' === $model->getProviderId()
        );

        $response = $this->controller()->listModels($this->user());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(['synaplan:tool_use'], $data['data'][0]['capabilities']);
        self::assertArrayNotHasKey('capabilities', $data['data'][1]);
    }

    private function wireTestProvider(): void
    {
        $provider = new TestProvider();
        $this->aiFacade->method('chat')->willReturnCallback(
            static function (array $messages, ?int $userId, array $options) use ($provider): array {
                $result = $provider->chat($messages, $options);

                return [
                    'content' => $result['content'],
                    'provider' => 'test',
                    'model' => $options['model'] ?? 'test-model',
                    'usage' => $result['usage'],
                    'tool_calls' => $result['tool_calls'] ?? null,
                    'finish_reason' => $result['finish_reason'] ?? 'stop',
                ];
            }
        );
        $this->aiFacade->method('chatStream')->willReturnCallback(
            static function (array $messages, callable $callback, ?int $userId, array $options) use ($provider): array {
                $meta = $provider->chatStream($messages, $callback, $options);

                return [
                    'provider' => 'test',
                    'model' => $options['model'] ?? 'test-model',
                    'usage' => $meta['usage'],
                ];
            }
        );
    }

    private function stubResolvableModel(Model $model): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn((int) $model->getId());
        $this->modelRepository->method('find')->willReturn($model);
    }

    private function capableModel(): Model
    {
        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('test');
        $model->method('getProviderId')->willReturn('test-model');
        $model->method('getName')->willReturn('Test Model');
        $model->method('getId')->willReturn(9);
        $model->expects(self::any())->method('hasFeature')->with('tool_use')->willReturn(true);

        return $model;
    }

    private function controller(): OpenAICompatibleController
    {
        $toolLoop = $this->createMock(OpenAiGatewayToolLoop::class);
        $toolLoop->method('complete')->willReturnCallback(
            fn (User $user, array $messages, array $options): array => $this->aiFacade->chat($messages, $user->getId(), $options)
        );
        $toolLoop->method('stream')->willReturnCallback(
            fn (User $user, array $messages, callable $callback, array $options): array => $this->aiFacade->chatStream($messages, $callback, $user->getId(), $options)
        );

        return new OpenAICompatibleController(
            $this->aiFacade,
            $this->modelRepository,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->createConfiguredMock(MessagesGatewayConfig::class, ['isSessionSummaryEnabled' => false]),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
            $this->toolCallingGate,
            $toolLoop,
        );
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $request = Request::create('/v1/chat/completions', 'POST', [], [], [], [], json_encode($body));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function captureSse(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
