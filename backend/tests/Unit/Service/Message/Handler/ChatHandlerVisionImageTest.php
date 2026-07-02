<?php

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #1238 — a re-attached generated PNG was embedded into the Anthropic
 * messages API full-size as a base64 data URL, blowing the prompt to ~1.9M
 * tokens (HTTP 400). The vision embedder must downscale oversized images so
 * the inline payload stays within budget, and skip the image entirely if it
 * still cannot be reduced enough — never send a payload that 400s.
 */
class ChatHandlerVisionImageTest extends TestCase
{
    /** Mirrors ChatHandler::MAX_VISION_BASE64_LENGTH. */
    private const MAX_VISION_BASE64_LENGTH = 450000;

    private ChatHandler $handler;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/synaplan-chat-vision-'.bin2hex(random_bytes(8));
        mkdir($this->uploadDir, 0o775, true);

        $this->handler = new ChatHandler(
            $this->createMock(AiFacade::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(PromptService::class),
            $this->createMock(ModelConfigService::class),
            $this->createMock(ModelRepository::class),
            new NullLogger(),
            $this->createMock(VectorSearchService::class),
            $this->createMock(EntityManagerInterface::class),
            $this->uploadDir,
            new UserUploadPathBuilder(),
            $this->createMock(UserMemoryService::class),
            new FeedbackConfigService($this->createStub(ConfigRepository::class)),
            $this->createMock(RateLimitService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(DocumentGeneratorService::class),
            new TimeContextBuilder(),
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            $this->removeDirectoryRecursive($this->uploadDir);
        }
    }

    /**
     * A small image comfortably under budget must be embedded verbatim,
     * preserving its original PNG mime type.
     */
    public function testSmallImageIsEmbeddedUnchanged(): void
    {
        // 1x1 transparent PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($this->uploadDir.'/tiny.png', $png);

        $result = $this->invokeImageToBase64DataUrl('tiny.png');

        $this->assertNotNull($result);
        $dataUrl = (string) $result;
        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
        $this->assertLessThanOrEqual(self::MAX_VISION_BASE64_LENGTH, strlen($this->base64Payload($dataUrl)));
    }

    /**
     * The core #1238 regression: an oversized image must be downscaled and
     * re-encoded (as JPEG) so its base64 payload lands within budget instead
     * of being sent full-size and triggering an HTTP 400.
     */
    public function testOversizedImageIsDownscaledUnderBudget(): void
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            $this->markTestSkipped('imagick is required to downscale oversized vision images');
        }

        $bigPng = $this->makeLargePng(1500, 1500);
        $originalBase64Length = intdiv(strlen($bigPng) + 2, 3) * 4;

        // The fixture must land in the testable window: large enough to exceed
        // the inline budget (so the downscale path runs), but under the 10 MB
        // file-size guard that short-circuits before downscaling. ImageMagick
        // builds compress plasma differently, so skip rather than fail if this
        // particular environment produced an out-of-range fixture.
        if ($originalBase64Length <= self::MAX_VISION_BASE64_LENGTH) {
            $this->markTestSkipped('Generated fixture compressed below the inline budget on this ImageMagick build');
        }
        if (strlen($bigPng) > 10 * 1024 * 1024) {
            $this->markTestSkipped('Generated fixture exceeded the 10 MB pre-downscale size guard on this ImageMagick build');
        }

        file_put_contents($this->uploadDir.'/big.png', $bigPng);

        $result = $this->invokeImageToBase64DataUrl('big.png');

        $this->assertNotNull($result, 'Oversized image should be downscaled, not skipped');
        $dataUrl = (string) $result;
        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUrl);
        $this->assertLessThanOrEqual(
            self::MAX_VISION_BASE64_LENGTH,
            strlen($this->base64Payload($dataUrl)),
            'Downscaled payload must fit within the inline vision budget',
        );
    }

    /**
     * When the image cannot be decoded/downscaled (corrupt bytes that still
     * exceed the budget), the embedder must skip it (return null) rather than
     * forward an oversized payload that 400s the provider request.
     */
    public function testOversizedUndecodableImageIsSkipped(): void
    {
        // > budget worth of bytes that are not a valid image. Imagick cannot
        // decode this, so no downscaled result can be produced.
        $garbage = str_repeat("\x00\x11\x22\x33", 400000);
        file_put_contents($this->uploadDir.'/broken.png', $garbage);

        $result = $this->invokeImageToBase64DataUrl('broken.png');

        $this->assertNull($result);
    }

    private function invokeImageToBase64DataUrl(string $relativePath): ?string
    {
        $method = new \ReflectionMethod(ChatHandler::class, 'imageToBase64DataUrl');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, $relativePath);

        return null === $result ? null : (string) $result;
    }

    private function base64Payload(string $dataUrl): string
    {
        $commaPos = strpos($dataUrl, ',');

        return false === $commaPos ? '' : substr($dataUrl, $commaPos + 1);
    }

    /**
     * Build a large, poorly-compressible PNG so its base64 payload exceeds the
     * inline vision budget.
     */
    private function makeLargePng(int $width, int $height): string
    {
        $imagick = new \Imagick();
        // A fractal plasma render is high-frequency/high-entropy, so it stays
        // large even as PNG without ballooning past the 10 MB size guard.
        $imagick->newPseudoImage($width, $height, 'plasma:fractal');
        $imagick->setImageFormat('png');
        $blob = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        return $blob;
    }

    private function removeDirectoryRecursive(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
