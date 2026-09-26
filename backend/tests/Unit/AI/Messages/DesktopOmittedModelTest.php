<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\DesktopOmittedModel;
use App\AI\Messages\MessagesGateway;
use App\AI\Messages\MessagesModelResolver;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\WebFetchPolicy;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use App\AI\Messages\Vision\VisionPolicy;
use App\Entity\ApiKey;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Security\ApiKeyScope;
use App\Service\BillingService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\ModelConfigService;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class DesktopOmittedModelTest extends TestCase
{
    public function testPairedDesktopKeyWithoutModelUsesTheAccountChatModel(): void
    {
        $model = $this->createMock(Model::class);
        $model->method('getActive')->willReturn(1);
        $model->method('getProviderId')->willReturn('claude-sonnet-4-6');
        $model->method('getName')->willReturn('Claude');

        $config = $this->createMock(ModelConfigService::class);
        $config->expects(self::once())->method('getDefaultModel')->with('CHAT', 7)->willReturn(42);

        $models = $this->createMock(ModelRepository::class);
        $models->expects(self::once())->method('find')->with(42)->willReturn($model);

        $fallback = new DesktopOmittedModel($config, $models);
        $request = $this->requestWithoutModel();
        $request->attributes->set('api_key', (new ApiKey())->setScopes(ApiKeyScope::pairingScopes()));

        self::assertTrue($fallback->applies($request, null));
        self::assertSame('claude-sonnet-4-6', $fallback->providerIdFor($this->user()));
    }

    public function testAFullKeyThatOmitsTheModelDoesNotTakeTheChatDefault(): void
    {
        $fallback = new DesktopOmittedModel(
            $this->createMock(ModelConfigService::class),
            $this->createMock(ModelRepository::class),
        );
        $request = $this->requestWithoutModel();
        $request->attributes->set('api_key', (new ApiKey())->setScopes(['*']));

        self::assertFalse($fallback->applies($request, null));
    }

    public function testAnExplicitModelIsNeverReplaced(): void
    {
        $fallback = new DesktopOmittedModel(
            $this->createMock(ModelConfigService::class),
            $this->createMock(ModelRepository::class),
        );
        $request = $this->requestWithoutModel();
        $request->attributes->set('api_key', (new ApiKey())->setScopes(ApiKeyScope::pairingScopes()));

        self::assertFalse($fallback->applies($request, 'claude-opus-5-5'));
    }

    public function testGatewayResolvesTheFilledModelInsteadOfNone(): void
    {
        $fallback = $this->createMock(DesktopOmittedModel::class);
        $fallback->method('applies')->willReturn(true);
        $fallback->method('providerIdFor')->willReturn('claude-sonnet-4-6');

        $resolver = $this->createMock(MessagesModelResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('claude-sonnet-4-6')->willReturn(null);
        $resolver->method('listResolvableAnthropicModelIds')->willReturn([]);

        $result = $this->gateway($resolver, $fallback)->prepare($this->requestWithoutModel(), $this->user());

        self::assertFalse($result['ok']);
        self::assertSame(404, $result['status']);
        self::assertStringContainsString('claude-sonnet-4-6', $result['message']);
        self::assertStringNotContainsString('(none)', $result['message']);
    }

    private function gateway(MessagesModelResolver $resolver, DesktopOmittedModel $fallback): MessagesGateway
    {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $limits = $this->createMock(RateLimitService::class);
        $limits->method('checkLimit')->willReturn([
            'allowed' => true,
            'limit' => 10,
            'used' => 0,
            'remaining' => 10,
        ]);
        $limits->method('checkCostBudget')->willReturn([
            'allowed' => true,
            'used_cost' => '0',
            'budget' => '1',
            'remaining' => '1',
            'percent' => 0.0,
        ]);

        return new MessagesGateway(
            $config,
            $resolver,
            $this->createMock(ModelRepository::class),
            $this->createMock(VisionModelResolver::class),
            $this->createMock(\App\AI\Credential\UserProviderKeyResolver::class),
            $limits,
            new PremiumFeatureGate(new BillingService('', '')),
            $this->createMock(AnthropicPassthroughTranslator::class),
            $this->createMock(GatewayToolCatalog::class),
            $this->createMock(GatewayToolLoop::class),
            new WebFetchPolicy(),
            $this->createMock(VisionPolicy::class),
            $this->createMock(\App\AI\Messages\MessagesContextInjector::class),
            $this->createMock(\Psr\Cache\CacheItemPoolInterface::class),
            $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class),
            new NullLogger(),
            desktopOmittedModel: $fallback,
        );
    }

    private function user(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function requestWithoutModel(): Request
    {
        return Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]));
    }
}
