<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Stt;

use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use App\Service\Stt\Exception\SttModelNotFoundException;
use App\Service\Stt\SttModelResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SttModelResolverTest extends TestCase
{
    private ModelRepository&MockObject $models;
    private ModelConfigService&MockObject $config;
    private SttModelResolver $resolver;

    protected function setUp(): void
    {
        $this->models = $this->createMock(ModelRepository::class);
        $this->config = $this->createMock(ModelConfigService::class);
        $this->resolver = new SttModelResolver($this->models, $this->config);
    }

    public function testResolveByRequestedIdentity(): void
    {
        $model = $this->sttModel(330, 'whisper', 'Whisper');

        $this->models->expects($this->once())
            ->method('findActiveByTagAndIdentity')
            ->with('sound2text', 'whisper')
            ->willReturn($model);

        $resolved = $this->resolver->resolve('whisper', 7);

        $this->assertSame('whisper', $resolved['provider']);
        $this->assertSame('whisper', $resolved['providerModelId']);
        $this->assertSame(330, $resolved['model_id']);
    }

    public function testResolveFallsBackToSound2TextDefault(): void
    {
        $this->models->method('findActiveByTagAndIdentity')->willReturn(null);
        $this->config->expects($this->once())
            ->method('getConfiguredDefaultModel')
            ->with('SOUND2TEXT', 7)
            ->willReturn(21);
        $this->config->expects($this->never())->method('resolveSttDefault');
        $this->config->method('isConfiguredModelUsable')->willReturn(true);
        $this->models->expects($this->any())
            ->method('find')
            ->with(21)
            ->willReturn($this->sttModel(21, 'whisper-large-v3', 'Groq'));

        $resolved = $this->resolver->resolve(null, 7);

        $this->assertSame('groq', $resolved['provider']);
        $this->assertSame('whisper-large-v3', $resolved['displayModel']);
    }

    public function testUnusableCloudDefaultBindsLocalWhisper(): void
    {
        $this->config->method('getConfiguredDefaultModel')->with('SOUND2TEXT', 7)->willReturn(21);
        $this->config->expects($this->never())->method('resolveSttDefault');
        $this->config->method('isConfiguredModelUsable')->willReturnCallback(
            static fn (int $id): bool => 330 === $id,
        );
        $this->models->method('findActiveByTag')->willReturn([
            $this->sttModel(21, 'whisper-large-v3', 'Groq'),
            $this->sttModel(330, 'whisper', 'Whisper'),
        ]);

        $resolved = $this->resolver->resolve(null, 7);

        $this->assertSame('whisper', $resolved['provider']);
        $this->assertSame(330, $resolved['model_id']);
    }

    public function testUnusableStoredCloudDoesNotBindAnotherCloudProvider(): void
    {
        $this->config->method('getConfiguredDefaultModel')->with('SOUND2TEXT', 7)->willReturn(21);
        $this->config->method('isConfiguredModelUsable')->willReturnCallback(
            static fn (int $id): bool => 22 === $id || 330 === $id,
        );
        $this->models->method('findActiveByTag')->willReturn([
            $this->sttModel(21, 'whisper-large-v3', 'Groq'),
            $this->sttModel(22, 'whisper-1', 'OpenAI'),
            $this->sttModel(330, 'whisper', 'Whisper'),
        ]);

        $resolved = $this->resolver->resolve(null, 7);

        $this->assertSame('whisper', $resolved['provider']);
        $this->assertSame(330, $resolved['model_id']);
    }

    public function testUnknownModelThrows(): void
    {
        $this->models->method('findActiveByTagAndIdentity')->willReturn(null);

        $this->expectException(SttModelNotFoundException::class);
        $this->resolver->resolve('no-such-stt', 7);
    }

    public function testListModelsReturnsOpenAiShape(): void
    {
        $this->models->expects($this->once())
            ->method('findActiveByTag')
            ->with('sound2text')
            ->willReturn([
                $this->sttModel(330, 'whisper', 'Whisper'),
            ]);

        $list = $this->resolver->listModels();

        $this->assertCount(1, $list);
        $this->assertSame('whisper', $list[0]['id']);
        $this->assertSame('whisper', $list[0]['owned_by']);
        $this->assertSame('sound2text', $list[0]['tag']);
    }

    private function sttModel(int $id, string $providerId, string $service): Model
    {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($providerId);
        $model->method('getService')->willReturn($service);
        $model->method('getTag')->willReturn('sound2text');
        $model->method('getActive')->willReturn(1);

        return $model;
    }
}
