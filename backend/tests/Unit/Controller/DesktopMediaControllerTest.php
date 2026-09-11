<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DesktopMediaController;
use App\Entity\User;
use App\Service\Desktop\DesktopGeneratedMediaService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class DesktopMediaControllerTest extends TestCase
{
    public function testGenerateRequiresAuth(): void
    {
        $media = $this->createMock(DesktopGeneratedMediaService::class);
        $media->expects($this->never())->method('generate');

        $controller = new DesktopMediaController($media, $this->createMock(LoggerInterface::class));
        $response = $controller->generate(new Request(), null);

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('invalid_api_key', $data['error']['code']);
    }

    public function testSpeechRequiresAuth(): void
    {
        $media = $this->createMock(DesktopGeneratedMediaService::class);
        $media->expects($this->never())->method('speak');

        $controller = new DesktopMediaController($media, $this->createMock(LoggerInterface::class));
        $response = $controller->speech(new Request(), null);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testGenerateDelegatesCatalogKey(): void
    {
        $user = $this->createMock(User::class);
        $media = $this->createMock(DesktopGeneratedMediaService::class);
        $media->expects($this->once())
            ->method('generate')
            ->with($user, 'a cat', 'image', 'openai:gpt-image-1:text2pic')
            ->willReturn([
                'success' => true,
                'file' => ['url' => '/api/v1/files/uploads/x.png', 'type' => 'image', 'mimeType' => 'image/png', 'id' => 1],
                'provider' => 'openai',
                'model' => 'gpt-image-1',
            ]);

        $controller = new DesktopMediaController($media, $this->createMock(LoggerInterface::class));
        $request = new Request(content: json_encode([
            'prompt' => 'a cat',
            'type' => 'image',
            'model' => 'openai:gpt-image-1:text2pic',
        ], JSON_THROW_ON_ERROR));
        $response = $controller->generate($request, $user);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame(1, $data['file']['id']);
    }
}
