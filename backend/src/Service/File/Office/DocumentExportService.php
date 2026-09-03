<?php

declare(strict_types=1);

namespace App\Service\File\Office;

use App\Entity\File;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;

/**
 * Export an on-disk office file to PDF (cached next to the source).
 *
 * Already-PDF sources are returned as-is. Never throws.
 */
final readonly class DocumentExportService
{
    public const CACHE_SUFFIX = '.export.pdf';

    public function __construct(
        private OfficeConverterClient $converter,
        private DocumentGeneratorService $documentGenerator,
        private DocumentImageReferenceResolver $documentImageReferenceResolver,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    public static function cachedRelativePath(string $sourceRelativePath): string
    {
        $pathInfo = pathinfo($sourceRelativePath);
        $dir = $pathInfo['dirname'] ?? '';
        $basename = '' !== $pathInfo['filename'] ? $pathInfo['filename'] : 'file';
        if ('.' === $dir || '' === $dir) {
            return $basename.self::CACHE_SUFFIX;
        }

        return $dir.'/'.$basename.self::CACHE_SUFFIX;
    }

    public static function pdfDownloadName(File $file): string
    {
        $base = pathinfo($file->getFileName(), PATHINFO_FILENAME);

        return ('' !== $base ? $base : 'document').'.pdf';
    }

    /**
     * Absolute path of a PDF the caller can stream, or null.
     */
    public function exportToPdf(File $file): ?string
    {
        try {
            return $this->doExport($file);
        } catch (\Throwable $e) {
            $this->logger->warning('Document PDF export failed', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function deleteCachedPdf(string $sourceRelativePath): void
    {
        $absolute = $this->uploadDir.'/'.ltrim(self::cachedRelativePath($sourceRelativePath), '/');
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function doExport(File $file): ?string
    {
        $source = $this->ensureSourceOnDisk($file);
        if (null === $source) {
            return null;
        }

        $ext = DocumentThumbnailGenerator::extensionOf($file);
        if (DocumentThumbnailGenerator::isPdf($ext)) {
            return $source;
        }

        if (!DocumentThumbnailGenerator::isOffice($ext)) {
            return null;
        }

        if (!$this->converter->isEnabled()) {
            return null;
        }

        $cacheRelative = self::cachedRelativePath(ltrim($file->getFilePath(), '/'));
        $cacheAbsolute = $this->uploadDir.'/'.$cacheRelative;
        if (is_file($cacheAbsolute) && filemtime($cacheAbsolute) >= filemtime($source)) {
            $this->logger->info('Document PDF export cache hit', [
                'file_id' => $file->getId(),
                'cache' => $cacheRelative,
            ]);

            return $cacheAbsolute;
        }

        $converted = $this->converter->convert($source, 'pdf');
        if (null === $converted || !is_file($converted)) {
            return null;
        }

        if (!FileHelper::ensureParentDirectory($cacheAbsolute)) {
            @unlink($converted);

            return null;
        }

        if (!@rename($converted, $cacheAbsolute) && !@copy($converted, $cacheAbsolute)) {
            $this->logger->warning('Document PDF export failed to move cache', [
                'from' => $converted,
                'to' => $cacheAbsolute,
            ]);
            @unlink($converted);

            return null;
        }
        if ($converted !== $cacheAbsolute) {
            @unlink($converted);
        }
        FileHelper::setFilePermissions($cacheAbsolute);

        return $cacheAbsolute;
    }

    private function ensureSourceOnDisk(File $file): ?string
    {
        $relative = ltrim($file->getFilePath(), '/');
        if ('' === $relative) {
            return null;
        }
        $absolute = $this->uploadDir.'/'.$relative;
        if (FileHelper::fileExistsNfs($absolute)) {
            return $absolute;
        }

        $text = $file->getFileText();
        if ('' === trim($text)) {
            return null;
        }

        $extension = DocumentThumbnailGenerator::extensionOf($file);
        if ('' === $extension) {
            return null;
        }

        try {
            if (!FileHelper::ensureParentDirectory($absolute)) {
                return null;
            }
            $images = $this->documentImageReferenceResolver->resolvePersistent($text, $file->getUserId());
            $this->documentGenerator->write($text, $extension, $absolute, $images);
        } catch (\Throwable $e) {
            $this->logger->warning('Document export: failed to regenerate missing binary', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $absolute;
    }
}
