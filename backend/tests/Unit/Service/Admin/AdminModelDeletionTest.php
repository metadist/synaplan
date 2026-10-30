<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\AI\Credential\ChatReadinessService;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\GroupConfig;
use App\Entity\Model;
use App\Plug\PlugHealth;
use App\Plug\Rerank\Adapter\HttpRerankAdapter;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Service\Admin\AdminModelsService;
use App\Service\Admin\DefaultModelMover;
use App\Service\Admin\ModelImportService;
use App\Service\Admin\ModelSqlValidator;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AdminModelDeletionTest extends TestCase
{
    public function testChatDefaultMovesToAnotherModelOfTheSameService(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $otherService = $this->model(2, 'Elsewhere', 'Anthropic');
        $sameService = $this->model(9, 'Next chat', 'Groq');
        $row = $this->config(0, 'CHAT', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $otherService, $sameService], $removed);

        self::assertSame('9', $row->getValue());
        self::assertSame([
            ['setting' => 'CHAT', 'scope' => 'global', 'ownerId' => 0, 'modelId' => 9, 'modelName' => 'Next chat'],
        ], $result['moved']);
        self::assertSame([], $result['cleared']);
        self::assertContains($gone, $removed);
        self::assertNotContains($row, $removed);
    }

    public function testChatDefaultIsClearedWhenNothingElseCanTakeTheJob(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $embedding = $this->model(6, 'Embed', 'Ollama', 'vectorize');
        $row = $this->config(0, 'CHAT', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $embedding], $removed);

        self::assertSame([], $result['moved']);
        self::assertSame([['setting' => 'CHAT', 'scope' => 'global', 'ownerId' => 0]], $result['cleared']);
        self::assertContains($row, $removed);
        self::assertContains($gone, $removed);
    }

    public function testSearchDefaultIsClearedEvenWhenAnotherEmbeddingModelExists(): void
    {
        $gone = $this->model(5, 'Old embed', 'Ollama', 'vectorize');
        $other = $this->model(6, 'Other embed', 'Ollama', 'vectorize');
        $row = $this->config(0, 'VECTORIZE', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $other], $removed);

        self::assertSame('5', $row->getValue());
        self::assertSame([], $result['moved']);
        self::assertSame([['setting' => 'VECTORIZE', 'scope' => 'global', 'ownerId' => 0]], $result['cleared']);
        self::assertContains($row, $removed);
    }

    public function testAModelThatIsNotADefaultIsRemovedWithoutTouchingConfig(): void
    {
        $gone = $this->model(5, 'Spare', 'Groq');
        $removed = [];

        $result = $this->delete($gone, [], [$gone], $removed);

        self::assertSame(['moved' => [], 'cleared' => []], $result);
        self::assertSame([$gone], $removed);
    }

    public function testAnOfflineModelIsNotUsedAsTheReplacement(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $offline = $this->model(8, 'Down', 'Groq');
        $up = $this->model(9, 'Up', 'Anthropic');
        $row = $this->config(0, 'CHAT', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $offline, $up], $removed, offlineIds: [8]);

        self::assertSame(9, $result['moved'][0]['modelId']);
    }

    public function testAForbiddenModelIsNotAssignedToThatPerson(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $blocked = $this->model(8, 'Blocked', 'Groq');
        $row = $this->config(4, 'CHAT', '5');
        $removed = [];
        $policy = $this->createStub(GroupPolicyService::class);
        $policy->method('isModelAllowed')->willReturn(false);

        $result = $this->delete($gone, [$row], [$gone, $blocked], $removed, policy: $policy);

        self::assertSame([], $result['moved']);
        self::assertSame([['setting' => 'CHAT', 'scope' => 'global', 'ownerId' => 4]], $result['cleared']);
    }

    public function testAToolsDefaultMovesToAnotherChatModel(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $next = $this->model(9, 'Next chat', 'Groq');
        $row = $this->config(0, 'TOOLS', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $next], $removed);

        self::assertSame(9, $result['moved'][0]['modelId']);
        self::assertSame('TOOLS', $result['moved'][0]['setting']);
        self::assertSame([], $result['cleared']);
    }

    public function testACatalogKeyBindingIsFound(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $next = $this->model(9, 'Next chat', 'Groq');
        $row = $this->config(0, 'CHAT', 'groq:old:chat');
        $removed = [];
        $policy = $this->createStub(GroupPolicyService::class);
        $policy->method('isModelAllowed')->willReturn(true);
        $policy->method('modelIdFromStored')->willReturnCallback(
            static fn (string $raw): ?int => 'groq:old:chat' === $raw ? 5 : null,
        );

        $result = $this->delete($gone, [$row], [$gone, $next], $removed, policy: $policy);

        self::assertSame('9', $row->getValue());
        self::assertSame('global', $result['moved'][0]['scope']);
    }

    public function testAGroupBindingIsMovedAndNamedAsGroup(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $next = $this->model(9, 'Next chat', 'Groq');
        $group = (new GroupConfig())->setGroupId(7)->setGroup('DEFAULTMODEL')->setSetting('TOOLS')->setValue('5');
        $removed = [];

        $result = $this->delete($gone, [], [$gone, $next], $removed, groupRows: [$group]);

        self::assertSame([
            ['setting' => 'TOOLS', 'scope' => 'group', 'ownerId' => 7, 'modelId' => 9, 'modelName' => 'Next chat'],
        ], $result['moved']);
        self::assertSame('9', $group->getValue());
    }

    public function testAGroupDefaultSkipsAModelTheGroupDoesNotAllow(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $blocked = $this->model(8, 'Blocked', 'Groq');
        $allowed = $this->model(9, 'Allowed', 'Anthropic');
        $group = (new GroupConfig())->setGroupId(7)->setGroup('DEFAULTMODEL')->setSetting('CHAT')->setValue('5');
        $removed = [];
        $policy = $this->createStub(GroupPolicyService::class);
        $policy->method('isModelAllowedForGroup')->willReturnCallback(
            static fn (int $groupId, int $modelId): bool => 7 === $groupId && 9 === $modelId,
        );

        $result = $this->delete($gone, [], [$gone, $blocked, $allowed], $removed, policy: $policy, groupRows: [$group]);

        self::assertSame(9, $result['moved'][0]['modelId']);
        self::assertSame([], $result['cleared']);
    }

    public function testAMemoryDefaultMovesToAHiddenMemoryModel(): void
    {
        $gone = $this->model(5, 'Old memory', 'Groq', 'mem');
        $hidden = $this->model(9, 'Next memory', 'Groq', 'mem');
        $hidden->setSelectable(0);
        $chat = $this->model(8, 'Visible chat', 'Groq', 'chat');
        $row = $this->config(0, 'MEM', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $chat, $hidden], $removed);

        self::assertSame(9, $result['moved'][0]['modelId']);
        self::assertSame([], $result['cleared']);
    }

    public function testASearchDefaultMovesToAnotherChatModel(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $next = $this->model(9, 'Next chat', 'Anthropic');
        $row = $this->config(0, 'SEARCH', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $next], $removed);

        self::assertSame(9, $result['moved'][0]['modelId']);
        self::assertSame('SEARCH', $result['moved'][0]['setting']);
    }

    public function testASearchEmbeddingDefaultIsCleared(): void
    {
        $gone = $this->model(5, 'Old embed', 'Ollama', 'vectorize');
        $other = $this->model(6, 'Other embed', 'Ollama', 'vectorize');
        $row = $this->config(0, 'SEARCH_EMBED', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $other], $removed);

        self::assertSame([], $result['moved']);
        self::assertSame([['setting' => 'SEARCH_EMBED', 'scope' => 'global', 'ownerId' => 0]], $result['cleared']);
        self::assertContains($row, $removed);
    }

    public function testAVisionAliasCanReplaceAPictureDefault(): void
    {
        $gone = $this->model(5, 'Old vision', 'OpenAI', 'pic2text');
        $alias = $this->model(9, 'Vision alias', 'OpenAI', 'vision');
        $row = $this->config(0, 'PIC2TEXT', '5');
        $removed = [];

        $result = $this->delete($gone, [$row], [$gone, $alias], $removed);

        self::assertSame(9, $result['moved'][0]['modelId']);
    }

    public function testProviderAvailabilityIsReadOnceForEveryDefault(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $next = $this->model(9, 'Next chat', 'Anthropic');
        $chat = $this->config(0, 'CHAT', '5');
        $tools = $this->config(0, 'TOOLS', '5');
        $removed = [];
        $groq = $this->createMock(ProviderMetadataInterface::class);
        $groq->expects($this->once())->method('isAvailable')->willReturn(false);
        $groq->method('getName')->willReturn('Groq');
        $anthropic = $this->createMock(ProviderMetadataInterface::class);
        $anthropic->expects($this->once())->method('isAvailable')->willReturn(true);
        $anthropic->method('getName')->willReturn('Anthropic');
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn(['Groq' => $groq, 'Anthropic' => $anthropic]);

        $result = $this->delete($gone, [$chat, $tools], [$gone, $next], $removed, providers: $registry);

        self::assertCount(2, $result['moved']);
        self::assertSame([9, 9], array_column($result['moved'], 'modelId'));
    }

    public function testAnUnavailableProviderIsNotTheReplacement(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $down = $this->model(8, 'No key', 'Groq');
        $up = $this->model(9, 'Ready', 'Anthropic');
        $row = $this->config(0, 'CHAT', '5');
        $removed = [];

        $result = $this->delete(
            $gone,
            [$row],
            [$gone, $down, $up],
            $removed,
            usable: static fn (int $id): bool => true,
            providers: $this->providers(['Groq' => false, 'Anthropic' => true]),
        );

        self::assertSame(9, $result['moved'][0]['modelId']);
    }

    public function testNoReplacementIsSavedWhenEveryProviderIsUnavailable(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $other = $this->model(9, 'Also down', 'Anthropic');
        $row = $this->config(0, 'CHAT', '5');
        $removed = [];

        $result = $this->delete(
            $gone,
            [$row],
            [$gone, $other],
            $removed,
            usable: static fn (int $id): bool => true,
            providers: $this->providers(['Groq' => false, 'Anthropic' => false]),
        );

        self::assertSame([], $result['moved']);
        self::assertSame([['setting' => 'CHAT', 'scope' => 'global', 'ownerId' => 0]], $result['cleared']);
    }

    public function testARerankDefaultSkipsAModelWithNoKey(): void
    {
        $gone = $this->model(5, 'Old rerank', 'Jina', 'rerank');
        $noKey = $this->model(8, 'No key', 'Cohere', 'rerank');
        $ready = $this->model(9, 'Ready', 'Voyage', 'rerank');
        $row = $this->config(0, 'RERANK', '5');
        $removed = [];
        $rerank = $this->createStub(HttpRerankAdapter::class);
        $rerank->method('healthForModel')->willReturnCallback(
            static fn (Model $model): PlugHealth => 9 === (int) $model->getId()
                ? PlugHealth::available()
                : PlugHealth::unavailable('API key is not configured'),
        );

        $result = $this->delete(
            $gone,
            [$row],
            [$gone, $noKey, $ready],
            $removed,
            usable: static fn (int $id): bool => true,
            rerank: $rerank,
        );

        self::assertSame(9, $result['moved'][0]['modelId']);
    }

    public function testAnUnusableProviderIsSkipped(): void
    {
        $gone = $this->model(5, 'Old chat', 'Groq');
        $noKey = $this->model(8, 'No key', 'Groq');
        $ready = $this->model(9, 'Ready', 'Anthropic');
        $row = $this->config(0, 'CHAT', '5');
        $removed = [];

        $result = $this->delete(
            $gone,
            [$row],
            [$gone, $noKey, $ready],
            $removed,
            usable: static fn (int $id): bool => 8 !== $id,
        );

        self::assertSame(9, $result['moved'][0]['modelId']);
    }

    /**
     * @param list<Config>      $rows
     * @param list<Model>       $catalog
     * @param list<object>      $removed
     * @param list<int>         $offlineIds
     * @param list<GroupConfig> $groupRows
     *
     * @return array{moved: list<array<string, mixed>>, cleared: list<array<string, mixed>>}
     */
    private function delete(
        Model $gone,
        array $rows,
        array $catalog,
        array &$removed,
        array $offlineIds = [],
        ?GroupPolicyService $policy = null,
        array $groupRows = [],
        ?\Closure $usable = null,
        ?ProviderRegistry $providers = null,
        ?HttpRerankAdapter $rerank = null,
    ): array {
        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturn($gone);
        $models->method('findBy')->willReturn($catalog);

        $configs = $this->createStub(ConfigRepository::class);
        $configs->method('findBy')->willReturn($rows);

        $groups = $this->createStub(GroupConfigRepository::class);
        $groups->method('findBy')->willReturn($groupRows);

        $health = $this->createStub(ModelHealthRepository::class);
        $health->method('findOfflineModelIds')->willReturn($offlineIds);

        $usability = $this->createStub(ModelConfigService::class);
        $usability->method('isConfiguredModelUsable')->willReturnCallback(
            $usable ?? static fn (int $id): bool => true,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });
        $em->expects($this->once())->method('flush');

        $mover = new DefaultModelMover($models, $configs, $groups, $health, $usability, $em, $policy, $providers, $rerank);
        $service = new AdminModelsService(
            $em,
            $models,
            $this->createStub(ModelImportService::class),
            $this->createStub(ModelSqlValidator::class),
            $this->createStub(ModelPriceHistoryRepository::class),
            $this->createStub(ChatReadinessService::class),
            $mover,
        );

        return $service->deleteModel((int) $gone->getId());
    }

    /**
     * @param array<string, bool> $availability
     */
    private function providers(array $availability): ProviderRegistry
    {
        $providers = [];
        foreach ($availability as $name => $available) {
            $provider = $this->createStub(ProviderMetadataInterface::class);
            $provider->method('getName')->willReturn($name);
            $provider->method('isAvailable')->willReturn($available);
            $providers[$name] = $provider;
        }
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn($providers);

        return $registry;
    }

    private function model(int $id, string $name, string $service, string $tag = 'chat'): Model
    {
        $model = (new Model())
            ->setName($name)
            ->setService($service)
            ->setTag($tag)
            ->setSelectable(1)
            ->setActive(1)
            ->setProviderId(strtolower(str_replace(' ', '-', $name)))
            ->setPriceIn(1)
            ->setPriceOut(1);
        (new \ReflectionProperty(Model::class, 'id'))->setValue($model, $id);

        return $model;
    }

    private function config(int $ownerId, string $setting, string $value): Config
    {
        return (new Config())
            ->setOwnerId($ownerId)
            ->setGroup('DEFAULTMODEL')
            ->setSetting($setting)
            ->setValue($value);
    }
}
