<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\ModelConfigService;
use App\Service\Research\Run\ConfigResearchModelLookup;
use PHPUnit\Framework\TestCase;

final class ConfigResearchModelLookupTest extends TestCase
{
    public function testALockedSlotDefaultOutsideTheAllowListStaysAllowed(): void
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturnCallback(
            static fn (string $slot): ?int => 'VERIFY' === $slot ? 12 : null,
        );
        $models->method('isConfiguredModelUsable')->willReturn(true);
        $groups = $this->createMock(GroupPolicyService::class);
        $groups->expects($this->never())->method('isModelAllowed');

        $choice = $this->lookup([12 => $this->model('anthropic', 'chat')], $models, $groups)->slot(7, 'VERIFY');

        $this->assertNotNull($choice);
        $this->assertTrue($choice->allowed);
        $this->assertSame('anthropic', $choice->family);
    }

    public function testFallbackIsSkippedWhenSlotHasNoConfiguredBinding(): void
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getConfiguredDefaultModel')->willReturn(null);
        $models->method('getDefaultModel')->willReturn(null);

        $this->assertNull($this->lookup([12 => $this->model('anthropic', 'chat')], $models)->slot(7, 'READ'));
    }

    public function testConfiguredBindingTakesPrecedenceInSlot(): void
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getConfiguredDefaultModel')->willReturnCallback(
            static fn (string $slot): ?int => 'READ' === $slot ? 12 : null,
        );
        $models->method('isConfiguredModelUsable')->willReturn(true);

        $choice = $this->lookup([12 => $this->model('anthropic', 'chat')], $models)->slot(7, 'READ');

        $this->assertNotNull($choice);
        $this->assertSame(12, $choice->id);
    }

    public function testAHintedModelIsCheckedAgainstTheAllowList(): void
    {
        $groups = $this->createMock(GroupPolicyService::class);
        $groups->expects($this->once())->method('isModelAllowed')->with(7, 12)->willReturn(false);

        $choice = $this->lookup([12 => $this->model('anthropic', 'chat')], $this->usable(true), $groups)->byId(7, 12);

        $this->assertNotNull($choice);
        $this->assertFalse($choice->allowed);
    }

    public function testANonChatModelIsNeverOffered(): void
    {
        $this->assertNull($this->lookup([30 => $this->model('openai', 'text2sound')], $this->usable(true))->byId(7, 30));
    }

    public function testAModelGenerationCannotUseIsUnhealthy(): void
    {
        $choice = $this->lookup([40 => $this->model('ollama', 'chat')], $this->usable(false))->byId(7, 40);

        $this->assertNotNull($choice);
        $this->assertFalse($choice->healthy);
    }

    public function testAPermanentlyOfflineModelIsUnhealthy(): void
    {
        $choice = $this->lookup([41 => $this->model('google', 'chat')], $this->usable(true), offline: [41])->byId(7, 41);

        $this->assertNotNull($choice);
        $this->assertFalse($choice->healthy);
    }

    /**
     * @param array<int, Model> $rows
     * @param list<int>         $offline
     */
    private function lookup(array $rows, ModelConfigService $models, ?GroupPolicyService $groups = null, array $offline = []): ConfigResearchModelLookup
    {
        $repository = $this->createStub(ModelRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (mixed $id): ?Model => $rows[(int) $id] ?? null,
        );
        $health = $this->createStub(ModelHealthRepository::class);
        $health->method('findOfflineModelIds')->willReturn($offline);
        if (null === $groups) {
            $groups = $this->createStub(GroupPolicyService::class);
            $groups->method('isModelAllowed')->willReturn(true);
        }

        return new ConfigResearchModelLookup($models, $repository, $health, $this->createStub(ConfigRepository::class), $groups);
    }

    private function usable(bool $usable): ModelConfigService
    {
        $models = $this->createStub(ModelConfigService::class);
        $models->method('isConfiguredModelUsable')->willReturn($usable);

        return $models;
    }

    private function model(string $family, string $tag): Model
    {
        return (new Model())
            ->setService($family)
            ->setTag($tag)
            ->setActive(1)
            ->setJson(['meta' => ['family' => $family, 'context_window' => '128000']]);
    }
}
