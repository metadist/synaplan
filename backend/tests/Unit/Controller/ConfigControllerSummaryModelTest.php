<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Credential\ChatReadinessService;
use App\AI\Service\AiProviderDisclosure;
use App\AI\Service\ProviderRegistry;
use App\Controller\ConfigController;
use App\Entity\Config;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\Auth\DemoLoginHint;
use App\Service\BillingService;
use App\Service\Branding\BrandingService;
use App\Service\Capability\CapabilityService;
use App\Service\Client\ClientContextResolver;
use App\Service\Client\MobileVersionService;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\GuestChatConfig;
use App\Service\Infrastructure\RedisService;
use App\Service\LocalAi\LocalAiDownloadStatusService;
use App\Service\MailerConfig;
use App\Service\MarketingNews\MarketingNewsConfig;
use App\Service\ModelConfigService;
use App\Service\Plugin\PluginManager;
use App\Service\RegistrationConfig;
use App\Service\Search\BraveSearchService;
use App\Service\Setup\SetupStateService;
use App\Service\UsageTaximeterConfig;
use App\Service\UserMemoryService;
use App\Service\WebSpeechConfig;
use App\Service\WhisperService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Coverage for the platform summary model endpoints: the SUMMARIZE slot
 * (DEFAULTMODEL.SUMMARIZE) drives the rolling conversation summary and was
 * previously only reachable through the seeder or direct SQL.
 *
 * Unlike the planner selection this row is global (ownerId 0) and admin-gated:
 * the summary runs inside the pipeline for every user's chat.
 */
final class ConfigControllerSummaryModelTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ConfigRepository&MockObject $configRepository;
    private ModelRepository&MockObject $modelRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private ConfigController $controller;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);

        $this->controller = new ConfigController(
            $this->em,
            $this->configRepository,
            $this->modelRepository,
            $this->createStub(ProviderRegistry::class),
            $this->createStub(BraveSearchService::class),
            $this->createStub(WhisperService::class),
            $this->createStub(PluginManager::class),
            $this->createStub(BillingService::class),
            $this->createStub(UserMemoryService::class),
            $this->createStub(EmbeddingModelChangeGuard::class),
            $this->createStub(EmbeddingMetadataService::class),
            $this->modelConfigService,
            new RedisService('', 'test', new NullLogger()),
            new ClientContextResolver(),
            $this->createStub(BrandingService::class),
            $this->createStub(MobileVersionService::class),
            $this->createStub(MarketingNewsConfig::class),
            $this->createStub(UsageTaximeterConfig::class),
            $this->createStub(RegistrationConfig::class),
            $this->createStub(GuestChatConfig::class),
            $this->createStub(WebSpeechConfig::class),
            $this->createStub(\App\Service\SavedTask\SavedTaskConfig::class),
            $this->createStub(\App\Service\Desktop\DesktopAgentConfig::class),
            $this->createStub(ChatReadinessService::class),
            new DemoLoginHint(
                $this->createStub(UserRepository::class),
                $this->createStub(UserPasswordHasherInterface::class),
                'test',
            ),
            $this->createStub(SetupStateService::class),
            $this->createStub(AiProviderDisclosure::class),
            $this->createStub(LocalAiDownloadStatusService::class),
            new MailerConfig(),
            new CapabilityService(),
            'http://qdrant.example',
        );

        $this->grantAdmin(true);
    }

    private function grantAdmin(bool $granted): void
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        $container = new Container();
        $container->set('security.authorization_checker', $checker);
        $this->controller->setContainer($container);
    }

    public function testGetRejectsUnauthenticatedRequest(): void
    {
        $response = $this->controller->getSummaryModel(null);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testGetRejectsNonAdmin(): void
    {
        $this->grantAdmin(false);

        $response = $this->controller->getSummaryModel($this->makeUser(7));

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testGetReturnsGlobalSelectionAndSortingFallback(): void
    {
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria): ?Config {
                if (0 === ($criteria['ownerId'] ?? null) && 'SUMMARIZE' === ($criteria['setting'] ?? null)) {
                    return $this->makeConfig('300');
                }

                return null;
            });

        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeModel($id, active: true));
        $this->modelConfigService->method('getDefaultModel')->willReturn(76);

        $response = $this->controller->getSummaryModel($this->makeUser(7));
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(300, $payload['modelId']);
        $this->assertSame(76, $payload['fallbackModelId']);
    }

    public function testGetIgnoresInactiveSelection(): void
    {
        $this->configRepository->method('findOneBy')->willReturn($this->makeConfig('300'));
        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeModel($id, active: false));
        $this->modelConfigService->method('getDefaultModel')->willReturn(76);

        $payload = $this->decode($this->controller->getSummaryModel($this->makeUser(7)));

        $this->assertNull($payload['modelId']);
    }

    public function testSaveRejectsNonAdmin(): void
    {
        $this->grantAdmin(false);
        $this->em->expects($this->never())->method('persist');

        $response = $this->controller->saveSummaryModel(
            $this->makeRequest(['modelId' => 300]),
            $this->makeUser(7),
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testSaveRejectsPayloadWithoutModelIdKey(): void
    {
        $response = $this->controller->saveSummaryModel($this->makeRequest(['foo' => 1]), $this->makeUser(7));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSavePersistsTheSelectionAsAGlobalRow(): void
    {
        $this->configRepository->method('findOneBy')->willReturn(null);
        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeModel($id, active: true));

        $persisted = [];
        $this->em
            ->method('persist')
            ->willReturnCallback(function (Config $config) use (&$persisted): void {
                $persisted[] = [$config->getGroup(), $config->getSetting(), $config->getValue(), $config->getOwnerId()];
            });
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->saveSummaryModel(
            $this->makeRequest(['modelId' => 300]),
            $this->makeUser(7),
        );
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(300, $payload['modelId']);
        $this->assertSame([['DEFAULTMODEL', 'SUMMARIZE', '300', 0]], $persisted);
    }

    public function testSaveClearsTheSelectionWhenModelIdIsNull(): void
    {
        $existing = $this->makeConfig('300');
        $this->configRepository->method('findOneBy')->willReturn($existing);

        $this->em->expects($this->once())->method('remove')->with($existing);
        $this->em->expects($this->once())->method('flush');

        $payload = $this->decode($this->controller->saveSummaryModel(
            $this->makeRequest(['modelId' => null]),
            $this->makeUser(7),
        ));

        $this->assertNull($payload['modelId']);
    }

    public function testSaveRejectsInactiveModel(): void
    {
        $this->configRepository->method('findOneBy')->willReturn(null);
        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeModel($id, active: false));

        $this->em->expects($this->never())->method('persist');

        $response = $this->controller->saveSummaryModel(
            $this->makeRequest(['modelId' => 300]),
            $this->makeUser(7),
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeRequest(array $payload): Request
    {
        return Request::create(
            '/api/v1/config/routing/summary-model',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionClass(User::class);
        $reflection->getProperty('id')->setValue($user, $id);

        return $user;
    }

    private function makeConfig(string $value): Config
    {
        $config = new Config();
        $config->setOwnerId(0);
        $config->setGroup('DEFAULTMODEL');
        $config->setSetting('SUMMARIZE');
        $config->setValue($value);

        return $config;
    }

    private function makeModel(int $id, bool $active): Model
    {
        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getActive')->willReturn($active ? 1 : 0);

        return $model;
    }
}
