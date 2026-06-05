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
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\ModelConfigService;
use App\Service\Plugin\PluginManager;
use App\Service\Search\BraveSearchService;
use App\Service\UserMemoryService;
use App\Service\WhisperService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression coverage for the #891 inconsistency: the AI Models Config
 * page (`AIModelsConfiguration.vue::saveConfiguration`) sends EVERY
 * non-null capability on every save — including the unchanged VECTORIZE
 * id seeded from `getDefaultModels()`. The premium gate on VECTORIZE
 * must therefore only fire when the value actually changes, otherwise
 * a NEW user trying to change CHAT gets a 403 and watches the whole
 * save silently fail.
 */
final class ConfigControllerSaveDefaultModelsTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ConfigRepository&MockObject $configRepository;
    private ModelRepository&MockObject $modelRepository;
    private EmbeddingModelChangeGuard&MockObject $embeddingChangeGuard;
    private EmbeddingMetadataService&MockObject $embeddingMetadata;
    private ConfigController $controller;

    protected function setUp(): void
    {
        // Only the dependencies the saveDefaultModels path actually
        // touches are full mocks (we verify behaviour on them).
        // Everything else is a passive stub — they exist so the
        // constructor wires up, nothing more.
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->embeddingChangeGuard = $this->createMock(EmbeddingModelChangeGuard::class);
        $this->embeddingMetadata = $this->createMock(EmbeddingMetadataService::class);

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
            $this->embeddingChangeGuard,
            $this->embeddingMetadata,
            $this->createStub(ModelConfigService::class),
            'http://qdrant.example',
        );

        // Bare container is enough for AbstractController::json() to
        // fall back to plain json_encode (no serializer service binding).
        $this->controller->setContainer(new Container());
    }

    public function testRejectsUnauthenticatedRequest(): void
    {
        $response = $this->controller->saveDefaultModels(
            $this->makeRequest(['defaults' => ['CHAT' => 5]]),
            null,
        );

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRejectsPayloadWithoutDefaults(): void
    {
        $response = $this->controller->saveDefaultModels(
            $this->makeRequest(['defaults' => 'not-an-array']),
            $this->makeUser(7),
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * The real-world #891 scenario: a NEW (anonymous) user picks a
     * different CHAT model on `/config/ai-models`. The frontend echoes
     * back the unchanged VECTORIZE seed (model 187) together with the
     * new CHAT id. Without the fix, the backend invokes the premium
     * gate purely because VECTORIZE is "set" in the payload, returns
     * 403, and discards the entire save — CHAT included.
     */
    public function testUnchangedVectorizeEchoDoesNotTriggerPremiumGate(): void
    {
        $existingVectorize = $this->makeConfig('VECTORIZE', '187');

        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($existingVectorize): ?Config {
                if ('VECTORIZE' === ($criteria['setting'] ?? null)
                    && 0 === ($criteria['ownerId'] ?? null)) {
                    return $existingVectorize;
                }

                return null;
            });

        $this->modelRepository
            ->method('find')
            ->willReturnCallback(fn (int $id) => $this->makeActiveModel($id));

        // CRITICAL: must NEVER be called for an unchanged VECTORIZE
        // echo, even if the caller is a NEW/anonymous user.
        $this->embeddingChangeGuard->expects($this->never())->method('assertCanChange');

        // Cache invalidation is also gated on the actual change — no
        // need to thrash the cache for a CHAT-only save.
        $this->embeddingMetadata->expects($this->never())->method('invalidate');

        // CHAT must persist; VECTORIZE write is silently skipped (the
        // value didn't change, so there's nothing to write).
        $persisted = [];
        $this->em
            ->method('persist')
            ->willReturnCallback(function (Config $config) use (&$persisted): void {
                $persisted[] = $config->getSetting();
            });

        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->saveDefaultModels(
            $this->makeRequest([
                'defaults' => [
                    'CHAT' => 42,
                    'VECTORIZE' => 187,
                ],
            ]),
            $this->makeUser(7),
        );

        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertContains('CHAT', $persisted);
        $this->assertNotContains('VECTORIZE', $persisted);
    }

    public function testChangedVectorizeFromNonPremiumUserReturns403(): void
    {
        $existingVectorize = $this->makeConfig('VECTORIZE', '187');

        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(fn (array $criteria) => 'VECTORIZE' === ($criteria['setting'] ?? null)
                && 0 === ($criteria['ownerId'] ?? null) ? $existingVectorize : null);

        $this->embeddingChangeGuard
            ->expects($this->once())
            ->method('assertCanChange')
            ->willThrowException(new PremiumRequiredException(
                'NEW',
                'Switching the embedding model requires a paid plan.',
            ));

        // 403 short-circuits — nothing should be persisted or flushed.
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->embeddingMetadata->expects($this->never())->method('invalidate');

        $response = $this->controller->saveDefaultModels(
            $this->makeRequest([
                'defaults' => [
                    'CHAT' => 42,
                    'VECTORIZE' => 188,
                ],
            ]),
            $this->makeUser(7),
        );

        $payload = $this->decode($response);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame('requires_premium', $payload['error']);
        $this->assertSame('VECTORIZE', $payload['capability']);
        $this->assertSame('NEW', $payload['currentLevel']);
    }

    public function testChangedVectorizeFromEligibleUserPersistsAndInvalidatesCache(): void
    {
        $existingVectorize = $this->makeConfig('VECTORIZE', '187');

        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(fn (array $criteria) => 'VECTORIZE' === ($criteria['setting'] ?? null)
                && 0 === ($criteria['ownerId'] ?? null) ? $existingVectorize : null);

        $this->modelRepository
            ->method('find')
            ->willReturnCallback(fn (int $id) => $this->makeActiveModel($id));

        $this->embeddingChangeGuard->expects($this->once())->method('assertCanChange');

        $this->embeddingMetadata->expects($this->once())->method('invalidate');

        $persisted = [];
        $this->em
            ->method('persist')
            ->willReturnCallback(function (Config $config) use (&$persisted): void {
                $persisted[] = $config->getSetting();
            });

        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->saveDefaultModels(
            $this->makeRequest([
                'defaults' => [
                    'VECTORIZE' => 188,
                ],
            ]),
            $this->makeUser(7),
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertContains('VECTORIZE', $persisted);
    }

    /**
     * Edge case: no existing VECTORIZE row at all (`resolveCurrentVectorizeModelId`
     * returns 0). Any incoming non-zero id MUST count as a change and
     * trip the premium gate — otherwise a NEW user on a brand-new
     * install could bypass the gate on the very first save.
     */
    public function testInitialVectorizeBindingFiresPremiumGate(): void
    {
        // No global VECTORIZE row exists yet.
        $this->configRepository->method('findOneBy')->willReturn(null);

        $this->embeddingChangeGuard
            ->expects($this->once())
            ->method('assertCanChange')
            ->willThrowException(new PremiumRequiredException(
                'NEW',
                'Switching the embedding model requires a paid plan.',
            ));

        $response = $this->controller->saveDefaultModels(
            $this->makeRequest([
                'defaults' => [
                    'VECTORIZE' => 188,
                ],
            ]),
            $this->makeUser(7),
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeRequest(array $payload): Request
    {
        return Request::create(
            '/api/v1/config/default-models',
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

    private function makeConfig(string $setting, string $value): Config
    {
        $config = new Config();
        $config->setOwnerId(0);
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
}
