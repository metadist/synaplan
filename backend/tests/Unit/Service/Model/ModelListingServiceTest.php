<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Model;

use App\AI\Credential\ChatReadinessService;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\Model\ModelListingService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;

final class ModelListingServiceTest extends TestCase
{
    public function testUnavailableModelsAreHiddenUnlessAskedFor(): void
    {
        $service = $this->listing([$this->model(1, 'openai', 'chat'), $this->model(2, 'groq', 'chat')], unavailable: [2]);

        $visible = $service->forUser($this->user(), includeUnavailable: false, applyGroupPolicy: false);
        $all = $service->forUser($this->user(), includeUnavailable: true, applyGroupPolicy: false);

        self::assertSame([1], array_column($visible['models']['CHAT'], 'id'));
        self::assertSame([1, 2], array_column($all['models']['CHAT'], 'id'));
        self::assertFalse($all['models']['CHAT'][1]['available']);
    }

    public function testRerankAndHiddenFreeModelsNeverReachAPicker(): void
    {
        $free = $this->model(3, 'openai', 'chat')->setPriceIn(0.0)->setPriceOut(0.0);
        $service = $this->listing([$this->model(1, 'jina', 'rerank'), $free, $this->model(4, 'openai', 'chat')]);

        $payload = $service->forUser($this->user(), includeUnavailable: true, applyGroupPolicy: false);

        foreach ($payload['models'] as $rows) {
            self::assertNotContains(1, array_column($rows, 'id'));
            self::assertNotContains(3, array_column($rows, 'id'));
        }
        self::assertSame([4], array_column($payload['models']['CHAT'], 'id'));
    }

    public function testALockedDefaultStaysVisibleBehindTheAllowList(): void
    {
        $policy = $this->createStub(GroupPolicyService::class);
        $policy->method('rowsAreRestricted')->willReturn(true);
        $policy->method('filterModelsByAllowList')->willReturn([]);
        $resolver = $this->createStub(LayeredConfigResolver::class);
        $resolver->method('isLocked')->willReturnCallback(static fn (string $group, string $capability): bool => 'CHAT' === $capability);
        $resolver->method('modelAllowListGroupIds')->willReturn([]);
        $defaults = $this->createStub(ModelConfigService::class);
        $defaults->method('reportedDefault')->willReturn(['id' => 7, 'source' => 'group', 'locked' => true]);

        $service = $this->listing([$this->model(7, 'openai', 'chat')], modelConfig: $defaults, policy: $policy, resolver: $resolver);
        $payload = $service->forUser($this->user(), includeUnavailable: false, applyGroupPolicy: true);

        self::assertSame([7], array_column($payload['models']['CHAT'], 'id'));
        self::assertSame([], $payload['models']['SORT']);
        self::assertContains('CHAT', $payload['restricted']);
        self::assertSame(['names' => [], 'combined' => false], $payload['groupLimits']);
    }

    /**
     * @param list<Model> $models
     * @param list<int>   $unavailable model ids whose provider has no key
     */
    private function listing(
        array $models,
        array $unavailable = [],
        ?ModelConfigService $modelConfig = null,
        ?GroupPolicyService $policy = null,
        ?LayeredConfigResolver $resolver = null,
    ): ModelListingService {
        $repository = $this->createStub(ModelRepository::class);
        $repository->method('findBy')->willReturn($models);

        $byProviderId = [];
        foreach ($models as $model) {
            $byProviderId[$model->getProviderId()] = !in_array($model->getId(), $unavailable, true);
        }
        $readiness = $this->createStub(ChatReadinessService::class);
        $readiness->method('providerAvailability')->willReturn([]);
        $readiness->method('modelAvailability')->willReturnCallback(
            static fn (string $service, string $providerId): array => ($byProviderId[$providerId] ?? true)
                ? ['available' => true, 'reason' => null]
                : ['available' => false, 'reason' => 'no_key'],
        );

        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn([]);

        return new ModelListingService(
            $repository,
            $readiness,
            $registry,
            $modelConfig ?? $this->createStub(ModelConfigService::class),
            null,
            $policy,
            $resolver,
        );
    }

    private function model(int $id, string $service, string $tag): Model
    {
        $model = (new Model())
            ->setService($service)
            ->setProviderId($service.'-'.$id)
            ->setTag($tag)
            ->setName('Model '.$id)
            ->setPriceIn(1.0)
            ->setPriceOut(1.0);
        (new \ReflectionProperty(Model::class, 'id'))->setValue($model, $id);

        return $model;
    }

    private function user(): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 7);

        return $user;
    }
}
