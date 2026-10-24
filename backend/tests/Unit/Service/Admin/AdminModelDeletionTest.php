<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\AI\Credential\ChatReadinessService;
use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Service\Admin\AdminModelsService;
use App\Service\Admin\DefaultModelMover;
use App\Service\Admin\ModelImportService;
use App\Service\Admin\ModelSqlValidator;
use App\Service\Iam\Policy\GroupPolicyService;
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
            ['setting' => 'CHAT', 'ownerId' => 0, 'modelId' => 9, 'modelName' => 'Next chat'],
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
        self::assertSame([['setting' => 'CHAT', 'ownerId' => 0]], $result['cleared']);
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
        self::assertSame([['setting' => 'VECTORIZE', 'ownerId' => 0]], $result['cleared']);
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
        self::assertSame([['setting' => 'CHAT', 'ownerId' => 4]], $result['cleared']);
    }

    /**
     * @param list<Config> $rows
     * @param list<Model>  $catalog
     * @param list<object> $removed
     * @param list<int>    $offlineIds
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
    ): array {
        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturn($gone);
        $models->method('findBy')->willReturn($catalog);

        $configs = $this->createStub(ConfigRepository::class);
        $configs->method('findBy')->willReturn($rows);

        $health = $this->createStub(ModelHealthRepository::class);
        $health->method('findOfflineModelIds')->willReturn($offlineIds);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });
        $em->expects($this->once())->method('flush');

        $mover = new DefaultModelMover($models, $configs, $health, $em, $policy);
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
