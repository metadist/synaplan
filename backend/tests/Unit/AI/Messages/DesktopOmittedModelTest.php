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
        self::assertSame($model, $fallback->selectedModel($this->user()));
    }

    public function testAFullKeyThatAlsoListsDesktopScopesDoesNotTakeTheChatDefault(): void
    {
        $fallback = new DesktopOmittedModel(
            $this->createMock(ModelConfigService::class),
            $this->createMock(ModelRepository::class),
        );
        $request = $this->requestWithoutModel();
        $request->attributes->set('api_key', (new ApiKey())->setScopes(['desktop:messages', '*']));

        self::assertFalse($fallback->applies($request, null));
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
        $fallback->method('selectedModel')->willReturn(null);

        $resolver = $this->createMock(MessagesModelResolver::class);
        $resolver->expects(self::once())->method('resolve')->with(null)->willReturn(null);
        $resolver->expects(self::never())->method('resolveSelected');
        $resolver->method('listResolvableAnthropicModelIds')->willReturn([]);

        $result = $this->gateway($resolver, $fallback)->prepare($this->requestWithoutModel(), $this->user());

        self::assertFalse($result['ok']);
        self::assertSame(404, $result['status']);
        self::assertStringContainsString('(none)', $result['message']);
    }

    public function testGatewayForwardsTheSelectedRowNotASecondLookup(): void
    {
        $model = $this->createMock(Model::class);
        $fallback = $this->createMock(DesktopOmittedModel::class);
        $fallback->method('applies')->willReturn(true);
        $fallback->method('selectedModel')->willReturn($model);

        $resolver = $this->createMock(MessagesModelResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $resolver->expects(self::once())->method('resolveSelected')->with($model)->willReturn([
            'provider' => 'openai',
            'providerModelId' => 'gpt-5.4',
            'displayModel' => 'gpt-5.4',
            'model_id' => 42,
            'requested' => 'gpt-5.4',
            'aliased_from' => null,
        ]);

        $result = $this->gateway($resolver, $fallback, ready: true)->prepare($this->requestWithoutModel(), $this->user());

        self::assertTrue($result['ok']);
        self::assertSame('gpt-5.4', $result['request_body']['model']);
        self::assertSame('gpt-5.4', $result['translator_context']['provider_model_id']);
        self::assertSame(42, $result['resolved']['model_id']);
    }

    private function gateway(MessagesModelResolver $resolver, DesktopOmittedModel $fallback, bool $ready = false): MessagesGateway
    {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('allowOperatorKey')->willReturn(true);
        $config->method('upstreamUrl')->willReturn('https://api.anthropic.com');
        $config->method('webFetchMode')->willReturn(MessagesGatewayConfig::WEB_FETCH_OFF);
        $config->method('isContextInjectionEnabled')->willReturn(false);

        $vision = $this->createMock(VisionPolicy::class);
        $vision->method('apply')->willReturnCallback(static fn (array $body): array => [
            'body' => $body,
            'mutated' => false,
            'mode' => MessagesGatewayConfig::VISION_AUTO,
            'detail' => MessagesGatewayConfig::IMAGE_DETAIL_AUTO,
            'images_forwarded' => 0,
            'images_omitted' => 0,
        ]);

        $keys = $this->createMock(\App\AI\Credential\UserProviderKeyResolver::class);
        if ($ready) {
            $keys->method('resolve')->willReturn(['key' => 'sk-test', 'source' => 'operator']);
        }

        $passthrough = $this->createMock(AnthropicPassthroughTranslator::class);
        $passthrough->method('supports')->willReturnCallback(
            static fn (string $name): bool => 'openai' === strtolower($name) || 'anthropic' === strtolower($name),
        );

        $tools = $this->createMock(GatewayToolCatalog::class);
        $tools->method('build')->willReturn([
            'tools' => [],
            'dispatch' => [],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
        ]);
        $tools->method('replacedServerTools')->willReturn([]);

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
            $keys,
            $limits,
            new PremiumFeatureGate(new BillingService('', '')),
            $passthrough,
            $tools,
            $this->createMock(GatewayToolLoop::class),
            new WebFetchPolicy(),
            $vision,
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
