<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\WebSearchTool;
use App\Controller\MessagesGatewayController;
use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\BillingService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The settings endpoint behind Channels → AI Agents. It writes one BCONFIG row
 * per submitted setting, so an unnoticed type slip would silently persist
 * garbage that only surfaces on the next gateway request.
 */
final class MessagesGatewayControllerFlagsTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private WebSearchTool&MockObject $webSearchTool;
    private AnalyzeImageTool&MockObject $analyzeImageTool;
    private MessagesGatewayController $controller;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->webSearchTool = $this->createMock(WebSearchTool::class);
        $this->analyzeImageTool = $this->createMock(AnalyzeImageTool::class);

        $this->controller = new MessagesGatewayController(
            $this->createStub(MessagesGatewayConfig::class),
            $this->createStub(UserProviderKeyResolver::class),
            $this->createStub(ProviderKeyStore::class),
            $this->configRepository,
            $this->createStub(RateLimitService::class),
            new PremiumFeatureGate(new BillingService('', '')),
            $this->webSearchTool,
            $this->analyzeImageTool,
            $this->createStub(GatewayToolCatalog::class),
            $this->createStub(McpServerConfigRepository::class),
            new NullLogger(),
        );

        $this->grantAdmin(true);
    }

    public function testRejectsNonAdmin(): void
    {
        $this->grantAdmin(false);

        $response = $this->controller->putFlags($this->request(['enabled' => true]), $this->makeUser());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testWritesEveryValueKind(): void
    {
        $this->webSearchTool->method('isAvailable')->willReturn(true);
        $written = [];
        $this->configRepository->method('setValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting, string $value) use (&$written): Config {
                $this->assertSame(0, $ownerId);
                $this->assertSame(MessagesGatewayConfig::CONFIG_GROUP, $group);
                $written[$setting] = $value;

                return new Config();
            });

        $response = $this->controller->putFlags($this->request([
            'mcp_tools_enabled' => true,
            'session_summary_enabled' => false,
            'mcp_max_iterations' => 12,
            'vision_max_images' => 4,
            'vision_mode' => 'OFF',
            'vision_image_detail' => 'low',
            'web_search_mode' => 'synaplan',
        ]), $this->makeUser());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame([
            'MCP_TOOLS_ENABLED' => '1',
            'SESSION_SUMMARY_ENABLED' => '0',
            'WEB_SEARCH_MODE' => 'synaplan',
            'VISION_MODE' => 'off',
            'VISION_IMAGE_DETAIL' => 'low',
            'MCP_MAX_ITERATIONS' => '12',
            'VISION_MAX_IMAGES' => '4',
        ], $written);

        $payload = $this->decode($response);
        $this->assertTrue($payload['success']);
        $this->assertSame(12, $payload['updated']['mcp_max_iterations']);
        $this->assertSame('off', $payload['updated']['vision_mode']);
        $this->assertFalse($payload['updated']['session_summary_enabled']);
    }

    public function testOmittedSettingsAreLeftAlone(): void
    {
        $this->configRepository->expects($this->once())->method('setValue');

        $response = $this->controller->putFlags($this->request(['enabled' => true]), $this->makeUser());

        $this->assertSame(['enabled' => true], $this->decode($response)['updated']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'unknown vision mode' => [['vision_mode' => 'sometimes'], 'vision_mode must be one of'];
        yield 'unknown image detail' => [['vision_image_detail' => 'ultra'], 'vision_image_detail must be one of'];
        yield 'unknown web search mode' => [['web_search_mode' => 'maybe'], 'web_search_mode must be one of'];
        yield 'iterations out of range' => [['mcp_max_iterations' => 999], 'mcp_max_iterations must be an integer'];
        yield 'negative image cap' => [['vision_max_images' => -1], 'vision_max_images must be an integer'];
        yield 'non-numeric image cap' => [['vision_max_images' => 'lots'], 'vision_max_images must be an integer'];
        yield 'non-boolean flag' => [['enabled' => 'perhaps'], 'enabled must be a boolean'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValues')]
    public function testInvalidValuesAreRejectedWithoutWriting(array $body, string $expectedError): void
    {
        $this->configRepository->expects($this->never())->method('setValue');

        $response = $this->controller->putFlags($this->request($body), $this->makeUser());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString($expectedError, $this->decode($response)['error']);
    }

    public function testSynaplanModesNeedTheCapabilityToBeConfigured(): void
    {
        $this->webSearchTool->method('isAvailable')->willReturn(false);
        $this->analyzeImageTool->method('isAvailable')->willReturn(false);
        $this->configRepository->expects($this->never())->method('setValue');

        $search = $this->controller->putFlags(
            $this->request(['web_search_mode' => 'synaplan']),
            $this->makeUser(),
        );
        $vision = $this->controller->putFlags(
            $this->request(['vision_mode' => 'synaplan']),
            $this->makeUser(),
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $search->getStatusCode());
        $this->assertStringContainsString(
            'requires a configured web search provider',
            $this->decode($search)['error'],
        );
        $this->assertSame(Response::HTTP_BAD_REQUEST, $vision->getStatusCode());
        $this->assertStringContainsString(
            'requires a configured Synaplan vision',
            $this->decode($vision)['error'],
        );
    }

    public function testInvalidJsonIsRejected(): void
    {
        $response = $this->controller->putFlags(
            new Request(server: ['CONTENT_TYPE' => 'application/json'], content: 'not json'),
            $this->makeUser(),
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    private function grantAdmin(bool $granted): void
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        $container = new Container();
        $container->set('security.authorization_checker', $checker);
        $this->controller->setContainer($container);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        return new Request(
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function makeUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
