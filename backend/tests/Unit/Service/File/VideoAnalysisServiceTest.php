<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Service\File\ThumbnailService;
use App\Service\File\VideoAnalysisService;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\NativeType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #983 — visual key-frame description for videos. Guards that the
 * service reuses an existing thumbnail, falls back to generating one, and
 * stays fully fault tolerant (returns null instead of throwing).
 */
class VideoAnalysisServiceTest extends TestCase
{
    private ThumbnailService&MockObject $thumbnailService;
    private AiFacade&MockObject $aiFacade;
    private VideoAnalysisService $service;

    protected function setUp(): void
    {
        $this->thumbnailService = $this->createMock(ThumbnailService::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->service = new VideoAnalysisService(
            $this->thumbnailService,
            $this->aiFacade,
            new NullLogger(),
        );
    }

    public function testReusesExistingThumbnailAndReturnsDescription(): void
    {
        $this->thumbnailService
            ->expects($this->once())
            ->method('getThumbnailIfExists')
            ->with('13/000/clip.mp4')
            ->willReturn('13/000/clip_thumb.jpg');

        $this->thumbnailService->expects($this->never())->method('generateThumbnail');

        $this->aiFacade
            ->expects($this->once())
            ->method('analyzeImage')
            ->with('13/000/clip_thumb.jpg', new IsType(NativeType::String), 7)
            ->willReturn(['content' => 'A person waving at the camera.']);

        $this->assertSame(
            'A person waving at the camera.',
            $this->service->describeKeyFrame('13/000/clip.mp4', 7),
        );
    }

    public function testGeneratesThumbnailWhenNoneExists(): void
    {
        $this->thumbnailService
            ->method('getThumbnailIfExists')
            ->willReturn(null);

        $this->thumbnailService
            ->expects($this->once())
            ->method('generateThumbnail')
            ->with('13/000/clip.mp4')
            ->willReturn('13/000/clip_thumb.jpg');

        $this->aiFacade
            ->method('analyzeImage')
            ->willReturn(['content' => 'A sunset over the ocean.']);

        $this->assertSame(
            'A sunset over the ocean.',
            $this->service->describeKeyFrame('13/000/clip.mp4'),
        );
    }

    public function testReturnsNullWhenFrameCannotBeExtracted(): void
    {
        $this->thumbnailService->method('getThumbnailIfExists')->willReturn(null);
        $this->thumbnailService->method('generateThumbnail')->willReturn(null);

        $this->aiFacade->expects($this->never())->method('analyzeImage');

        $this->assertNull($this->service->describeKeyFrame('13/000/clip.mp4'));
    }

    public function testReturnsNullWhenVisionThrows(): void
    {
        $this->thumbnailService->method('getThumbnailIfExists')->willReturn('13/000/clip_thumb.jpg');
        $this->aiFacade
            ->method('analyzeImage')
            ->willThrowException(new \RuntimeException('vision boom'));

        $this->assertNull($this->service->describeKeyFrame('13/000/clip.mp4'));
    }

    public function testReturnsNullForEmptyDescription(): void
    {
        $this->thumbnailService->method('getThumbnailIfExists')->willReturn('13/000/clip_thumb.jpg');
        $this->aiFacade->method('analyzeImage')->willReturn(['content' => '   ']);

        $this->assertNull($this->service->describeKeyFrame('13/000/clip.mp4'));
    }

    public function testStripsTestProviderPrefix(): void
    {
        $this->thumbnailService->method('getThumbnailIfExists')->willReturn('13/000/clip_thumb.jpg');
        $this->aiFacade
            ->method('analyzeImage')
            ->willReturn(['content' => 'Test image description: a dog running.']);

        $this->assertSame('a dog running.', $this->service->describeKeyFrame('13/000/clip.mp4'));
    }
}
