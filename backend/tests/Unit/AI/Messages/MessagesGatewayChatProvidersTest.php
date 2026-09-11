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
use App\AI\Messages\Translator\OpenAiMessagesTranslator;
use App\AI\Messages\Vision\VisionPolicy;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Desktop (and Claude Code aliases) must accept every catalog chat model that
 * already speaks Anthropic, Gemini, or OpenAI Chat Completions — not only
 * the three original Claude Code hosts.
 */
final class MessagesGatewayChatProvidersTest extends TestCase
{
    public function testGroqChatModelPreparesWithTheOpenAiTranslator(): void
    {
        $keyResolver = $this->createMock(UserProviderKeyResolver::class);
        $keyResolver->expects($this->once())
            ->method('resolve')
            ->with('groq', 7, true)
            ->willReturn(['key' => 'gsk_test', 'source' => 'operator']);

        $gateway = $this->gateway(
            provider: 'groq',
            providerModelId: 'llama-3.3-70b-versatile',
            keyResolver: $keyResolver,
        );

        $result = $gateway->prepare($this->request('groq:llama-3.3-70b-versatile:chat'), $this->user());

        self::assertTrue($result['ok']);
        self::assertSame('groq', $result['resolved']['provider']);
        self::assertSame('groq', $result['translator_context']['provider']);
        self::assertSame('llama-3.3-70b-versatile', $result['translator_context']['provider_model_id']);
    }

    public function testOllamaDoesNotRequireAProviderKey(): void
    {
        $keyResolver = $this->createMock(UserProviderKeyResolver::class);
        $keyResolver->expects($this->never())->method('resolve');

        $gateway = $this->gateway(
            provider: 'ollama',
            providerModelId: 'llama3.2',
            keyResolver: $keyResolver,
        );

        $result = $gateway->prepare($this->request('ollama:llama3.2:chat'), $this->user());

        self::assertTrue($result['ok']);
        self::assertSame('operator', $result['key_source']);
        self::assertSame('local', $result['translator_context']['api_key']);
    }

    public function testUnsupportedProviderIsRejectedWithChatHostList(): void
    {
        $keyResolver = $this->createMock(UserProviderKeyResolver::class);
        $keyResolver->method('resolve')->willReturn(['key' => 'unused', 'source' => 'operator']);

        $gateway = $this->gateway(
            provider: 'triton',
            providerModelId: 'mistral-7b',
            keyResolver: $keyResolver,
        );

        $result = $gateway->prepare($this->request('triton:mistral-7b:chat'), $this->user());

        self::assertFalse($result['ok']);
        self::assertSame(400, $result['status']);
        self::assertSame('invalid_request_error', $result['error_type']);
        self::assertStringContainsString('triton', $result['message']);
        self::assertStringContainsString('Groq', $result['message']);
        self::assertStringContainsString('MODEL_ALIASES', $result['message']);
    }

    /**
     * @param 'groq'|'ollama'|'triton' $provider
     */
    private function gateway(
        string $provider,
        string $providerModelId,
        UserProviderKeyResolver $keyResolver,
    ): MessagesGateway {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('allowOperatorKey')->willReturn(true);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('isContextInjectionEnabled')->willReturn(false);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_AUTO);
        $config->method('webFetchMode')->willReturn(MessagesGatewayConfig::WEB_FETCH_OFF);
        $config->method('upstreamUrl')->willReturn('https://api.anthropic.com');

        $modelResolver = $this->createMock(MessagesModelResolver::class);
        $modelResolver->method('resolve')->willReturn([
            'provider' => $provider,
            'providerModelId' => $providerModelId,
            'displayModel' => $providerModelId,
            'model_id' => 42,
            'requested' => $providerModelId,
            'aliased_from' => null,
        ]);

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
        $passthrough->method('supports')->willReturn(false);

        $toolCatalog = $this->createMock(GatewayToolCatalog::class);
        $toolCatalog->method('build')->willReturn([
            'tools' => [],
            'dispatch' => [],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
        ]);
        $toolCatalog->method('replacedServerTools')->willReturn([]);

        $visionPolicy = $this->createMock(VisionPolicy::class);
        $visionPolicy->method('apply')->willReturnCallback(
            static fn (array $body): array => [
                'body' => $body,
                'mutated' => false,
                'mode' => MessagesGatewayConfig::VISION_AUTO,
                'detail' => MessagesGatewayConfig::IMAGE_DETAIL_AUTO,
                'images_forwarded' => 0,
                'images_omitted' => 0,
            ],
        );

        return new MessagesGateway(
            $config,
            $modelResolver,
            $this->createMock(ModelRepository::class),
            $this->createMock(VisionModelResolver::class),
            $keyResolver,
            $rateLimits,
            new PremiumFeatureGate(new BillingService('sk_live_test', 'price_1RealPro')),
            $passthrough,
            $toolCatalog,
            $this->createMock(GatewayToolLoop::class),
            new WebFetchPolicy(),
            $visionPolicy,
            $this->createMock(MessagesContextInjector::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
            translators: [new OpenAiMessagesTranslator(new MockHttpClient())],
        );
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('PRO');

        return $user;
    }

    private function request(string $model): Request
    {
        return Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'model' => $model,
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]));
    }
}
