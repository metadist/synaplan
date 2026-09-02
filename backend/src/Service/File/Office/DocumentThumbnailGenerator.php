<?php

declare(strict_types=1);

namespace App\Service\File\Office;

use App\Entity\File;
use App\Service\File\FileHelper;
use App\Service\File\PdfRasterizer;
use App\Service\File\ThumbnailService;
use Psr\Log\LoggerInterface;

/**
 * First-page poster for office documents and PDFs.
 *
 * Office formats go through Collabora convert-to PNG when the engine is on.
 * PDFs use {@see PdfRasterizer} (Imagick / pdftoppm) and work without the
 * engine. Never throws.
 */
final readonly class DocumentThumbnailGenerator
{
    public const OFFICE_EXTENSIONS = [
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf',
        'pages', 'numbers', 'key',
    ];

    private const POSTER_WIDTH = 640;
    private const POSTER_HEIGHT = 360;
    private const JPEG_QUALITY = 82;

    public function __construct(
        private OfficeConverterClient $converter,
        private PdfRasterizer $pdfRasterizer,
        private ThumbnailService $thumbnailService,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    public static function isPdf(string $extension): bool
    {
        return 'pdf' === strtolower($extension);
    }

    public static function isOffice(string $extension): bool
    {
        return in_array(strtolower($extension), self::OFFICE_EXTENSIONS, true);
    }

    public static function supportsExtension(string $extension): bool
    {
        return self::isPdf($extension) || self::isOffice($extension);
    }

    public static function extensionOf(File $file): string
    {
        $fromName = strtolower(pathinfo($file->getFileName(), PATHINFO_EXTENSION));
        if ('' !== $fromName) {
            return $fromName;
        }

        return strtolower($file->getFileType());
    }

    /**
     * @return string|null relative thumb path (`{basename}_thumb.jpg`) or null
     */
    public function generate(File $file): ?string
    {
        try {
            return $this->doGenerate($file);
        } catch (\Throwable $e) {
            $this->logger->warning('Document thumbnail generation failed', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function doGenerate(File $file): ?string
    {
        $ext = self::extensionOf($file);
        if (!self::supportsExtension($ext)) {
            return null;
        }

        $relativePath = ltrim($file->getFilePath(), '/');
        if ('' === $relativePath) {
            return null;
        }

        $absoluteInput = $this->uploadDir.'/'.$relativePath;
        if (!FileHelper::fileExistsNfs($absoluteInput)) {
            $this->logger->warning('Document thumbnail: source missing', [
                'file_id' => $file->getId(),
                'path' => $relativePath,
            ]);

            return null;
        }

        $pngPath = self::isPdf($ext)
            ? $this->firstPdfPagePng($absoluteInput)
            : $this->officeFirstPagePng($absoluteInput);

        if (null === $pngPath) {
            return null;
        }

        $thumbRelative = $this->thumbnailService->getThumbnailPath($relativePath);
        $thumbAbsolute = $this->uploadDir.'/'.ltrim($thumbRelative, '/');

        if (!FileHelper::ensureParentDirectory($thumbAbsolute)) {
            $this->cleanupTemp($pngPath, $absoluteInput);

            return null;
        }

        $wrote = $this->writePosterJpeg($pngPath, $thumbAbsolute);
        $this->cleanupTemp($pngPath, $absoluteInput);

        if (!$wrote || !FileHelper::fileExistsNfs($thumbAbsolute)) {
            return null;
        }

        FileHelper::setFilePermissions($thumbAbsolute);

        return $thumbRelative;
    }

    private function firstPdfPagePng(string $absolutePdf): ?string
    {
        $pages = $this->pdfRasterizer->pdfToPng($absolutePdf, 1);

        return $pages[0] ?? null;
    }

    private function officeFirstPagePng(string $absoluteInput): ?string
    {
        if (!$this->converter->isEnabled()) {
            return null;
        }

        return $this->converter->convert($absoluteInput, 'png');
    }

    private function writePosterJpeg(string $pngPath, string $jpegPath): bool
    {
        if (!class_exists(\Imagick::class)) {
            $this->logger->warning('Document thumbnail: Imagick missing, cannot write poster');

            return false;
        }

        $imagick = new \Imagick($pngPath);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(self::JPEG_QUALITY);
        $imagick->thumbnailImage(self::POSTER_WIDTH, self::POSTER_HEIGHT, true);
        $ok = $imagick->writeImage($jpegPath);
        $imagick->clear();
        $imagick->destroy();

        return $ok && is_file($jpegPath) && filesize($jpegPath) > 0;
    }

    private function cleanupTemp(string $pngPath, string $sourcePath): void
    {
        if ($pngPath === $sourcePath) {
            return;
        }
        if (is_file($pngPath)) {
            @unlink($pngPath);
        }
    }
}
