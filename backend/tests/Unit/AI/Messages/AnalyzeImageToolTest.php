<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Service\AiFacade;
use App\Service\Security\SsrfGuard;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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

    private function tool(bool $available): AnalyzeImageTool
    {
        $vision = $this->createMock(VisionModelResolver::class);
        $vision->method('isAvailable')->willReturn($available);

        return new AnalyzeImageTool(
            $this->createMock(AiFacade::class),
            $vision,
            $this->createMock(SsrfGuard::class),
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }
}
