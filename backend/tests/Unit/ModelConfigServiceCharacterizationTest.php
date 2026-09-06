<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\OllamaModelInventory;
use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\IamConfig;
use App\Service\ModelConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

/**
 * IAM40/IAM41: with the group-policy flag off, getDefaultModel() matches the
 * pre-resolver owner loop (user then global).
 */
final class ModelConfigServiceCharacterizationTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private ModelRepository&MockObject $modelRepository;
    /** @var array<int, string> ownerId => value */
    private array $bindings = [];

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->bindings = [];

        $this->configRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?Config {
                if (($criteria['group'] ?? '') !== 'DEFAULTMODEL') {
                    return null;
                }
                $owner = (int) ($criteria['ownerId'] ?? -1);
                if (!isset($this->bindings[$owner])) {
                    return null;
                }
                $row = new Config();
                $row->setOwnerId($owner);
                $row->setGroup('DEFAULTMODEL');
                $row->setSetting((string) ($criteria['setting'] ?? 'CHAT'));
                $row->setValue($this->bindings[$owner]);

                return $row;
            }
        );
        $this->configRepository->method('getValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting): ?string {
                if ('DEFAULTMODEL' !== $group) {
                    return null;
                }

                return $this->bindings[$ownerId] ?? null;
            }
        );
        $this->configRepository->method('findByOwnerGroupAndSetting')->willReturnCallback(
            function (int $ownerId, string $group, string $setting): ?Config {
                if ('DEFAULTMODEL' !== $group || !isset($this->bindings[$ownerId])) {
                    return null;
                }
                $row = new Config();
                $row->setOwnerId($ownerId);
                $row->setGroup($group);
                $row->setSetting($setting);
                $row->setValue($this->bindings[$ownerId]);

                return $row;
            }
        );
    }

    #[DataProvider('matrix')]
    public function testFlagOffMatchesOwnerLoop(?int $userId, array $bindingPairs, int $usableId, int $expected): void
    {
        $this->bindings = [];
        foreach ($bindingPairs as [$ownerId, $value]) {
            $this->bindings[(int) $ownerId] = (string) $value;
        }
        $legacy = $this->makeService(null);
        $layered = $this->makeService($this->resolverOff());
        $this->modelRepository->method('find')->willReturnCallback(
            function (int $id) use ($usableId): Model {
                $model = $this->createMock(Model::class);
                $model->method('getId')->willReturn($id);
                $model->method('getActive')->willReturn(1);
                $model->method('getService')->willReturn($id === $usableId ? 'groq' : 'missing');

                return $model;
            }
        );

        self::assertSame($expected, $legacy->getDefaultModel('CHAT', $userId));
        self::assertSame($expected, $layered->getDefaultModel('CHAT', $userId));
    }

    /**
     * Bindings are [ownerId, value] pairs so PHPUnit does not reindex keys.
     *
     * @return iterable<string, array{0: ?int, 1: list<array{0: int, 1: string}>, 2: int, 3: int}>
     */
    public static function matrix(): iterable
    {
        yield 'user usable' => [5, [[5, '11'], [0, '22']], 11, 11];
        yield 'user unusable falls to global' => [5, [[5, '11'], [0, '22']], 22, 22];
        yield 'global only' => [5, [[0, '22']], 22, 22];
        yield 'anonymous global' => [null, [[0, '22']], 22, 22];
    }

    private function resolverOff(): LayeredConfigResolver
    {
        $iam = $this->createMock(IamConfig::class);
        $iam->method('isGroupPoliciesEnabled')->willReturn(false);

        return new LayeredConfigResolver(
            $this->configRepository,
            $this->createMock(GroupConfigRepository::class),
            $this->createMock(GroupMemberRepository::class),
            $iam,
        );
    }

    private function makeService(?LayeredConfigResolver $resolver): ModelConfigService
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $providers = $this->createMock(ProviderRegistry::class);
        $groq = $this->createMock(ProviderMetadataInterface::class);
        $groq->method('getName')->willReturn('groq');
        $groq->method('isAvailable')->willReturn(true);
        $providers->method('getUniqueProviders')->willReturn(['groq' => $groq]);
        $health = $this->createMock(ModelHealthRepository::class);
        $health->method('findOfflineModelIds')->willReturn([]);

        return new ModelConfigService(
            $this->configRepository,
            $this->modelRepository,
            $this->createMock(UserRepository::class),
            $cache,
            $providers,
            $this->createMock(OllamaModelInventory::class),
            $health,
            new NullLogger(),
            $resolver,
        );
    }
}
