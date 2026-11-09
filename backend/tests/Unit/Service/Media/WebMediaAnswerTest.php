<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\AI\Exception\ProviderException;
use App\Entity\User;
use App\Service\Media\MediaGenerationServiceInterface;
use App\Service\Media\WebMediaAnswer;
use PHPUnit\Framework\TestCase;

final class WebMediaAnswerTest extends TestCase
{
    public function testAnAsyncVideoIsAJobAndDoesNotRenderInTheRequest(): void
    {
        $user = $this->createStub(User::class);
        $media = $this->createMock(MediaGenerationServiceInterface::class);
        $media->expects(self::once())
            ->method('startVideoGeneration')
            ->with($user, 'a wave', null, '1080p')
            ->willReturn([
                'jobId' => 'abc',
                'status' => 'processing',
                'provider' => 'google',
                'model' => 'veo-3.1',
                'resolution' => '1080p',
            ]);
        $media->expects(self::never())->method('generate');

        $answer = (new WebMediaAnswer($media))->answer($user, 'a wave', 'video', null, '1080p');

        self::assertSame(202, $answer['status']);
        self::assertSame('abc', $answer['body']['jobId']);
        self::assertArrayNotHasKey('file', $answer['body']);
    }

    public function testAProviderThatCannotQueueStillReturnsTheFile(): void
    {
        $user = $this->createStub(User::class);
        $file = [
            'success' => true,
            'file' => ['url' => '/api/v1/files/uploads/clip.mp4', 'type' => 'video', 'mimeType' => 'video/mp4'],
            'provider' => 'huggingface',
            'model' => 'video',
        ];
        $media = $this->createMock(MediaGenerationServiceInterface::class);
        $media->method('startVideoGeneration')->willThrowException(
            new ProviderException('Async video generation is not supported by huggingface', 'huggingface'),
        );
        $media->expects(self::once())->method('generate')->with($user, 'a wave', 'video', null, null)->willReturn($file);

        $answer = (new WebMediaAnswer($media))->answer($user, 'a wave', 'video', null, null);

        self::assertSame(200, $answer['status']);
        self::assertSame($file, $answer['body']);
    }

    public function testAnotherProviderFailureIsNotTurnedIntoABlockingRender(): void
    {
        $user = $this->createStub(User::class);
        $media = $this->createMock(MediaGenerationServiceInterface::class);
        $media->method('startVideoGeneration')->willThrowException(new ProviderException('quota', 'google'));
        $media->expects(self::never())->method('generate');

        $this->expectException(ProviderException::class);
        (new WebMediaAnswer($media))->answer($user, 'a wave', 'video', null, null);
    }
}
