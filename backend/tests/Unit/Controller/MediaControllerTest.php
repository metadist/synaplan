<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Exception\ProviderException;
use App\Controller\MediaController;
use App\Entity\User;
use App\Service\Exception\NoModelAvailableException;
use App\Service\Exception\RateLimitExceededException;
use App\Service\MediaGenerationServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

class MediaControllerTest extends TestCase
{
    private MediaGenerationServiceInterface $mediaService;
    private MediaController $controller;

    protected function setUp(): void
    {
        $this->mediaService = $this->createMock(MediaGenerationServiceInterface::class);
        $this->controller = new MediaController($this->mediaService, new NullLogger());

        $container = new Container();
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
        });
        $this->controller->setContainer($container);
    }

    private function createUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }

    private function makeRequest(array $body): Request
    {
        return Request::create('/api/v1/media/generate', 'POST', content: json_encode($body));
    }

    public function testUnauthenticatedReturns401(): void
    {
        $request = $this->makeRequest(['prompt' => 'test', 'type' => 'image']);
        $response = $this->controller->generate($request, null);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Not authenticated', $response->getContent());
    }

    public function testInvalidArgumentReturns400(): void
    {
        $this->mediaService->method('generate')
            ->willThrowException(new \InvalidArgumentException('Prompt is required'));

        $response = $this->controller->generate(
            $this->makeRequest(['prompt' => '', 'type' => 'image']),
            $this->createUser(),
        );

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('Prompt is required', $data['error']);
    }

    public function testRateLimitReturns429(): void
    {
        $this->mediaService->method('generate')
            ->willThrowException(new RateLimitExceededException('IMAGES', 10, 10));

        $response = $this->controller->generate(
            $this->makeRequest(['prompt' => 'sunset', 'type' => 'image']),
            $this->createUser(),
        );

        self::assertSame(429, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('Rate limit exceeded', $data['error']);
    }

    public function testNoModelReturns422(): void
    {
        $this->mediaService->method('generate')
            ->willThrowException(new NoModelAvailableException('No model available for image generation'));

        $response = $this->controller->generate(
            $this->makeRequest(['prompt' => 'sunset', 'type' => 'image']),
            $this->createUser(),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('No model available for image generation', $data['error']);
    }

    public function testProviderExceptionReturns500(): void
    {
        $this->mediaService->method('generate')
            ->willThrowException(new ProviderException('Anthropic API error', 'anthropic'));

        $response = $this->controller->generate(
            $this->makeRequest(['prompt' => 'sunset', 'type' => 'image']),
            $this->createUser(),
        );

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('Anthropic API error', $data['error']);
    }

    public function testRuntimeExceptionReturns500WithoutDoublePrefix(): void
    {
        $this->mediaService->method('generate')
            ->willThrowException(new \RuntimeException('Provider returned no media'));

        $response = $this->controller->generate(
            $this->makeRequest(['prompt' => 'sunset', 'type' => 'image']),
            $this->createUser(),
        );

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('Provider returned no media', $data['error']);
        self::assertStringNotContainsString('Media generation failed:', $data['error']);
    }

    public function testSuccessfulGenerationReturns200(): void
    {
        $this->mediaService->method('generate')->willReturn([
            'success' => true,
            'file' => [
                'url' => '/api/v1/files/uploads/01/000/00001/2026/03/media_1_openai_1709000000.png',
                'type' => 'image',
                'mimeType' => 'image/png',
            ],
            'provider' => 'openai',
            'model' => 'dall-e-3',
        ]);

        $response = $this->controller->generate(
            $this->makeRequest(['prompt' => 'sunset', 'type' => 'image']),
            $this->createUser(),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame('openai', $data['provider']);
        self::assertSame('image/png', $data['file']['mimeType']);
    }

    // ==================== Pic2pic Tests ====================

    private function makePic2picRequest(string $prompt, array $files = [], ?int $modelId = null): Request
    {
        $request = Request::create(
            '/api/v1/media/generate-from-images',
            'POST',
        );
        $request->request->set('prompt', $prompt);
        if (null !== $modelId) {
            $request->request->set('modelId', (string) $modelId);
        }
        foreach ($files as $key => $file) {
            $request->files->set($key, $file);
        }

        return $request;
    }

    public function testPic2picUnauthenticatedReturns401(): void
    {
        $request = $this->makePic2picRequest('combine images');
        $response = $this->controller->generateFromImages($request, null);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testPic2picInvalidInputReturns400(): void
    {
        $this->mediaService->method('generateFromImages')
            ->willThrowException(new \InvalidArgumentException('Prompt is required'));

        $request = $this->makePic2picRequest('');
        $response = $this->controller->generateFromImages($request, $this->createUser());

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('Prompt is required', $data['error']);
    }

    public function testPic2picRateLimitReturns429(): void
    {
        $this->mediaService->method('generateFromImages')
            ->willThrowException(new RateLimitExceededException('IMAGES', 10, 10));

        $request = $this->makePic2picRequest('combine');
        $response = $this->controller->generateFromImages($request, $this->createUser());

        self::assertSame(429, $response->getStatusCode());
    }

    public function testPic2picNoModelReturns422(): void
    {
        $this->mediaService->method('generateFromImages')
            ->willThrowException(new NoModelAvailableException('No model available'));

        $request = $this->makePic2picRequest('combine');
        $response = $this->controller->generateFromImages($request, $this->createUser());

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPic2picSuccessReturns200(): void
    {
        $this->mediaService->method('generateFromImages')->willReturn([
            'success' => true,
            'file' => [
                'url' => '/api/v1/files/uploads/01/000/00001/2026/03/media_1_openai_1709000000.png',
                'type' => 'image',
                'mimeType' => 'image/png',
            ],
            'provider' => 'openai',
            'model' => 'gpt-image-1.5',
        ]);

        $request = $this->makePic2picRequest('put object from image 1 into scene');
        $response = $this->controller->generateFromImages($request, $this->createUser());

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame('openai', $data['provider']);
        self::assertSame('gpt-image-1.5', $data['model']);
    }

    public function testPic2picProviderErrorReturns500(): void
    {
        $this->mediaService->method('generateFromImages')
            ->willThrowException(new ProviderException('Responses API error', 'openai'));

        $request = $this->makePic2picRequest('combine');
        $response = $this->controller->generateFromImages($request, $this->createUser());

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('Responses API error', $data['error']);
    }
}
