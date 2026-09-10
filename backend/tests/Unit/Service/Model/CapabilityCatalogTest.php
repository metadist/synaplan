<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Model;

use App\AI\Credential\ChatReadinessService;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\Model\CapabilityCatalog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CapabilityCatalogTest extends TestCase
{
    public function testGroupsForTagMatchDesktopSlots(): void
    {
        $this->assertSame(['CHAT', 'ANALYZE'], CapabilityCatalog::groupsForTag('chat'));
        $this->assertSame(['ANALYZE'], CapabilityCatalog::groupsForTag('analyze'));
        $this->assertSame(['VECTORIZE'], CapabilityCatalog::groupsForTag('vectorize'));
        $this->assertSame(['VECTORIZE'], CapabilityCatalog::groupsForTag('embedding'));
        $this->assertSame(['PIC2TEXT'], CapabilityCatalog::groupsForTag('vision'));
        $this->assertSame(['TEXT2PIC'], CapabilityCatalog::groupsForTag('image'));
        $this->assertSame(['TEXT2VID'], CapabilityCatalog::groupsForTag('video'));
        $this->assertSame([], CapabilityCatalog::groupsForTag('video', ['image2video']));
        $this->assertSame(['SOUND2TEXT'], CapabilityCatalog::groupsForTag('audio'));
        $this->assertSame(['TEXT2SOUND'], CapabilityCatalog::groupsForTag('tts'));
        $this->assertSame([], CapabilityCatalog::groupsForTag('rerank'));
        $this->assertSame([], CapabilityCatalog::groupsForTag('mem'));
    }

    public function testForUserReturnsEightGroupsCatalogKeysAndKeepsUnavailable(): void
    {
        $chat = $this->model(11, 'Ollama', 'llama3.2', 'Llama 3.2', 'chat', selectable: 1);
        $embed = $this->model(12, 'Ollama', 'bge-m3', 'bge-m3', 'vectorize', selectable: 1);
        $hidden = $this->model(13, 'Ollama', 'system-sorter', 'Sorter', 'chat', selectable: 0);
        $rerank = $this->model(14, 'Jina', 'jina-rerank', 'Rerank', 'rerank', selectable: 1);

        $models = $this->createMock(ModelRepository::class);
        $models->method('findBy')->willReturn([$chat, $embed, $hidden, $rerank]);

        $readiness = $this->createMock(ChatReadinessService::class);
        $readiness->method('providerAvailability')->willReturn(['ollama' => true]);
        $readiness->method('modelAvailability')->willReturnCallback(
            static function (string $service, string $providerId): array {
                if ('bge-m3' === $providerId) {
                    return ['available' => false, 'reason' => 'not_pulled'];
                }

                return ['available' => true, 'reason' => null];
            }
        );

        $catalog = new CapabilityCatalog($models, $readiness);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);

        $payload = $catalog->forUser($user);

        $this->assertSame('catalog', $payload['object']);
        $this->assertSame(CapabilityCatalog::GROUPS, array_keys($payload['capabilities']));

        $chatRows = $payload['capabilities']['CHAT'];
        $this->assertCount(1, $chatRows);
        $this->assertSame('ollama:llama3.2:chat', $chatRows[0]['id']);
        $this->assertSame('llama3.2', $chatRows[0]['providerId']);
        $this->assertSame('ollama', $chatRows[0]['service']);
        $this->assertTrue($chatRows[0]['available']);
        $this->assertNull($chatRows[0]['unavailableReason']);

        $this->assertSame($chatRows, $payload['capabilities']['ANALYZE']);

        $embedRows = $payload['capabilities']['VECTORIZE'];
        $this->assertCount(1, $embedRows);
        $this->assertSame('ollama:bge-m3:vectorize', $embedRows[0]['id']);
        $this->assertFalse($embedRows[0]['available']);
        $this->assertSame('not_pulled', $embedRows[0]['unavailableReason']);

        foreach (['SOUND2TEXT', 'TEXT2SOUND', 'PIC2TEXT', 'TEXT2PIC', 'TEXT2VID'] as $empty) {
            $this->assertSame([], $payload['capabilities'][$empty]);
        }
    }

    public function testForUserAppliesGroupAllowList(): void
    {
        $chat = $this->model(11, 'Ollama', 'llama3.2', 'Llama 3.2', 'chat', selectable: 1);
        $models = $this->createMock(ModelRepository::class);
        $models->method('findBy')->willReturn([$chat]);

        $readiness = $this->createMock(ChatReadinessService::class);
        $readiness->method('providerAvailability')->willReturn([]);
        $readiness->method('modelAvailability')->willReturn(['available' => true, 'reason' => null]);

        $policy = $this->createMock(GroupPolicyService::class);
        $policy->expects($this->exactly(count(CapabilityCatalog::GROUPS)))
            ->method('filterModelsByAllowList')
            ->willReturn([]);

        $catalog = new CapabilityCatalog($models, $readiness, $policy);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $payload = $catalog->forUser($user);
        $this->assertSame([], $payload['capabilities']['CHAT']);
    }

    private function model(
        int $id,
        string $service,
        string $providerId,
        string $name,
        string $tag,
        int $selectable,
    ): Model&MockObject {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($name);
        $model->method('getTag')->willReturn($tag);
        $model->method('getSelectable')->willReturn($selectable);
        $model->method('isHiddenBecauseFree')->willReturn(false);
        $model->method('getFeatures')->willReturn([]);

        return $model;
    }
}
