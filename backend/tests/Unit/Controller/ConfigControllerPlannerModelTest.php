<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\ProviderRegistry;
use App\Controller\ConfigController;
use App\Entity\Config;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\BillingService;
use App\Service\Branding\BrandingService;
use App\Service\Client\ClientContextResolver;
use App\Service\Client\MobileVersionService;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Infrastructure\RedisService;
use App\Service\MarketingNews\MarketingNewsConfig;
use App\Service\ModelConfigService;
use App\Service\Plugin\PluginManager;
use App\Service\Search\BraveSearchService;
use App\Service\UsageTaximeterConfig;
use App\Service\UserMemoryService;
use App\Service\WhisperService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coverage for the planner model selection endpoints (#1143): the PLAN
 * slot (DEFAULTMODEL.PLAN) was previously only configurable via direct SQL.
 */
final class ConfigControllerPlannerModelTest extends TestCase
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
            'http://qdrant.example',
        );

        $this->controller->setContainer(new Container());
    }

    public function testGetRejectsUnauthenticatedRequest(): void
    {
        $response = $this->controller->getPlannerModel(null);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testGetReturnsActiveUserOverrideAndSortingFallback(): void
    {
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria): ?Config {
                if (7 === ($criteria['ownerId'] ?? null) && 'PLAN' === ($criteria['setting'] ?? null)) {
                    return $this->makeConfig(7, 'PLAN', '12');
                }

                return null;
            });

        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeActiveModel($id));
        $this->modelConfigService->method('getDefaultModel')->willReturn(7);

        $response = $this->controller->getPlannerModel($this->makeUser(7));
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(12, $payload['modelId']);
        $this->assertSame(7, $payload['fallbackModelId']);
    }

    public function testGetReturnsNullWhenNoOverrideConfigured(): void
    {
        $this->configRepository->method('findOneBy')->willReturn(null);
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $response = $this->controller->getPlannerModel($this->makeUser(7));
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNull($payload['modelId']);
        $this->assertNull($payload['fallbackModelId']);
    }

    public function testGetIgnoresInactiveOverrideModel(): void
    {
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria): ?Config {
                if (7 === ($criteria['ownerId'] ?? null) && 'PLAN' === ($criteria['setting'] ?? null)) {
                    return $this->makeConfig(7, 'PLAN', '99');
                }

                return null;
            });

        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeInactiveModel($id));
        $this->modelConfigService->method('getDefaultModel')->willReturn(7);

        $response = $this->controller->getPlannerModel($this->makeUser(7));
        $payload = $this->decode($response);

        $this->assertNull($payload['modelId']);
    }

    public function testSaveRejectsUnauthenticatedRequest(): void
    {
        $response = $this->controller->savePlannerModel($this->makeRequest(['modelId' => 12]), null);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSaveRejectsPayloadWithoutModelIdKey(): void
    {
        $response = $this->controller->savePlannerModel($this->makeRequest(['foo' => 1]), $this->makeUser(7));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testSaveClearsExistingOverrideWhenModelIdIsNull(): void
    {
        $existing = $this->makeConfig(7, 'PLAN', '12');
        $this->configRepository->method('findOneBy')->willReturn($existing);

        $this->em->expects($this->once())->method('remove')->with($existing);
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->savePlannerModel(
            $this->makeRequest(['modelId' => null]),
            $this->makeUser(7),
        );
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNull($payload['modelId']);
    }

    public function testSaveWithNullAndNoExistingRowIsNoOp(): void
    {
        $this->configRepository->method('findOneBy')->willReturn(null);

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $response = $this->controller->savePlannerModel(
            $this->makeRequest(['modelId' => null]),
            $this->makeUser(7),
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSavePersistsValidActiveModel(): void
    {
        $this->configRepository->method('findOneBy')->willReturn(null);
        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeActiveModel($id));

        $persisted = [];
        $this->em
            ->method('persist')
            ->willReturnCallback(function (Config $config) use (&$persisted): void {
                $persisted[] = [$config->getSetting(), $config->getValue(), $config->getOwnerId()];
            });
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->savePlannerModel(
            $this->makeRequest(['modelId' => 12]),
            $this->makeUser(7),
        );
        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(12, $payload['modelId']);
        $this->assertSame([['PLAN', '12', 7]], $persisted);
    }

    public function testSaveRejectsInactiveModel(): void
    {
        $this->configRepository->method('findOneBy')->willReturn(null);
        $this->modelRepository->method('find')->willReturnCallback(fn (int $id): Model => $this->makeInactiveModel($id));

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $response = $this->controller->savePlannerModel(
            $this->makeRequest(['modelId' => 12]),
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
            '/api/v1/config/routing/planner-model',
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
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($user, $id);

        return $user;
    }

    private function makeConfig(int $ownerId, string $setting, string $value): Config
    {
        $config = new Config();
        $config->setOwnerId($ownerId);
        $config->setGroup('DEFAULTMODEL');
        $config->setSetting($setting);
        $config->setValue($value);

        return $config;
    }

    private function makeActiveModel(int $id): Model
    {
        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getActive')->willReturn(1);

        return $model;
    }

    private function makeInactiveModel(int $id): Model
    {
        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getActive')->willReturn(0);

        return $model;
    }
}
