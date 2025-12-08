<?php

namespace App\Service\File;

use Psr\Log\LoggerInterface;

/**
 * PDF Rasterizer Service.
 *
 * Converts PDF pages to PNG images using Imagick or pdftoppm as fallback.
 * Used when Tika extraction fails or produces low-quality text.
 */
class PdfRasterizer
{
    private string $lastEngine = '';
    private int $lastDpi = 0;
    private int $lastPages = 0;

    public function __construct(
        private LoggerInterface $logger,
        private string $uploadDir,
        private int $rasterizeDpi,
        private int $rasterizePageCap,
        private int $rasterizeTimeoutMs,
    ) {
    }

    /**
     * Convert PDF to PNG images.
     *
     * @param string $absolutePdfPath Absolute path to PDF file
     *
     * @return array Array of absolute paths to generated PNG files
     */
    public function pdfToPng(string $absolutePdfPath): array
    {
        if (!is_file($absolutePdfPath) || 0 === filesize($absolutePdfPath)) {
            $this->logger->warning('Rasterizer: PDF file missing or empty', ['file' => $absolutePdfPath]);

            return [];
        }

        $targetDir = $this->resolveTargetDir($absolutePdfPath);
        $basename = pathinfo($absolutePdfPath, PATHINFO_FILENAME);
        $images = [];

        // Try Imagick first (faster and better quality)
        if (class_exists('\\Imagick')) {
            try {
                $imagick = new \Imagick();
                $imagick->setResolution($this->rasterizeDpi, $this->rasterizeDpi);
                $imagick->readImage($absolutePdfPath);
                $pages = min($this->rasterizePageCap, $imagick->getNumberImages());
                $imagick->setIteratorIndex(0);

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

                if (!empty($images)) {
                    $this->lastEngine = 'imagick';
                    $this->lastDpi = $this->rasterizeDpi;
                    $this->lastPages = count($images);

                    $this->logger->info('Rasterizer success with Imagick', [
                        'engine' => 'imagick',
                        'pages' => $this->lastPages,
                        'dpi' => $this->lastDpi,
                    ]);

                    return $images;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Rasterizer Imagick failed, falling back to pdftoppm', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to pdftoppm
        $images = $this->pdfToPngViaPdftoppm($absolutePdfPath, $targetDir, $basename);

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

    /**
     * Fallback method using pdftoppm command-line tool.
     */
    private function pdfToPngViaPdftoppm(string $absolutePdfPath, string $targetDir, string $basename): array
    {
        $prefix = $targetDir.'/'.$basename;
        $cmd = sprintf(
            'pdftoppm -png -r %d -f 1 -l %d %s %s',
            $this->rasterizeDpi,
            $this->rasterizePageCap,
            escapeshellarg($absolutePdfPath),
            escapeshellarg($prefix)
        );

        $this->execWithTimeout($cmd);

        $images = [];
        for ($i = 1; $i <= $this->rasterizePageCap; ++$i) {
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
