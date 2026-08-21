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
use App\AI\Messages\Vision\VisionPolicy;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\BillingService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * BYO-key plan gate in {@see MessagesGateway::prepare()}:
 *  - a BYO key requires at least the Pro plan (403 below it),
 *  - BYO requests are not blocked by the Synaplan cost budget (zero-cost metering),
 *  - operator-key requests stay budget-gated (429 when exhausted),
 *  - an install without billing lets every level bring its own key.
 */
class MessagesGatewayByoPlanTest extends TestCase
{
    private MessagesGatewayConfig&MockObject $config;
    private RateLimitService&MockObject $rateLimitService;
    private UserProviderKeyResolver&MockObject $keyResolver;
    private MessagesGateway $gateway;

    protected function setUp(): void
    {
        $this->config = $this->createMock(MessagesGatewayConfig::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('allowOperatorKey')->willReturn(true);
        $this->config->method('isMcpToolsEnabled')->willReturn(false);
        $this->config->method('isContextInjectionEnabled')->willReturn(false);
        $this->config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_AUTO);
        $this->config->method('webFetchMode')->willReturn(MessagesGatewayConfig::WEB_FETCH_OFF);
        $this->config->method('upstreamUrl')->willReturn('https://api.anthropic.com');

        $this->keyResolver = $this->createMock(UserProviderKeyResolver::class);

        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->rateLimitService->method('checkLimit')->willReturn([
            'allowed' => true,
            'limit' => 100,
            'used' => 1,
            'remaining' => 99,
        ]);

        $this->gateway = $this->buildGateway($this->makeGate(billingEnabled: true));
    }

    private function buildGateway(PremiumFeatureGate $premiumFeatureGate): MessagesGateway
    {
        $modelResolver = $this->createMock(MessagesModelResolver::class);
        $modelResolver->method('resolve')->willReturn([
            'provider' => 'anthropic',
            'providerModelId' => 'claude-sonnet-4-5',
            'displayModel' => 'claude-sonnet-4-5',
            'model_id' => 42,
            'requested' => 'claude-sonnet-4-5',
            'aliased_from' => null,
        ]);

        $passthrough = $this->createMock(AnthropicPassthroughTranslator::class);
        $passthrough->method('supports')->willReturn(true);

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
            $this->config,
            $modelResolver,
            $this->createMock(ModelRepository::class),
            $this->createMock(VisionModelResolver::class),
            $this->keyResolver,
            $this->rateLimitService,
            $premiumFeatureGate,
            $passthrough,
            $toolCatalog,
            $this->createMock(GatewayToolLoop::class),
            new WebFetchPolicy(),
            $visionPolicy,
            $this->createMock(MessagesContextInjector::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    /**
     * BillingService and PremiumFeatureGate are final, so the open-source mode
     * is expressed the way production does it: through the Stripe config.
     */
    private function makeGate(bool $billingEnabled): PremiumFeatureGate
    {
        return new PremiumFeatureGate(
            $billingEnabled
                ? new BillingService('sk_live_test', 'price_1RealPro')
                : new BillingService('', ''),
        );
    }

    private function makeUser(string $level): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn($level);

        return $user;
    }

    private function makeRequest(): Request
    {
        return Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]));
    }

    private function budget(bool $allowed): array
    {
        return [
            'allowed' => $allowed,
            'used_cost' => $allowed ? '1.00' : '20.00',
            'budget' => '19.95',
            'remaining' => $allowed ? '18.95' : '0.00',
            'percent' => $allowed ? 5.0 : 100.0,
        ];
    }

    public function testByoKeyBelowProPlanIsRefused(): void
    {
        $this->rateLimitService->method('checkCostBudget')->willReturn($this->budget(true));
        $this->keyResolver->method('resolve')->willReturn(['key' => 'sk-ant-own', 'source' => 'user']);

        $result = $this->gateway->prepare($this->makeRequest(), $this->makeUser('NEW'));

        self::assertFalse($result['ok']);
        self::assertSame(403, $result['status']);
        self::assertSame('permission_error', $result['error_type']);
        self::assertStringContainsString('Pro plan', $result['message']);
    }

    public function testByoKeyOnProPlanIsNotBlockedByExhaustedBudget(): void
    {
        // Budget exhausted — must not matter for BYO: the user pays the provider.
        $this->rateLimitService->method('checkCostBudget')->willReturn($this->budget(false));
        $this->keyResolver->method('resolve')->willReturn(['key' => 'sk-ant-own', 'source' => 'user']);

        $result = $this->gateway->prepare($this->makeRequest(), $this->makeUser('PRO'));

        self::assertTrue($result['ok']);
        self::assertSame('user', $result['key_source']);
    }

    public function testOperatorKeyStaysBudgetGated(): void
    {
        $this->rateLimitService->method('checkCostBudget')->willReturn($this->budget(false));
        $this->keyResolver->method('resolve')->willReturn(['key' => 'sk-ant-operator', 'source' => 'operator']);

        $result = $this->gateway->prepare($this->makeRequest(), $this->makeUser('PRO'));

        self::assertFalse($result['ok']);
        self::assertSame(429, $result['status']);
        self::assertSame('rate_limit_error', $result['error_type']);
        self::assertStringContainsString('cost budget exceeded', $result['message']);
    }

    public function testOperatorKeyWithinBudgetSucceedsForFreeUsers(): void
    {
        // Operator-key users do NOT need the Pro plan — the cost budget gates them.
        $this->rateLimitService->method('checkCostBudget')->willReturn($this->budget(true));
        $this->keyResolver->method('resolve')->willReturn(['key' => 'sk-ant-operator', 'source' => 'operator']);

        $result = $this->gateway->prepare($this->makeRequest(), $this->makeUser('NEW'));

        self::assertTrue($result['ok']);
        self::assertSame('operator', $result['key_source']);
    }

    public function testByoKeyIsAllowedForEveryLevelWithoutBilling(): void
    {
        $this->rateLimitService->method('checkCostBudget')->willReturn($this->budget(true));
        $this->keyResolver->method('resolve')->willReturn(['key' => 'sk-ant-own', 'source' => 'user']);

        $openSourceGateway = $this->buildGateway($this->makeGate(billingEnabled: false));
        $result = $openSourceGateway->prepare($this->makeRequest(), $this->makeUser('NEW'));

        self::assertTrue($result['ok']);
        self::assertSame('user', $result['key_source']);
    }
}
