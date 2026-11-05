<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\XmpDigitalSource;
use PHPUnit\Framework\TestCase;

final class XmpDigitalSourceTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testPngGainsTheSourceTypeAndKeepsItsHeader(): void
    {
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);
        $marked = (new XmpDigitalSource())->embed($bytes);

        self::assertIsString($marked);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $marked);
        self::assertStringContainsString('trainedAlgorithmicMedia', $marked);
        self::assertStringContainsString('IHDR', $marked);
    }

    public function testAlreadyMarkedBytesStayByteIdentical(): void
    {
        $bytes = base64_decode(self::PNG, true).'c2pa';
        self::assertNull((new XmpDigitalSource())->embed($bytes));
    }
}
