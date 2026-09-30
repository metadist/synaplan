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
        $repo->expects($this->once())->method('findOneBy')->with(['filePath' => '01/000/x.png'])->willReturn($file);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('getRepository')->with(File::class)->willReturn($repo);

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

        self::assertSame(77, $out['file']['id']);
        self::assertSame('/api/v1/files/uploads/01/000/x.png', $out['file']['url']);
    }

    public function testSpeakPassesExplicitLanguageToFacade(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $user->method('getLocale')->willReturn('en');

        $model = $this->createMock(\App\Entity\Model::class);
        $model->method('getService')->willReturn('Piper');
        $model->method('getProviderId')->willReturn('piper-multi');
        $model->method('getName')->willReturn('Piper Multi-Language');

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->expects($this->any())->method('find')->with(140)->willReturn($model);

        $fileRepo = $this->createMock(EntityRepository::class);
        $fileRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($modelRepo, $fileRepo) {
            return \App\Entity\Model::class === $class ? $modelRepo : $fileRepo;
        });
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('synthesize')
            ->with('Guten Morgen', 'de', 5, self::anything())
            ->willReturn([
                'relativePath' => '5/000/tts.mp3',
                'provider' => 'piper',
                'model' => 'de_DE-kerstin-low',
            ]);

        $rateLimit = $this->createMock(RateLimitService::class);
        $rateLimit->method('checkLimit')->willReturn(['allowed' => true, 'used' => 0, 'limit' => 100]);

        $svc = new DesktopGeneratedMediaService(
            $this->createMock(MediaGenerationServiceInterface::class),
            $aiFacade,
            $rateLimit,
            $em,
            '/tmp/uploads',
        );

        $out = $svc->speak($user, 'Guten Morgen', 'piper:piper-multi:text2sound', 'de');
        self::assertSame('/api/v1/files/uploads/5/000/tts.mp3', $out['file']['url']);
    }

    public function testSpeakFallsBackToUserLocaleWhenLanguageOmitted(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(5);
        $user->method('getLocale')->willReturn('fr');

        $model = $this->createMock(\App\Entity\Model::class);
        $model->method('getService')->willReturn('Piper');
        $model->method('getProviderId')->willReturn('piper-multi');
        $model->method('getName')->willReturn('Piper Multi-Language');

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->expects($this->any())->method('find')->with(140)->willReturn($model);
        $fileRepo = $this->createMock(EntityRepository::class);
        $fileRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($modelRepo, $fileRepo) {
            return \App\Entity\Model::class === $class ? $modelRepo : $fileRepo;
        });
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('synthesize')
            ->with('Bonjour', 'fr', 5, self::anything())
            ->willReturn([
                'relativePath' => '5/000/tts.mp3',
                'provider' => 'piper',
                'model' => 'fr_FR-siwis-medium',
            ]);

        $rateLimit = $this->createMock(RateLimitService::class);
        $rateLimit->method('checkLimit')->willReturn(['allowed' => true, 'used' => 0, 'limit' => 100]);

        $svc = new DesktopGeneratedMediaService(
            $this->createMock(MediaGenerationServiceInterface::class),
            $aiFacade,
            $rateLimit,
            $em,
            '/tmp/uploads',
        );

        $out = $svc->speak($user, 'Bonjour', 'piper:piper-multi:text2sound');
        self::assertSame('piper', $out['provider']);
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
