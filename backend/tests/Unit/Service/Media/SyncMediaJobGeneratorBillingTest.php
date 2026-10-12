<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\MediaJob;
use App\Service\Media\SyncMediaJobGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An async edit is billed when the job completes, from the usage payload
 * stashed at detach time. The model picks the size of an edit, so the size
 * it rendered must replace the requested one (#2404).
 */
final class SyncMediaJobGeneratorBillingTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/sync-media-billing-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->uploadDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->uploadDir);
    }

    public function testARenderedLandscapeEditIsBilledAtTheLandscapeSize(): void
    {
        $job = $this->imageJob();

        $this->generator(['url' => 'data:image/png;base64,'.self::PNG, 'size' => '1536x1024'])->generate($job);

        self::assertSame(
            ['images' => 1, 'quality' => 'high', 'size' => '1536x1024'],
            $job->getOptions()['media_usage'],
        );
    }

    public function testWithoutARenderedSizeTheRequestedSizeIsBilled(): void
    {
        $job = $this->imageJob();

        $this->generator(['url' => 'data:image/png;base64,'.self::PNG])->generate($job);

        self::assertSame('1024x1024', $job->getOptions()['media_usage']['size']);
    }

    private function imageJob(): MediaJob
    {
        $job = new MediaJob();
        $job->setUserId(7);
        $job->setType(MediaJob::TYPE_IMAGE);
        $job->setProvider('openai');
        $job->setModel('gpt-image-1.5');
        $job->setPrompt('Add muntins to the left window');
        $job->setOptions([
            'quality' => 'high',
            'size' => '1024x1024',
            'images' => ['/tmp/photo.jpg'],
            'media_usage' => ['images' => 1, 'quality' => 'high', 'size' => '1024x1024'],
        ]);

        return $job;
    }

    /**
     * @param array<string, mixed> $image
     */
    private function generator(array $image): SyncMediaJobGenerator
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('generateImage')->willReturn([
            'images' => [$image],
            'provider' => 'openai',
            'model' => 'gpt-image-1.5',
            'image_count' => 1,
        ]);

        return new SyncMediaJobGenerator(
            $aiFacade,
            new UserUploadPathBuilder(),
            $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            $this->uploadDir,
        );
    }
}
