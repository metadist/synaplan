<?php

namespace App\Service\File;

use Psr\Log\LoggerInterface;

/**
 * PDF Rasterizer Service.
 *
 * Converts PDF pages to PNG images. Imagick is used only when the PDF coder
 * is actually permitted; the shipped ImageMagick policy sets
 * `rights="none"` for PDF, so we skip that engine after a one-time probe
 * and go straight to pdftoppm (issue #1794).
 */
final class PdfRasterizer
{
    private static ?bool $cachedImagickPdfAllowed = null;

    private static int $imagickPdfProbeCalls = 0;

    private string $lastEngine = '';
    private int $lastDpi = 0;
    private int $lastPages = 0;

    /**
     * @param bool|null $imagickPdfAllowed When non-null, skip the process-wide
     *                                     policy probe (tests). Null = detect.
     */
    public function __construct(
        private LoggerInterface $logger,
        private string $uploadDir,
        private int $rasterizeDpi,
        private int $rasterizePageCap,
        private int $rasterizeTimeoutMs,
        private ?bool $imagickPdfAllowed = null,
    ) {
    }

    /**
     * Reset the process-wide Imagick-PDF probe. Tests only.
     */
    public static function resetImagickPdfProbeForTests(): void
    {
        self::$cachedImagickPdfAllowed = null;
        self::$imagickPdfProbeCalls = 0;
    }

    /**
     * How many times this process ran the Imagick-PDF probe. Tests only.
     */
    public static function imagickPdfProbeCallCountForTests(): int
    {
        return self::$imagickPdfProbeCalls;
    }

    /**
     * Whether this process will attempt Imagick for PDF reads.
     */
    public function willAttemptImagick(): bool
    {
        return $this->imagickCanReadPdf();
    }

    /**
     * Convert PDF pages to PNG images.
     *
     * @param string   $absolutePdfPath Absolute path to the PDF file
     * @param int|null $pageCap         Override the configured page cap (e.g. 1 for a poster)
     *
     * @return array<int, string> Absolute paths to the generated PNG files
     */
    public function pdfToPng(string $absolutePdfPath, ?int $pageCap = null): array
    {
        $cap = max(1, $pageCap ?? $this->rasterizePageCap);

        if (!is_file($absolutePdfPath) || 0 === filesize($absolutePdfPath)) {
            $this->logger->warning('Rasterizer: PDF file missing or empty', ['file' => $absolutePdfPath]);

            return [];
        }

        $targetDir = $this->resolveTargetDir($absolutePdfPath);
        $basename = pathinfo($absolutePdfPath, PATHINFO_FILENAME);
        $images = [];

        // Imagick only when the PDF coder is permitted. The shipped policy
        // denies it; probing once avoids a warning on every scanned PDF.
        if ($this->imagickCanReadPdf()) {
            $images = $this->pdfToPngViaImagick($absolutePdfPath, $targetDir, $basename, $cap);
            if (!empty($images)) {
                return $images;
            }
        }

        // Fallback to pdftoppm
        $images = $this->pdfToPngViaPdftoppm($absolutePdfPath, $targetDir, $basename, $cap);

        if (!empty($images)) {
            $this->lastEngine = 'pdftoppm';
            $this->lastDpi = $this->rasterizeDpi;
            $this->lastPages = count($images);

            $this->logger->info('Rasterizer success with pdftoppm', [
                'engine' => 'pdftoppm',
                'pages' => $this->lastPages,
                'dpi' => $this->lastDpi,
            ]);
        }

        return $images;
    }

    private function imagickCanReadPdf(): bool
    {
        if (null !== $this->imagickPdfAllowed) {
            return $this->imagickPdfAllowed;
        }

        if (null !== self::$cachedImagickPdfAllowed) {
            return self::$cachedImagickPdfAllowed;
        }

        self::$cachedImagickPdfAllowed = $this->probeImagickPdfPolicy();

        return self::$cachedImagickPdfAllowed;
    }

    private function probeImagickPdfPolicy(): bool
    {
        ++self::$imagickPdfProbeCalls;

        if (!class_exists(\Imagick::class)) {
            return false;
        }

        if (\is_callable([\Imagick::class, 'getPolicy'])) {
            try {
                $rights = strtolower(trim((string) \Imagick::getPolicy('coder', 'PDF')));
                if ('none' === $rights) {
                    $this->logger->info('Rasterizer: skipping Imagick for PDF (coder policy is none)');

                    return false;
                }
                if ('' !== $rights) {
                    // Coder is explicitly permitted. Do not let a probe blob
                    // override that and disable Imagick for the whole process.
                    return true;
                }
            } catch (\Throwable) {
                // Policy query unsupported or unset — fall through to a blob probe.
            }
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(2, 2);
            $imagick->readImageBlob(self::minimalValidPdf());
            $imagick->clear();
            $imagick->destroy();

            return true;
        } catch (\Throwable $e) {
            $this->logger->info('Rasterizer: skipping Imagick for PDF', [
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * One-page 3×3 PDF with a real xref/startxref so a policy-allowed Imagick
     * does not reject the probe as malformed and cache "cannot read PDF".
     */
    private static function minimalValidPdf(): string
    {
        $objects = [
            '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
            '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
            '3 0 obj<</Type/Page/MediaBox[0 0 3 3]/Parent 2 0 R>>endobj',
        ];

        $body = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($body);
            $body .= $object."\n";
        }

        $xrefPos = strlen($body);
        $xref = "xref\n0 4\n".sprintf("%010d 65535 f \n", 0);
        foreach ([1, 2, 3] as $id) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $body.$xref."trailer<</Size 4/Root 1 0 R>>\nstartxref\n".$xrefPos."\n%%EOF\n";
    }

    /**
     * @return list<string>
     */
    private function pdfToPngViaImagick(string $absolutePdfPath, string $targetDir, string $basename, int $pageCap): array
    {
        try {
            $imagick = new \Imagick();
            $imagick->setResolution($this->rasterizeDpi, $this->rasterizeDpi);
            $imagick->readImage($absolutePdfPath);
            $pages = min($pageCap, $imagick->getNumberImages());
            $imagick->setIteratorIndex(0);

            $images = [];
            for ($i = 0; $i < $pages; ++$i) {
                $imagick->setIteratorIndex($i);
                $imagick->setImageFormat('png');
                $outputPath = $targetDir.'/'.$basename.'-'.($i + 1).'.png';

                if ($imagick->writeImage($outputPath)) {
                    if (is_file($outputPath) && filesize($outputPath) > 0) {
                        $images[] = $outputPath;
                    } else {
                        $this->logger->warning('Rasterizer Imagick: write failed', ['output' => $outputPath]);
                    }
                }
            }

            $imagick->clear();
            $imagick->destroy();

            if ([] !== $images) {
                $this->lastEngine = 'imagick';
                $this->lastDpi = $this->rasterizeDpi;
                $this->lastPages = count($images);

                $this->logger->info('Rasterizer success with Imagick', [
                    'engine' => 'imagick',
                    'pages' => $this->lastPages,
                    'dpi' => $this->lastDpi,
                ]);
            }

            return $images;
        } catch (\Throwable $e) {
            $this->logger->warning('Rasterizer Imagick failed, falling back to pdftoppm', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Fallback method using pdftoppm command-line tool.
     */
    private function pdfToPngViaPdftoppm(string $absolutePdfPath, string $targetDir, string $basename, int $pageCap): array
    {
        $prefix = $targetDir.'/'.$basename;
        $cmd = sprintf(
            'pdftoppm -png -r %d -f 1 -l %d %s %s',
            $this->rasterizeDpi,
            $pageCap,
            escapeshellarg($absolutePdfPath),
            escapeshellarg($prefix)
        );

        $this->execWithTimeout($cmd);

        $images = [];
        for ($i = 1; $i <= $pageCap; ++$i) {
            $file = $prefix.'-'.$i.'.png';
            if (is_file($file) && filesize($file) > 0) {
                $images[] = $file;
            }
        }

        return $images;
    }

    /**
     * Execute command with timeout.
     */
    private function execWithTimeout(string $cmd): void
    {
        $timeoutSec = max(1, (int) ceil($this->rasterizeTimeoutMs / 1000));
        $fullCmd = sprintf('timeout %ds %s 2>&1', $timeoutSec, $cmd);

        exec($fullCmd, $output, $returnCode);

        if (0 !== $returnCode) {
            $this->logger->error('Rasterizer exec failed', [
                'command' => $cmd,
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
            ]);
        }
    }

    /**
     * Resolve target directory for PNG files.
     */
    private function resolveTargetDir(string $absolutePdfPath): string
    {
        $uploadBase = rtrim($this->uploadDir, '/').'/';

        // If PDF is in upload directory, write PNGs next to it
        if (str_starts_with($absolutePdfPath, $uploadBase)) {
            $dir = dirname($absolutePdfPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            return $dir;
        }

        // Otherwise, use temp folder
        return $this->ensureTempDir();
    }

    /**
     * Ensure temp directory exists.
     */
    private function ensureTempDir(): string
    {
        $dir = rtrim($this->uploadDir, '/').'/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Get last used engine (imagick or pdftoppm).
     */
    public function getLastEngine(): string
    {
        return $this->lastEngine;
    }

    /**
     * Get last used DPI.
     */
    public function getLastDpi(): int
    {
        return $this->lastDpi;
    }

    /**
     * Get last number of pages processed.
     */
    public function getLastPages(): int
    {
        return $this->lastPages;
    }
}
