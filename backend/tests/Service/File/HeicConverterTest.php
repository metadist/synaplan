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

    public function testAnSvgNamedHeicIsRefusedBeforeItIsDecoded(): void
    {
        $error = null;

        $this->assertNull($this->converter->convertBlobToJpeg($this->svgReadingALocalFile(), $error));
        $this->assertSame(HeicConverter::NOT_HEIC, $error);
    }

    public function testAnSvgFileNamedHeicIsNotConverted(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'heicsvg_').'.heic';
        $target = $source.'.jpg';
        file_put_contents($source, $this->svgReadingALocalFile());

        try {
            $this->assertFalse($this->converter->convertFileToJpeg($source, $target));
            $this->assertFileDoesNotExist($target);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    public function testAHeicContainerWithoutAPictureIsNotReadByAnotherCoder(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick is required for this test');
        }

        // Passes the container check, cannot be decoded as HEIC, and must not
        // fall through to a coder chosen from the rest of the content.
        $bytes = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".$this->svgReadingALocalFile();
        $error = null;

        $this->assertNull($this->converter->convertBlobToJpeg($bytes, $error));
        $this->assertNotSame(HeicConverter::NOT_HEIC, $error);
    }

    private function svgReadingALocalFile(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
            .' width="32" height="32"><image width="32" height="32" xlink:href="'.__FILE__.'"/></svg>';
    }
}
