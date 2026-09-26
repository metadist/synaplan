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
use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Security\ApiKeyScope;
use App\Service\BillingService;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * App chat on a paired computer is not the same path as Claude Code.
 * Synaplan Desktop off refuses that chat. A missing provider key stays a
 * 403 that says the computer is still paired, so the app does not treat it
 * as a disconnected device.
 */
class MessagesGatewayDesktopChatReadinessTest extends TestCase
{
    private MessagesGatewayConfig $config;
    private UserProviderKeyResolver $keyResolver;
    private DesktopAgentConfig $desktop;
    private bool $gatewayEnabled = true;
    private bool $desktopEnabled = true;

    protected function setUp(): void
    {
        $this->config = $this->createStub(MessagesGatewayConfig::class);
        $this->config->method('isEnabled')->willReturnCallback(fn (): bool => $this->gatewayEnabled);
        $this->config->method('allowOperatorKey')->willReturn(false);

        $this->keyResolver = $this->createStub(UserProviderKeyResolver::class);
        $this->keyResolver->method('resolve')->willReturn(null);

        $this->desktop = $this->createStub(DesktopAgentConfig::class);
        $this->desktop->method('isEnabled')->willReturnCallback(fn (): bool => $this->desktopEnabled);
    }

    public function testPairedComputerCannotChatWhenDesktopIsTurnedOff(): void
    {
        $this->desktopEnabled = false;

        $result = $this->gateway()->prepare($this->desktopRequest(), $this->user());

        self::assertFalse($result['ok']);
        self::assertSame(403, $result['status']);
        self::assertSame('permission_error', $result['error_type']);
        self::assertStringContainsString('Synaplan Desktop is turned off', $result['message']);
        self::assertStringNotContainsString('gateway', strtolower($result['message']));
    }

    public function testGatewayOffKeepsItsSentenceWhenDesktopIsOn(): void
    {
        $this->gatewayEnabled = false;

        $result = $this->gateway()->prepare($this->desktopRequest(), $this->user());

        self::assertSame(403, $result['status']);
        self::assertSame(
            'Messages gateway is disabled on this Synaplan instance.',
            $result['message'],
        );
    }

    public function testMissingProviderKeyForAPairedComputerIsNotUnauthorized(): void
    {
        $result = $this->gateway()->prepare($this->desktopRequest(), $this->user());

        self::assertFalse($result['ok']);
        self::assertSame(403, $result['status']);
        self::assertSame('permission_error', $result['error_type']);
        self::assertStringContainsString('still paired', $result['message']);
        self::assertStringContainsString('Nothing was sent', $result['message']);
        self::assertStringNotContainsString('gateway', strtolower($result['message']));
    }

    public function testMissingProviderKeyForClaudeCodeStaysUnauthorized(): void
    {
        $result = $this->gateway()->prepare($this->claudeRequest(), $this->user());

        self::assertSame(401, $result['status']);
        self::assertSame('authentication_error', $result['error_type']);
        self::assertStringContainsString('No API key available', $result['message']);
    }

    public function testFullKeyThatNamesItselfDesktopStaysUnauthorized(): void
    {
        $this->desktopEnabled = false;

        $result = $this->gateway()->prepare($this->claudeRequest(userAgent: 'synaplan-desktop/0.1'), $this->user());

        self::assertSame(401, $result['status']);
        self::assertStringContainsString('No API key available', $result['message']);
    }

    public function testWithoutDesktopConfigAPairedComputerKeepsTheGatewaySentence(): void
    {
        $this->gatewayEnabled = false;

        $result = $this->gateway(withDesktopConfig: false)->prepare($this->desktopRequest(), $this->user());

        self::assertSame(
            'Messages gateway is disabled on this Synaplan instance.',
            $result['message'],
        );
    }

    private function gateway(bool $withDesktopConfig = true): MessagesGateway
    {
        $modelResolver = $this->createStub(MessagesModelResolver::class);
        $modelResolver->method('resolve')->willReturn([
            'provider' => 'anthropic',
            'providerModelId' => 'claude-sonnet-4-5',
            'displayModel' => 'claude-sonnet-4-5',
            'model_id' => 42,
            'requested' => 'claude-sonnet-4-5',
            'aliased_from' => null,
        ]);

        $passthrough = $this->createStub(AnthropicPassthroughTranslator::class);
        $passthrough->method('supports')->willReturn(true);

        $toolCatalog = $this->createStub(GatewayToolCatalog::class);
        $toolCatalog->method('build')->willReturn([
            'tools' => [],
            'dispatch' => [],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
        ]);
        $toolCatalog->method('replacedServerTools')->willReturn([]);

        $visionPolicy = $this->createStub(VisionPolicy::class);
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

        $rateLimit = $this->createStub(RateLimitService::class);
        $rateLimit->method('checkLimit')->willReturn([
            'allowed' => true,
            'limit' => 100,
            'used' => 1,
            'remaining' => 99,
        ]);
        $rateLimit->method('checkCostBudget')->willReturn([
            'allowed' => true,
            'used_cost' => '0.00',
            'budget' => '19.95',
            'remaining' => '19.95',
            'percent' => 0.0,
        ]);

        return new MessagesGateway(
            $this->config,
            $modelResolver,
            $this->createStub(ModelRepository::class),
            $this->createStub(VisionModelResolver::class),
            $this->keyResolver,
            $rateLimit,
            new PremiumFeatureGate(new BillingService('', '')),
            $passthrough,
            $toolCatalog,
            $this->createStub(GatewayToolLoop::class),
            new WebFetchPolicy(),
            $visionPolicy,
            $this->createStub(MessagesContextInjector::class),
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(MessageBusInterface::class),
            new NullLogger(),
            desktopAgentConfig: $withDesktopConfig ? $this->desktop : null,
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function desktopRequest(): Request
    {
        $key = new ApiKey();
        $key->setScopes(ApiKeyScope::pairingScopes());

        $request = $this->jsonRequest();
        $request->attributes->set('api_key', $key);
        $request->headers->set('User-Agent', 'synaplan-desktop/0.1');

        return $request;
    }

    private function claudeRequest(?string $userAgent = null): Request
    {
        $key = new ApiKey();
        $key->setScopes([ApiKeyScope::WILDCARD]);

        $request = $this->jsonRequest();
        $request->attributes->set('api_key', $key);
        if (null !== $userAgent) {
            $request->headers->set('User-Agent', $userAgent);
        }

        return $request;
    }

    private function jsonRequest(): Request
    {
        return Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]));
    }
}
