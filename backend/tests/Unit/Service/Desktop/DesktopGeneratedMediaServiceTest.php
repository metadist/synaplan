<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\User;
use App\Service\Desktop\DesktopGeneratedMediaService;
use App\Service\MediaGenerationServiceInterface;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class DesktopGeneratedMediaServiceTest extends TestCase
{
    public function testEmptyCatalogKeyDoesNotResolve(): void
    {
        $svc = $this->service();
        self::assertNull($svc->resolveModelId(''));
        self::assertNull($svc->resolveModelId('   '));
    }

    public function testUnknownCatalogKeyDoesNotResolve(): void
    {
        $svc = $this->service();
        self::assertNull($svc->resolveModelId('not-a-real:model:text2pic'));
    }

    public function testKnownImageCatalogKeyResolvesToBid(): void
    {
        $svc = $this->service();
        self::assertSame(29, $svc->resolveModelId('openai:gpt-image-1:text2pic'));
    }

    public function testGenerateRequiresACatalogKey(): void
    {
        $media = $this->createMock(MediaGenerationServiceInterface::class);
        $media->expects($this->never())->method('generate');
        $svc = $this->service($media);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('model is required');
        $svc->generate($this->createMock(User::class), 'a cat', 'image', '');
    }

    public function testGenerateRejectsUnknownCatalogKeyBeforeCallingProvider(): void
    {
        $media = $this->createMock(MediaGenerationServiceInterface::class);
        $media->expects($this->never())->method('generate');
        $svc = $this->service($media);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown model');
        $svc->generate($this->createMock(User::class), 'a cat', 'image', 'madeup:nope:text2pic');
    }

    public function testGeneratePassesResolvedBidAndReturnsFileId(): void
    {
        $user = $this->createMock(User::class);
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(77);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->with(['filePath' => '01/000/x.png'])->willReturn($file);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(File::class)->willReturn($repo);

        $media = $this->createMock(MediaGenerationServiceInterface::class);
        $media->expects($this->once())
            ->method('generate')
            ->with($user, 'a cat', 'image', 29)
            ->willReturn([
                'success' => true,
                'file' => [
                    'url' => '/api/v1/files/uploads/01/000/x.png',
                    'type' => 'image',
                    'mimeType' => 'image/png',
                ],
                'provider' => 'openai',
                'model' => 'gpt-image-1',
            ]);

        $svc = $this->service($media, $em);
        $out = $svc->generate($user, 'a cat', 'image', 'openai:gpt-image-1:text2pic');

        self::assertTrue($out['success']);
        self::assertSame(77, $out['file']['id']);
        self::assertSame('/api/v1/files/uploads/01/000/x.png', $out['file']['url']);
    }

    private function service(
        ?MediaGenerationServiceInterface $media = null,
        ?EntityManagerInterface $em = null,
    ): DesktopGeneratedMediaService {
        return new DesktopGeneratedMediaService(
            $media ?? $this->createMock(MediaGenerationServiceInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(RateLimitService::class),
            $em ?? $this->createMock(EntityManagerInterface::class),
            '/tmp/uploads',
        );
    }
}
