<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Exception\ChatFailureReason;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Service\AiFacade;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ChatErrorView;
use App\Service\Security\SsrfGuard;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnalyzeImageToolTest extends TestCase
{
    public function testDeclarationUsesStableName(): void
    {
        $tool = $this->tool(available: true);
        $declaration = $tool->declaration();

        self::assertSame(AnalyzeImageTool::NAME, $declaration['name']);
        self::assertArrayHasKey('image_url', $declaration['input_schema']['properties']);
        self::assertArrayHasKey('image_base64', $declaration['input_schema']['properties']);
    }

    public function testExecuteRequiresImageInput(): void
    {
        $tool = $this->tool(available: true);
        $result = $tool->execute(['prompt' => 'describe'], 1);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('image_url', $result['text']);
    }

    public function testExecuteReportsMissingVisionModel(): void
    {
        $tool = $this->tool(available: false);
        $result = $tool->execute([
            'image_base64' => base64_encode('not-an-image'),
            'media_type' => 'image/png',
        ], 1);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('No Synaplan vision model', $result['text']);
    }

    public function testExecuteAnalysesBase64ViaAiFacade(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertNotFalse($png);

        $uploadDir = sys_get_temp_dir().'/synaplan-analyze-image-'.bin2hex(random_bytes(4));
        mkdir($uploadDir);

        $ai = $this->createMock(AiFacade::class);
        $ai->expects($this->once())
            ->method('analyzeImage')
            ->with($this->stringContains('gateway-vision/'), 'What is on this page?', 9)
            ->willReturn(['content' => 'a red pixel', 'provider' => 'openai']);

        $vision = $this->createMock(VisionModelResolver::class);
        $vision->method('isAvailable')->willReturn(true);

        $tool = new AnalyzeImageTool(
            $ai,
            $vision,
            $this->createMock(SsrfGuard::class),
            $this->httpClient(),
            new NullLogger(),
            $uploadDir,
        );

        $result = $tool->execute([
            'prompt' => 'What is on this page?',
            'image_base64' => base64_encode($png),
            'media_type' => 'image/png',
        ], 9);

        self::assertFalse($result['isError']);
        self::assertSame('a red pixel', $result['text']);
        self::assertSame([], glob($uploadDir.'/gateway-vision/*') ?: []);

        @rmdir($uploadDir.'/gateway-vision');
        @rmdir($uploadDir);
    }

    private function tool(
        bool $available,
        ?SsrfGuard $guard = null,
        ?HttpClientInterface $http = null,
        ?AiFacade $ai = null,
        ?string $uploadDir = null,
    ): AnalyzeImageTool {
        $vision = $this->createMock(VisionModelResolver::class);
        $vision->method('isAvailable')->willReturn($available);

        return new AnalyzeImageTool(
            $ai ?? $this->createMock(AiFacade::class),
            $vision,
            $guard ?? $this->createMock(SsrfGuard::class),
            $http ?? $this->httpClient(),
            new NullLogger(),
            $uploadDir ?? sys_get_temp_dir(),
        );
    }

    private function httpClient(): HttpClientInterface
    {
        return new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('No image URL was provided.');
        });
    }

    /**
     * Issue #1074 residual: a failing vision call must surface the presented
     * reason, never the raw provider message.
     */
    public function testExecutePresentsFailureWithoutRawMessage(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->method('analyzeImage')->willThrowException(new \RuntimeException('context_length_exceeded: too many tokens'));

        $vision = $this->createMock(VisionModelResolver::class);
        $vision->method('isAvailable')->willReturn(true);

        $presenter = $this->createMock(ChatErrorPresenter::class);
        $presenter->expects(self::once())->method('present')->with(
            self::isInstanceOf(\RuntimeException::class),
            'de',
        )->willReturn(new ChatErrorView(
            ChatFailureReason::ContextLengthExceeded,
            'That image is too large to analyse.',
            null,
            false,
            'context_length_exceeded: too many tokens',
        ));

        $tool = new AnalyzeImageTool(
            $ai,
            $vision,
            $this->createMock(SsrfGuard::class),
            $this->httpClient(),
            new NullLogger(),
            sys_get_temp_dir(),
            $presenter,
        );

        $result = $tool->execute([
            'prompt' => 'What is on this page?',
            'image_base64' => base64_encode('fake-png-bytes'),
            'media_type' => 'image/png',
        ], 9, 'de');

        self::assertTrue($result['isError']);
        self::assertSame('That image is too large to analyse.', $result['text']);

        @rmdir(sys_get_temp_dir().'/gateway-vision');
    }

    public function testDownloadConnectsToThePinnedAddress(): void
    {
        $png = $this->pngBytes();
        $uploadDir = $this->uploadDir();
        $guard = $this->createMock(SsrfGuard::class);
        $guard->expects(self::once())->method('isBlockedUrl')->with('https://cdn.example/a.png')->willReturn(false);
        $guard->expects(self::once())->method('pinnedIps')->with('cdn.example')->willReturn(['203.0.113.10', '203.0.113.11']);

        $seen = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen, $png): MockResponse {
            $seen = [$method, $url, $options];

            return new MockResponse($png, ['http_code' => 200]);
        });
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::once())->method('analyzeImage')->willReturn(['content' => 'a pixel', 'provider' => 'openai']);

        $result = $this->tool(true, $guard, $http, $ai, $uploadDir)->execute([
            'image_url' => 'https://cdn.example/a.png',
        ], 4);

        self::assertFalse($result['isError']);
        self::assertSame('a pixel', $result['text']);
        self::assertNotNull($seen);
        self::assertSame(['GET', 'https://cdn.example/a.png'], [$seen[0], $seen[1]]);
        self::assertSame(['cdn.example' => '203.0.113.10'], $seen[2]['resolve']);
        self::assertSame(0, $seen[2]['max_redirects']);
        self::assertTrue($seen[2]['verify_peer']);
        self::assertTrue($seen[2]['verify_host']);
        $this->removeUploadDir($uploadDir);
    }

    public function testDownloadRefusesWhenThePinIsEmpty(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->method('isBlockedUrl')->willReturn(false);
        $guard->expects(self::once())->method('pinnedIps')->with('cdn.example')->willReturn([]);
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('The download must not run when no public address is pinned.');
        });
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('analyzeImage');

        $result = $this->tool(true, $guard, $http, $ai)->execute([
            'image_url' => 'https://cdn.example/a.png',
        ], 4);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('blocked host', $result['text']);
    }

    public function testDownloadRefusesABlockedUrlWithoutFetching(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->expects(self::once())->method('isBlockedUrl')->willReturn(true);
        $guard->expects(self::never())->method('pinnedIps');
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('The download must not run for a blocked URL.');
        });

        $result = $this->tool(true, $guard, $http)->execute([
            'image_url' => 'https://cdn.example/a.png',
        ], 4);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('blocked host', $result['text']);
    }

    public function testDownloadRefusesPlainHttpWithoutFetching(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->expects(self::never())->method('isBlockedUrl');
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('Plain HTTP must not be fetched.');
        });

        $result = $this->tool(true, $guard, $http)->execute([
            'image_url' => 'http://cdn.example/a.png',
        ], 4);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('HTTPS', $result['text']);
    }

    public function testDownloadDoesNotFollowARedirect(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->method('isBlockedUrl')->willReturn(false);
        $guard->method('pinnedIps')->willReturn(['203.0.113.10']);
        $http = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['location: https://127.0.0.1/secret.png'],
            ]);
        });
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('analyzeImage');

        $result = $this->tool(true, $guard, $http, $ai)->execute([
            'image_url' => 'https://cdn.example/a.png',
        ], 4);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Failed to download', $result['text']);
    }

    public function testDownloadRejectsANonImageBody(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->method('isBlockedUrl')->willReturn(false);
        $guard->method('pinnedIps')->willReturn(['203.0.113.10']);
        $http = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('{"secret":true}', ['http_code' => 200]);
        });
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('analyzeImage');

        $result = $this->tool(true, $guard, $http, $ai)->execute([
            'image_url' => 'https://cdn.example/a.png',
        ], 4);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('not a supported image type', $result['text']);
    }

    public function testLiteralPublicAddressIsNotResolvedAgain(): void
    {
        $png = $this->pngBytes();
        $uploadDir = $this->uploadDir();
        $guard = $this->createMock(SsrfGuard::class);
        $guard->method('isBlockedUrl')->willReturn(false);
        $guard->expects(self::never())->method('pinnedIps');

        $seenResolve = 'unset';
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenResolve, $png): MockResponse {
            $seenResolve = $options['resolve'] ?? null;

            return new MockResponse($png, ['http_code' => 200]);
        });
        $ai = $this->createMock(AiFacade::class);
        $ai->method('analyzeImage')->willReturn(['content' => 'a pixel', 'provider' => 'openai']);

        $result = $this->tool(true, $guard, $http, $ai, $uploadDir)->execute([
            'image_url' => 'https://203.0.113.10/a.png',
        ], 4);

        self::assertFalse($result['isError'], $result['text']);
        self::assertNull($seenResolve);
        $this->removeUploadDir($uploadDir);
    }

    private function pngBytes(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertNotFalse($png);

        return $png;
    }

    private function uploadDir(): string
    {
        $uploadDir = sys_get_temp_dir().'/synaplan-analyze-image-'.bin2hex(random_bytes(4));
        mkdir($uploadDir);

        return $uploadDir;
    }

    private function removeUploadDir(string $uploadDir): void
    {
        foreach (glob($uploadDir.'/gateway-vision/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($uploadDir.'/gateway-vision');
        @rmdir($uploadDir);
    }
}
