<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vision;

use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;

final class VisionModelResolverTest extends TestCase
{
    public function testPrefersConfiguredPic2TextWhenVisionCapable(): void
    {
        $configured = $this->createMock(Model::class);
        $configured->expects($this->any())->method('hasFeature')->with('vision')->willReturn(true);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->expects($this->any())->method('getDefaultModel')->with('PIC2TEXT', 7)->willReturn(11);

        $models = $this->createMock(ModelRepository::class);
        $models->expects($this->any())->method('find')->with(11)->willReturn($configured);
        $models->expects($this->never())->method('findByFeature');

        $resolver = new VisionModelResolver($modelConfig, $models);

        self::assertSame($configured, $resolver->resolve(7));
        self::assertTrue($resolver->isAvailable(7));
    }

    public function testFallsBackToCatalogWhenPic2TextMissingOrNotVision(): void
    {
        $catalog = $this->createMock(Model::class);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(null);

        $models = $this->createMock(ModelRepository::class);
        $models->expects($this->any())->method('findByFeature')->with('vision', 'chat', true)->willReturn($catalog);

        $resolver = new VisionModelResolver($modelConfig, $models);

        self::assertSame($catalog, $resolver->resolve(3));
    }

    public function testUnavailableWhenNothingResolves(): void
    {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(null);

        $models = $this->createMock(ModelRepository::class);
        $models->method('findByFeature')->willReturn(null);

        $resolver = new VisionModelResolver($modelConfig, $models);

        self::assertNull($resolver->resolve(1));
        self::assertFalse($resolver->isAvailable(1));
    }
}
