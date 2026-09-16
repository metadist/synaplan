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

        if (!$this->pdftoppmAvailable()) {
            self::markTestSkipped('pdftoppm is required to assert the blocked-Imagick fallback');
        }

        $rasterizer = new PdfRasterizer($logger, $dir, 72, 1, 5000, false);
        $images = $rasterizer->pdfToPng($pdf);

        self::assertNotSame([], $images);
        self::assertSame('pdftoppm', $rasterizer->getLastEngine());
        self::assertFileExists($images[0]);
        self::assertGreaterThan(0, (int) filesize($images[0]));
        foreach ($logger->warnings as $warning) {
            self::assertStringNotContainsString('Imagick failed', $warning);
        }

        foreach (glob($dir.'/*') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($dir);
    }

    public function testProbePdfIsStructurallyComplete(): void
    {
        $method = new \ReflectionMethod(PdfRasterizer::class, 'minimalValidPdf');
        $pdf = $method->invoke(null);
        self::assertIsString($pdf);
        self::assertStringContainsString("xref\n", $pdf);
        self::assertStringContainsString("startxref\n", $pdf);

        $marker = "startxref\n";
        $offsetStart = strpos($pdf, $marker);
        self::assertNotFalse($offsetStart);
        $xrefOffset = (int) substr($pdf, $offsetStart + strlen($marker));
        self::assertSame('xref', substr($pdf, $xrefOffset, 4));
    }

    public function testProbeIsCachedAcrossInstances(): void
    {
        PdfRasterizer::resetImagickPdfProbeForTests();
        self::assertSame(0, PdfRasterizer::imagickPdfProbeCallCountForTests());

        $first = $this->rasterizer();
        $allowed = $first->willAttemptImagick();
        self::assertSame(1, PdfRasterizer::imagickPdfProbeCallCountForTests());

        $second = $this->rasterizer();
        self::assertSame($allowed, $second->willAttemptImagick());
        self::assertSame(1, PdfRasterizer::imagickPdfProbeCallCountForTests());
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

    private function pdftoppmAvailable(): bool
    {
        $path = trim((string) shell_exec('command -v pdftoppm 2>/dev/null'));

        return '' !== $path;
    }
}
