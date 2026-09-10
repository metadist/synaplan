<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\DesktopTurnOptions;
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
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\BillingService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * C15 — pinning an Assistant must not replace the body `model`.
 */
final class MessagesGatewayDesktopPinTest extends TestCase
{
    public function testPinnedAssistantDoesNotReplaceBodyModel(): void
    {
        $config = $this->createMock(MessagesGatewayConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('allowOperatorKey')->willReturn(true);
        $config->method('isMcpToolsEnabled')->willReturn(false);
        $config->method('isContextInjectionEnabled')->willReturn(false);
        $config->method('visionMode')->willReturn(MessagesGatewayConfig::VISION_AUTO);
        $config->method('webFetchMode')->willReturn(MessagesGatewayConfig::WEB_FETCH_OFF);
        $config->method('upstreamUrl')->willReturn('https://api.anthropic.com');

        $modelResolver = $this->createMock(MessagesModelResolver::class);
        $modelResolver->expects($this->once())->method('resolve')->with('project-chat-y')->willReturn([
            'provider' => 'anthropic',
            'providerModelId' => 'project-chat-y',
            'displayModel' => 'project-chat-y',
            'model_id' => 42,
            'requested' => 'project-chat-y',
            'aliased_from' => null,
        ]);

        $keyResolver = $this->createMock(UserProviderKeyResolver::class);
        $keyResolver->method('resolve')->willReturn(['key' => 'sk-ant-operator', 'source' => 'operator']);

        $rateLimitService = $this->createMock(RateLimitService::class);
        $rateLimitService->method('checkLimit')->willReturn([
            'allowed' => true,
            'limit' => 100,
            'used' => 1,
            'remaining' => 99,
        ]);
        $rateLimitService->method('checkCostBudget')->willReturn([
            'allowed' => true,
            'used_cost' => '1.00',
            'budget' => '19.95',
            'remaining' => '18.95',
            'percent' => 5.0,
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

        $recipeProfile = new RuntimeProfile(
            promptId: 9,
            promptTopic: 'agent:review',
            systemPrompt: 'You review contracts.',
            modelIds: ['CHAT' => 999],
            ragScopes: [],
            toolFlags: [],
            skillAllow: null,
            skillDeny: null,
            parameters: [],
            agentId: 12,
        );

        $agentConfig = $this->createMock(AgentConfig::class);
        $agentConfig->method('isEnabled')->willReturn(true);

        $runtimeResolver = $this->createMock(AgentRuntimeResolver::class);
        $runtimeResolver->expects($this->once())->method('resolve')->willReturn($recipeProfile);

        $capturedProfile = null;
        $capturedModel = null;
        $contextInjector = $this->createMock(MessagesContextInjector::class);
        $contextInjector->expects($this->once())->method('inject')->willReturnCallback(
            static function (array $requestBody, User $user, string $sessionKey, ?string $headerOverride, $desktop, $profile) use (&$capturedProfile, &$capturedModel): array {
                $capturedProfile = $profile;
                $capturedModel = $requestBody['model'] ?? null;

                return ['body' => $requestBody, 'injected' => false, 'hash' => null];
            },
        );

        $gateway = new MessagesGateway(
            $config,
            $modelResolver,
            $this->createMock(ModelRepository::class),
            $this->createMock(VisionModelResolver::class),
            $keyResolver,
            $rateLimitService,
            new PremiumFeatureGate(new BillingService('', '')),
            $passthrough,
            $toolCatalog,
            $this->createMock(GatewayToolLoop::class),
            new WebFetchPolicy(),
            $visionPolicy,
            $contextInjector,
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
            [],
            $agentConfig,
            $runtimeResolver,
        );

        $request = Request::create('/v1/messages', 'POST', [], [], [], [], (string) json_encode([
            'model' => 'project-chat-y',
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]));
        $request->headers->set(DesktopTurnOptions::HEADER_AGENT_ID, '12');
        $request->headers->set(DesktopTurnOptions::HEADER_RAG_GROUP_KEY, 'DESKTOP:personal');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getRateLimitLevel')->willReturn('PRO');

        $result = $gateway->prepare($request, $user);

        $this->assertTrue($result['ok']);
        $this->assertSame(42, $result['resolved']['model_id']);
        $this->assertSame('project-chat-y', $result['resolved']['providerModelId']);
        $this->assertSame('project-chat-y', $result['request_body']['model']);
        $this->assertSame('project-chat-y', $capturedModel);
        $this->assertInstanceOf(RuntimeProfile::class, $capturedProfile);
        $this->assertSame(12, $capturedProfile->agentId);
        $this->assertSame(999, $capturedProfile->modelIds['CHAT']);
    }
}
