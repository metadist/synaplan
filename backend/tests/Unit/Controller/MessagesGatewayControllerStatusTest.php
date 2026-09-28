<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\AppChatCredential;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\WebSearchTool;
use App\Controller\MessagesGatewayController;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\BillingService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Coding-client setup copies setup.base_url_hint into ANTHROPIC_BASE_URL.
 * The hint is the APP_URL origin, not the browser page and not a path.
 */
final class MessagesGatewayControllerStatusTest extends TestCase
{
    #[DataProvider('appUrls')]
    public function testStatusBaseUrlHintIsTheAppOrigin(string $appUrl, string $expected): void
    {
        $rateLimit = $this->createStub(RateLimitService::class);
        $rateLimit->method('checkCostBudget')->willReturn([
            'percent' => 0,
            'used_cost' => 0.0,
            'budget' => 0.0,
            'remaining' => 0.0,
            'allowed' => true,
        ]);

        $keys = $this->createStub(UserProviderKeyResolver::class);
        $keys->method('resolve')->willReturn(null);

        $controller = new MessagesGatewayController(
            $this->createStub(MessagesGatewayConfig::class),
            $keys,
            $this->createStub(ProviderKeyStore::class),
            $this->createStub(ConfigRepository::class),
            $rateLimit,
            new PremiumFeatureGate(new BillingService('', '')),
            $this->createStub(WebSearchTool::class),
            $this->createStub(AnalyzeImageTool::class),
            $this->createStub(GatewayToolCatalog::class),
            $this->createStub(McpServerConfigRepository::class),
            new NullLogger(),
            $this->createStub(AppChatCredential::class),
            $appUrl,
        );

        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(false);
        $container = new Container();
        $container->set('security.authorization_checker', $checker);
        $controller->setContainer($container);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $response = $controller->status($user);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body['setup']);
        self::assertSame($expected, $body['setup']['base_url_hint']);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function appUrls(): array
    {
        return [
            ['', ''],
            ['http://localhost:8000', 'http://localhost:8000'],
            ['http://localhost:8000/', 'http://localhost:8000'],
            ['http://localhost:8000/api', 'http://localhost:8000'],
            ['https://example.com:8443/synaplan/', 'https://example.com:8443'],
            ['https://synaplan.example', 'https://synaplan.example'],
        ];
    }
}
