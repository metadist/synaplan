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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Image turns: rewrite onto Synaplan PIC2TEXT when the resolved model cannot
 * see, otherwise leave images on the wire (Anthropic / upstream funnel).
 */
final class MessagesGatewayVisionTest extends TestCase
{
    public function testAutoRewritesOntoVisionModelWhenResolvedLacksVision(): void
    {
        $current = $this->model(42, 'anthropic', 'text-only', vision: false);
        $vision = $this->model(99, 'openai', 'gpt-4o', vision: true);

        $gateway = $this->gateway(
            MessagesGatewayConfig::VISION_AUTO,
            $current,
            $vision,
            expectedProvider: 'openai',
        );

        $result = $gateway->prepare($this->imageRequest('text-only'), $this->user());

        self::assertTrue($result['ok']);
        self::assertSame(GatewayToolCatalog::VISION_SYNAPLAN, $result['vision']['handling']);
        self::assertSame(99, $result['resolved']['model_id']);
        self::assertSame('gpt-4o', $result['request_body']['model']);
    }

    public function testAutoLeavesVisionCapableModelUntouched(): void
    {
        $current = $this->model(42, 'anthropic', 'claude-sonnet-4-5', vision: true);

        $visionResolver = $this->createMock(VisionModelResolver::class);
        $visionResolver->expects($this->never())->method('resolve');

        $gateway = $this->gateway(
            MessagesGatewayConfig::VISION_AUTO,
            $current,
            null,
            expectedProvider: 'anthropic',
            visionResolver: $visionResolver,
        );

        $result = $gateway->prepare($this->imageRequest('claude-sonnet-4-5'), $this->user());

        self::assertTrue($result['ok']);
        self::assertSame(GatewayToolCatalog::VISION_PASSTHROUGH, $result['vision']['handling']);
        self::assertSame(42, $result['resolved']['model_id']);
    }

    public function testPassthroughModeNeverRewrites(): void
    {
        $current = $this->model(42, 'anthropic', 'text-only', vision: false);

        $visionResolver = $this->createMock(VisionModelResolver::class);
        $visionResolver->expects($this->never())->method('resolve');

        $gateway = $this->gateway(
            MessagesGatewayConfig::VISION_PASSTHROUGH,
            $current,
            null,
            expectedProvider: 'anthropic',
            visionResolver: $visionResolver,
        );

        $result = $gateway->prepare($this->imageRequest('text-only'), $this->user());

        self::assertTrue($result['ok']);
        self::assertSame(GatewayToolCatalog::VISION_PASSTHROUGH, $result['vision']['handling']);
        self::assertSame(42, $result['resolved']['model_id']);
    }

    public function testNoImagesYieldsVisionNone(): void
    {
        $current = $this->model(42, 'anthropic', 'claude-sonnet-4-5', vision: true);
        $gateway = $this->gateway(
            MessagesGatewayConfig::VISION_AUTO,
            $current,
            null,
            expectedProvider: 'anthropic',
        );

        $result = $gateway->prepare(Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ])), $this->user());

        self::assertTrue($result['ok']);
        self::assertSame(GatewayToolCatalog::VISION_NONE, $result['vision']['handling']);
    }

    private function gateway(
        string $visionMode,
        Model $current,
        ?Model $visionFallback,
        string $expectedProvider,
        ?VisionModelResolver $visionResolver = null,
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
            'provider' => strtolower($current->getService()),
            'providerModelId' => $current->getProviderId(),
            'displayModel' => $current->getProviderId(),
            'model_id' => (int) $current->getId(),
            'requested' => $current->getProviderId(),
            'aliased_from' => null,
        ]);

        $models = $this->createMock(ModelRepository::class);
        $models->expects($this->any())->method('find')->with((int) $current->getId())->willReturn($current);

        if (null === $visionResolver) {
            $visionResolver = $this->createMock(VisionModelResolver::class);
            $visionResolver->method('resolve')->willReturn($visionFallback);
        }

        $keys = $this->createMock(UserProviderKeyResolver::class);
        $keys->expects($this->any())->method('resolve')->with($expectedProvider, 7, true)->willReturn([
            'key' => 'sk-test',
            'source' => 'operator',
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
        $passthrough->method('supports')->willReturn(true);

        $toolCatalog = $this->createMock(GatewayToolCatalog::class);
        $toolCatalog->method('build')->willReturn([
            'tools' => [],
            'dispatch' => [],
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
        ]);
        $toolCatalog->method('replacedServerTools')->willReturn([]);

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
