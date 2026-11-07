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

    public function testPngInsertsBeforeTheRealEndChunkWhenIdatContainsTheMarker(): void
    {
        $idat = $this->chunk('IDAT', 'xxxxIEND-inside');
        $iend = $this->chunk('IEND', '');
        $png = "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', 'ihdr').$idat.$iend;

        $marked = (new XmpDigitalSource())->embed($png);

        self::assertIsString($marked);
        self::assertStringEndsWith($iend, $marked);
        self::assertStringContainsString('trainedAlgorithmicMedia', $marked);
        $decoyAt = strpos($marked, 'xxxxIEND-inside');
        $packetAt = strpos($marked, 'trainedAlgorithmicMedia');
        self::assertNotFalse($decoyAt);
        self::assertNotFalse($packetAt);
        self::assertLessThan($packetAt, $decoyAt);
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', 0);
    }
}
