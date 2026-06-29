<?php

declare(strict_types=1);

namespace App\Tests\Service\File;

use App\Service\File\HeicConverter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HeicConverterTest extends TestCase
{
    use HeicTestSupportTrait;

    private HeicConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new HeicConverter(new NullLogger());
    }

    public function testIsHeicByExtension(): void
    {
        $this->assertTrue($this->converter->isHeic('heic'));
        $this->assertTrue($this->converter->isHeic('HEIC'));
        $this->assertTrue($this->converter->isHeic('heif'));
        $this->assertFalse($this->converter->isHeic('jpg'));
        $this->assertFalse($this->converter->isHeic('png'));
    }

    public function testIsHeicByMime(): void
    {
        $this->assertTrue($this->converter->isHeic('', 'image/heic'));
        $this->assertTrue($this->converter->isHeic('bin', 'image/heif'));
        $this->assertFalse($this->converter->isHeic('bin', 'image/jpeg'));
    }

    public function testConvertBlobToJpegProducesJpeg(): void
    {
        $heicBytes = $this->createHeicSampleOrSkip();
        $this->skipUnlessEnvironmentDecodesHeic($heicBytes);

        $jpeg = $this->converter->convertBlobToJpeg($heicBytes);

        $this->assertNotNull($jpeg);
        // JPEG SOI marker.
        $this->assertSame("\xFF\xD8", substr($jpeg, 0, 2));
    }

    public function testConvertBlobToJpegReturnsNullForGarbage(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick is required for this test');
        }

        $this->assertNull($this->converter->convertBlobToJpeg('not-an-image'));
    }
}
