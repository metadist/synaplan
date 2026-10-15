<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vision;

use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\ModelConfigService;
use App\Service\Vision\VisionAttachmentEncoder;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * When the account's chat model can't see images, the vision fallback must
 * honour the CONFIGURED image model (DEFAULTMODEL.PIC2TEXT) instead of grabbing
 * the globally highest-quality vision model. Previously the global pick meant a
 * WhatsApp image reply could arrive as an Anthropic answer even though the
 * account never configured that provider.
 */
final class VisionFallbackModelTest extends TestCase
{
    public function testPrefersConfiguredPic2TextModel(): void
    {
        $configured = $this->createMock(Model::class);
        $configured->method('getId')->willReturn(42);
        $configured->method('hasFeature')->willReturn(true);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(42);

        $repo = $this->createMock(ModelRepository::class);
        $repo->method('find')->willReturn($configured);
        // The global catalog pick must NOT be consulted when a configured
        // vision model is available.
        $repo->expects($this->never())->method('findByFeature');

        $result = $this->encoder($modelConfig, $repo)->fallbackModel(7);

        $this->assertSame($configured, $result);
    }

    public function testFallsBackToCatalogWhenNoConfiguredModel(): void
    {
        $catalog = $this->createMock(Model::class);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(null);

        $repo = $this->createMock(ModelRepository::class);
        $repo->method('findByFeature')->willReturn($catalog);

        $result = $this->encoder($modelConfig, $repo)->fallbackModel(7);

        $this->assertSame($catalog, $result);
    }

    public function testFallsBackToCatalogWhenConfiguredModelLacksVision(): void
    {
        $configured = $this->createMock(Model::class);
        $configured->method('hasFeature')->willReturn(false);
        $catalog = $this->createMock(Model::class);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(99);

        $repo = $this->createMock(ModelRepository::class);
        $repo->method('find')->willReturn($configured);
        $repo->method('findByFeature')->willReturn($catalog);

        $result = $this->encoder($modelConfig, $repo)->fallbackModel(7);

        $this->assertSame($catalog, $result);
    }

    private function encoder(ModelConfigService $modelConfig, ModelRepository $repo): VisionAttachmentEncoder
    {
        return new VisionAttachmentEncoder(
            sys_get_temp_dir(),
            $this->createStub(ConversationFileCatalog::class),
            $this->createStub(GeneratedImageVisionFlag::class),
            $repo,
            new VisionModelResolver($modelConfig, $repo),
            new NullLogger(),
        );
    }
}
