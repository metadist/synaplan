<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\MessagesModelResolver;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MessagesModelResolverTest extends TestCase
{
    private ModelRepository&MockObject $modelRepository;
    private MessagesGatewayConfig&MockObject $config;
    private MessagesModelResolver $resolver;

    protected function setUp(): void
    {
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->config = $this->createMock(MessagesGatewayConfig::class);
        $this->config->method('modelAliases')->willReturn([]);
        $this->resolver = new MessagesModelResolver(
            $this->modelRepository,
            $this->config,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testResolveReturnsNullForEmpty(): void
    {
        $this->assertNull($this->resolver->resolve(null));
        $this->assertNull($this->resolver->resolve(''));
        $this->assertNull($this->resolver->resolve('   '));
    }

    public function testResolveByProviderId(): void
    {
        $model = $this->makeModel(42, 'Anthropic', 'claude-sonnet-4-6', 'Claude Sonnet 4.6');
        $this->expectLookupSequence([$model, null]);

        $resolved = $this->resolver->resolve('claude-sonnet-4-6');

        $this->assertNotNull($resolved);
        $this->assertSame('anthropic', $resolved['provider']);
        $this->assertSame('claude-sonnet-4-6', $resolved['providerModelId']);
        $this->assertSame(42, $resolved['model_id']);
        $this->assertNull($resolved['aliased_from']);
    }

    public function testResolveAppliesAliasMap(): void
    {
        $this->config = $this->createMock(MessagesGatewayConfig::class);
        $this->config->method('modelAliases')->willReturn([
            'claude-sonnet-4-6' => 'claude-sonnet-4-5-20250929',
        ]);
        $this->resolver = new MessagesModelResolver(
            $this->modelRepository,
            $this->config,
            $this->createMock(LoggerInterface::class),
        );

        $model = $this->makeModel(7, 'Anthropic', 'claude-sonnet-4-5-20250929', 'Sonnet');
        $this->expectLookupSequence([$model, null]);

        $resolved = $this->resolver->resolve('claude-sonnet-4-6');

        $this->assertNotNull($resolved);
        $this->assertSame('claude-sonnet-4-5-20250929', $resolved['providerModelId']);
        $this->assertSame('claude-sonnet-4-6', $resolved['aliased_from']);
    }

    public function testResolveStripsDatedSuffix(): void
    {
        // First lookup (dated id) misses both providerId and name;
        // second lookup (stripped) hits providerId.
        $model = $this->makeModel(9, 'Anthropic', 'claude-haiku-4-5', 'Haiku');
        $this->expectLookupSequence([null, null, $model, null]);

        $resolved = $this->resolver->resolve('claude-haiku-4-5-20251001');

        $this->assertNotNull($resolved);
        $this->assertSame('claude-haiku-4-5', $resolved['providerModelId']);
    }

    public function testResolveFailsClosedWhenUnknown(): void
    {
        $this->expectLookupSequence([null, null, null, null]);

        $this->assertNull($this->resolver->resolve('totally-unknown-model-xyz'));
    }

    private function makeModel(int $id, string $service, string $providerId, string $name): Model&MockObject
    {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($name);

        return $model;
    }

    /**
     * Each findActiveModel issues two QueryBuilder chains (providerId, then name).
     * Provide results in that order for every findActiveModel call.
     *
     * @param list<Model|null> $results
     */
    private function expectLookupSequence(array $results): void
    {
        $qbQueue = [];
        foreach ($results as $result) {
            $qbQueue[] = $this->makeQueryBuilderReturning($result);
        }

        $this->modelRepository->method('createQueryBuilder')
            ->willReturnCallback(function () use (&$qbQueue) {
                if ([] === $qbQueue) {
                    return $this->makeQueryBuilderReturning(null);
                }

                return array_shift($qbQueue);
            });
    }

    private function makeQueryBuilderReturning(?Model $result): QueryBuilder&MockObject
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($result);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }
}
