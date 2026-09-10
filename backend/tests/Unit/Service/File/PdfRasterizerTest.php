<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\PdfRasterizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Issue #1794 — do not attempt Imagick when the PDF coder is blocked.
 */
final class PdfRasterizerTest extends TestCase
{
    protected function tearDown(): void
    {
        PdfRasterizer::resetImagickPdfProbeForTests();
    }

    public function testOverrideFalseDoesNotAttemptImagick(): void
    {
        $rasterizer = $this->rasterizer(imagickPdfAllowed: false);

        self::assertFalse($rasterizer->willAttemptImagick());
    }

    public function testOverrideTrueAttemptsImagickWhenExtensionExists(): void
    {
        $rasterizer = $this->rasterizer(imagickPdfAllowed: true);

        self::assertTrue($rasterizer->willAttemptImagick());
    }

    public function testBlockedImagickPathDoesNotLogImagickFailedWarning(): void
    {
        $dir = sys_get_temp_dir().'/rasterizer-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0775, true) || is_dir($dir));
        $pdf = $dir.'/sample.pdf';
        copy($this->tinyPdf(), $pdf);

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            /**
             * @param string       $message
             * @param array<mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $rasterizer = new PdfRasterizer($logger, $dir, 72, 1, 5000, false);
        $rasterizer->pdfToPng($pdf);

        self::assertNotSame('imagick', $rasterizer->getLastEngine());
        foreach ($logger->warnings as $warning) {
            self::assertStringNotContainsString('Imagick failed', $warning);
        }

        foreach (glob($dir.'/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($dir);
    }

    public function testProbeIsCachedAcrossInstances(): void
    {
        PdfRasterizer::resetImagickPdfProbeForTests();
        $first = $this->rasterizer();
        $allowed = $first->willAttemptImagick();
        $second = $this->rasterizer();

        self::assertSame($allowed, $second->willAttemptImagick());
    }

    private function rasterizer(?bool $imagickPdfAllowed = null): PdfRasterizer
    {
        return new PdfRasterizer(
            new NullLogger(),
            sys_get_temp_dir(),
            72,
            1,
            5000,
            $imagickPdfAllowed,
        );
    }

    private function tinyPdf(): string
    {
        $path = dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf';
        self::assertFileExists($path);

        return $path;
    }
}
