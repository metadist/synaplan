<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Health\ModelAlternatives;
use App\Entity\Model;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;

final class ModelAlternativesTest extends TestCase
{
    public function testSameProviderComesFirstAndTheBrokenModelIsLeftOut(): void
    {
        $broken = $this->model(1, 'gpt 5.5', 'OpenAI');
        $rows = [
            $this->model(2, 'Claude', 'Anthropic'),
            $broken,
            $this->model(3, 'gpt 6 sol', 'OpenAI'),
            $this->model(4, 'Gemini', 'Google'),
        ];

        $listed = $this->alternatives($rows)->forModel($broken);

        self::assertSame([3, 2, 4], array_column($listed, 'id'));
        self::assertSame('gpt 6 sol', $listed[0]['name']);
        self::assertSame('OpenAI', $listed[0]['service']);
    }

    public function testUnusableRetiredInactiveHiddenAndOfflineModelsAreSkipped(): void
    {
        $broken = $this->model(1, 'gpt 5.5', 'OpenAI');
        $rows = [
            $broken,
            $this->model(2, 'retired', 'OpenAI')->setRetiredOn(new \DateTimeImmutable('2026-01-01')),
            $this->model(3, 'switched off', 'OpenAI')->setActive(0),
            $this->model(4, 'free hidden', 'OpenAI')->setPriceIn(0)->setPriceOut(0)->setShowWhenFree(0),
            $this->model(5, 'offline', 'OpenAI'),
            $this->model(6, 'no key', 'Groq'),
            $this->model(7, 'gpt 6 sol', 'OpenAI'),
        ];

        $usable = $this->createStub(ModelConfigService::class);
        $usable->method('isConfiguredModelUsable')->willReturnCallback(
            static fn (int $id): bool => 6 !== $id
        );

        $listed = $this->alternatives($rows, offline: [5], usable: $usable)->forModel($broken);

        self::assertSame([7], array_column($listed, 'id'));
    }

    public function testAGroupAllowListDropsModelsThePersonCannotUse(): void
    {
        $broken = $this->model(1, 'gpt 5.5', 'OpenAI');
        $rows = [
            $broken,
            $this->model(2, 'blocked', 'OpenAI'),
            $this->model(3, 'allowed', 'OpenAI'),
        ];
        $policy = $this->createStub(GroupPolicyService::class);
        $policy->method('isModelAllowed')->willReturnCallback(
            static fn (?int $userId, int $modelId, ?string $capability = null): bool => 2 !== $modelId
        );

        $listed = $this->alternatives($rows, policy: $policy)->forModel($broken, 9, 'CHAT');

        self::assertSame([3], array_column($listed, 'id'));
    }

    public function testOnlyFiveAlternativesAreReturned(): void
    {
        $broken = $this->model(1, 'gpt 5.5', 'OpenAI');
        $rows = [$broken];
        for ($id = 2; $id <= 8; ++$id) {
            $rows[] = $this->model($id, 'model '.$id, 'OpenAI');
        }

        self::assertCount(5, $this->alternatives($rows)->forModel($broken));
    }

    public function testAPlainImageModelIsNotOfferedForImageEditing(): void
    {
        $editor = $this->model(1, 'edit', 'OpenAI', 'text2pic')->setJson(['features' => ['pic2pic']]);
        $generator = $this->model(2, 'image', 'OpenAI', 'text2pic');
        $otherEditor = $this->model(3, 'retouch', 'OpenAI', 'text2pic')->setJson(['features' => ['pic2pic']]);

        $listed = $this->alternatives([$editor, $generator, $otherEditor])->forModel($editor);

        self::assertSame([3], array_column($listed, 'id'));
    }

    public function testDefaultSlotFollowsTheTaskAndSkipsEmbeddings(): void
    {
        $alternatives = $this->alternatives([]);

        self::assertSame('CHAT', $alternatives->defaultCapability($this->model(1, 'chat', 'OpenAI', 'chat')));
        self::assertSame(
            'PIC2PIC',
            $alternatives->defaultCapability($this->model(2, 'edit', 'OpenAI', 'text2pic')->setJson(['features' => ['pic2pic']]))
        );
        self::assertSame('TEXT2PIC', $alternatives->defaultCapability($this->model(3, 'image', 'OpenAI', 'text2pic')));
        self::assertNull($alternatives->defaultCapability($this->model(4, 'embed', 'Ollama', 'vectorize')));
    }

    /**
     * @param list<Model> $rows
     * @param list<int>   $offline
     */
    private function alternatives(
        array $rows,
        array $offline = [],
        ?ModelConfigService $usable = null,
        ?GroupPolicyService $policy = null,
    ): ModelAlternatives {
        $models = $this->createStub(ModelRepository::class);
        $models->method('findByTag')->willReturn($rows);

        $health = $this->createStub(ModelHealthRepository::class);
        $health->method('findOfflineModelIds')->willReturn($offline);

        if (null === $usable) {
            $usable = $this->createStub(ModelConfigService::class);
            $usable->method('isConfiguredModelUsable')->willReturn(true);
        }

        return new ModelAlternatives($models, $health, $usable, $policy);
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
        $idProperty = new \ReflectionProperty(Model::class, 'id');
        $idProperty->setValue($model, $id);

        return $model;
    }
}
