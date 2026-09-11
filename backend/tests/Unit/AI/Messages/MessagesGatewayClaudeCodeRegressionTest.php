<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\MessagesContextInjector;
use App\AI\Messages\MessagesGateway;
use App\AI\Messages\MessagesModelResolver;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\WebFetchPolicy;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use App\AI\Messages\Translator\ChatCompletionsUpstreams;
use App\AI\Messages\Translator\GeminiMessagesTranslator;
use App\AI\Messages\Translator\OpenAiMessagesTranslator;
use App\AI\Messages\Vision\VisionPolicy;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\BillingService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Claude Code must keep the Anthropic passthrough and MODEL_ALIASES routes
 * after Desktop gained Groq / other Chat Completions hosts.
 */
final class MessagesGatewayClaudeCodeRegressionTest extends TestCase
{
    public function testAnthropicPassthroughWinsWhenOpenAiTranslatorIsRegisteredFirst(): void
    {
        $gateway = $this->gateway(
            provider: 'anthropic',
            providerModelId: 'claude-sonnet-4-5',
            aliasedFrom: null,
        );

        $result = $gateway->prepare($this->textRequest('claude-sonnet-4-5'), $this->user());

        self::assertTrue($result['ok']);
        self::assertTrue($result['raw_stream']);
        self::assertSame('anthropic', $result['resolved']['provider']);
        self::assertFalse($result['body_mutated']);
    }

    public function testModelAliasToOpenAiStillHitsOpenAiNotGroq(): void
    {
        $seenUrl = null;
        $openaiClient = new MockHttpClient(static function (string $method, string $url) use (&$seenUrl): MockResponse {
            $seenUrl = $url;

            return new MockResponse((string) json_encode([
                'id' => 'chatcmpl_alias',
                'model' => 'gpt-4o',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'aliased'],
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2],
            ]));
        });

        $gateway = $this->gateway(
            provider: 'openai',
            providerModelId: 'gpt-4o',
            aliasedFrom: 'claude-sonnet-4-6',
            openaiClient: $openaiClient,
        );

        $prepared = $gateway->prepare($this->textRequest('claude-sonnet-4-6'), $this->user());
        self::assertTrue($prepared['ok']);
        self::assertFalse($prepared['raw_stream']);
        self::assertSame('openai', $prepared['resolved']['provider']);
        self::assertSame('claude-sonnet-4-6', $prepared['resolved']['aliased_from']);
        self::assertSame('gpt-4o', $prepared['request_body']['model']);

        $executed = $gateway->executeComplete($prepared, $this->user());
        self::assertSame(200, $executed['status']);
        self::assertSame(ChatCompletionsUpstreams::URLS['openai'], $seenUrl);
        self::assertStringNotContainsString('groq', (string) $seenUrl);
    }

    public function testModelAliasToGeminiStillSelectsGeminiTranslator(): void
    {
        $seenUrl = null;
        $geminiClient = new MockHttpClient(static function (string $method, string $url) use (&$seenUrl): MockResponse {
            $seenUrl = $url;

            return new MockResponse((string) json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'gemini-alias']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
            ]));
        });

        $gateway = $this->gateway(
            provider: 'google',
            providerModelId: 'gemini-2.0-flash',
            aliasedFrom: 'claude-sonnet-4-6',
            geminiClient: $geminiClient,
        );

        $prepared = $gateway->prepare($this->textRequest('claude-sonnet-4-6'), $this->user());
        self::assertTrue($prepared['ok']);
        self::assertFalse($prepared['raw_stream']);
        self::assertSame('google', $prepared['resolved']['provider']);

        $executed = $gateway->executeComplete($prepared, $this->user());
        self::assertSame(200, $executed['status']);
        self::assertNotNull($seenUrl);
        self::assertStringContainsString('generativelanguage.googleapis.com', (string) $seenUrl);
        self::assertStringContainsString('gemini-2.0-flash', (string) $seenUrl);
        self::assertStringNotContainsString('groq', (string) $seenUrl);
    }

    public function testGroqPic2TextDoesNotRewriteAClaudeCodeImageTurn(): void
    {
        $current = $this->model(42, 'anthropic', 'text-only', vision: false);
        $groqVision = $this->model(99, 'groq', 'qwen-vl', vision: true);

        $gateway = $this->gateway(
            provider: 'anthropic',
            providerModelId: 'text-only',
            aliasedFrom: null,
            currentModel: $current,
            visionFallback: $groqVision,
            visionMode: MessagesGatewayConfig::VISION_SYNAPLAN,
        );

        $result = $gateway->prepare($this->imageRequest('text-only'), $this->user());

        self::assertTrue($result['ok']);
        self::assertTrue($result['raw_stream']);
        self::assertSame(GatewayToolCatalog::VISION_PASSTHROUGH, $result['vision']['handling']);
        self::assertSame(42, $result['resolved']['model_id']);
        self::assertSame('anthropic', $result['resolved']['provider']);
    }

    /**
     * @param 'anthropic'|'openai'|'google' $provider
     */
    private function gateway(
        string $provider,
        string $providerModelId,
        ?string $aliasedFrom,
        ?MockHttpClient $openaiClient = null,
        ?MockHttpClient $geminiClient = null,
        ?Model $currentModel = null,
        ?Model $visionFallback = null,
        string $visionMode = MessagesGatewayConfig::VISION_AUTO,
    ): MessagesGateway {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('allowOperatorKey')->willReturn(true);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('isContextInjectionEnabled')->willReturn(false);
        $config->method('visionMode')->willReturn($visionMode);
        $config->method('webFetchMode')->willReturn(MessagesGatewayConfig::WEB_FETCH_OFF);
        $config->method('visionImageDetail')->willReturn(MessagesGatewayConfig::IMAGE_DETAIL_AUTO);
        $config->method('visionMaxImages')->willReturn(0);
        $config->method('upstreamUrl')->willReturn('https://api.anthropic.com');

        $modelResolver = $this->createMock(MessagesModelResolver::class);
        $modelResolver->method('resolve')->willReturn([
            'provider' => $provider,
            'providerModelId' => $providerModelId,
            'displayModel' => $providerModelId,
            'model_id' => 42,
            'requested' => $aliasedFrom ?? $providerModelId,
            'aliased_from' => $aliasedFrom,
        ]);

        $models = $this->createMock(ModelRepository::class);
        if (null !== $currentModel) {
            $models->expects($this->any())->method('find')->with(42)->willReturn($currentModel);
        }

        $visionResolver = $this->createMock(VisionModelResolver::class);
        $visionResolver->method('resolve')->willReturn($visionFallback);

        $keys = $this->createMock(UserProviderKeyResolver::class);
        $keys->method('resolve')->willReturn(['key' => 'sk-test', 'source' => 'operator']);

        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->method('checkLimit')->willReturn([
            'allowed' => true,
            'limit' => 100,
            'used' => 1,
            'remaining' => 99,
        ]);
        $rateLimits->method('checkCostBudget')->willReturn([
            'allowed' => true,
            'used_cost' => '0.00',
            'budget' => '10.00',
            'remaining' => '10.00',
            'percent' => 0.0,
        ]);

        $passthrough = $this->createMock(AnthropicPassthroughTranslator::class);
        $passthrough->method('supports')->willReturnCallback(
            static fn (string $name): bool => 'anthropic' === strtolower($name),
        );

        $toolCatalog = $this->createMock(GatewayToolCatalog::class);
        $toolCatalog->method('build')->willReturn([
            'tools' => [],
            'dispatch' => [],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
        ]);
        $toolCatalog->method('replacedServerTools')->willReturn([]);

        $openai = new OpenAiMessagesTranslator($openaiClient ?? new MockHttpClient());
        $gemini = new GeminiMessagesTranslator($geminiClient ?? new MockHttpClient());

        return new MessagesGateway(
            $config,
            $modelResolver,
            $models,
            $visionResolver,
            $keys,
            $rateLimits,
            new PremiumFeatureGate(new BillingService('sk_live_test', 'price_1RealPro')),
            $passthrough,
            $toolCatalog,
            $this->createMock(GatewayToolLoop::class),
            new WebFetchPolicy(),
            new VisionPolicy($config, new NullLogger()),
            $this->createMock(MessagesContextInjector::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
            // Chat Completions translator first — the regression we lock.
            translators: [$openai, $gemini, $passthrough],
        );
    }

    private function model(int $id, string $service, string $providerId, bool $vision): Model
    {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($providerId);
        $model->expects($this->any())->method('hasFeature')->with('vision')->willReturn($vision);

        return $model;
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('PRO');

        return $user;
    }

    private function textRequest(string $model): Request
    {
        return Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'model' => $model,
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]));
    }

    private function imageRequest(string $model): Request
    {
        return Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'model' => $model,
            'max_tokens' => 64,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What is on this page?'],
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'image/png',
                            'data' => 'aaa',
                        ],
                    ],
                ],
            ]],
        ]));
    }
}
