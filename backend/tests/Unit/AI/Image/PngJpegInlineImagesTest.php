<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Image;

use App\AI\Image\PngJpegInlineImages;
use App\AI\Image\UnsupportedImageInputException;
use PHPUnit\Framework\TestCase;

final class PngJpegInlineImagesTest extends TestCase
{
    private const ONE_PIXEL_GIF = 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testPngAndJpegPassThroughUnchanged(): void
    {
        $png = 'data:image/png;base64,'.self::ONE_PIXEL_PNG;
        $jpeg = 'data:image/jpeg;base64,'.self::ONE_PIXEL_PNG;

        $this->assertSame($png, PngJpegInlineImages::toDataUrl($png, 'Cerebras'));
        $this->assertSame($jpeg, PngJpegInlineImages::toDataUrl($jpeg, 'Cerebras'));
    }

    public function testGifIsTranscodedToPng(): void
    {
        $this->requireImagick();

        $converted = PngJpegInlineImages::toDataUrl('data:image/gif;base64,'.self::ONE_PIXEL_GIF, 'Cerebras');

        $this->assertStringStartsWith('data:image/png;base64,', $converted);
        $bytes = base64_decode(substr($converted, strlen('data:image/png;base64,')), true);
        $this->assertIsString($bytes);
        $this->assertStringStartsWith("\x89PNG", $bytes);
    }

    public function testWebpIsTranscodedToPng(): void
    {
        $this->requireImagick();
        $webp = new \Imagick();
        $webp->newImage(2, 2, new \ImagickPixel('red'));
        $webp->setImageFormat('webp');

        $converted = PngJpegInlineImages::toDataUrl('data:image/webp;base64,'.base64_encode($webp->getImageBlob()), 'Cerebras');

        $this->assertStringStartsWith('data:image/png;base64,', $converted);
    }

    public function testLinksAreRejectedInsteadOfFetched(): void
    {
        $this->expectException(UnsupportedImageInputException::class);
        $this->expectExceptionMessage('Cerebras accepts images only as uploaded PNG or JPEG files, not as links.');

        PngJpegInlineImages::toDataUrl('https://example.test/cat.png', 'Cerebras');
    }

    public function testUndecodableImagesAreRejectedWithARecovery(): void
    {
        $this->expectException(UnsupportedImageInputException::class);
        $this->expectExceptionMessage('Save it as PNG or JPEG and upload it again.');

        PngJpegInlineImages::toDataUrl('data:image/webp;base64,bm90LWFuLWltYWdl', 'Cerebras');
    }

    public function testMessagesKeepTextAndLoseTheDetailField(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'What is this?'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::ONE_PIXEL_PNG, 'detail' => 'high']],
            ]],
        ];

        $normalized = PngJpegInlineImages::normalizeMessages($messages, 'Cerebras');

        $this->assertSame('Be brief.', $normalized[0]['content']);
        $this->assertSame(['type' => 'text', 'text' => 'What is this?'], $normalized[1]['content'][0]);
        $this->assertSame(['url' => 'data:image/png;base64,'.self::ONE_PIXEL_PNG], $normalized[1]['content'][1]['image_url']);
    }

    private function requireImagick(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick is required for transcoding');
        }
    }
}
