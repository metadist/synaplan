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

    /**
     * Spreadsheets are exported with the full-sheet layout (#1690), which is a
     * different artifact from the paginated print layout — so it gets its own
     * cache name and a workbook exported before that change is converted again
     * instead of serving the clipped PDF until the source changes.
     */
    public const SHEET_CACHE_SUFFIX = '.export.sheet.pdf';

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
        $extension = pathinfo($sourceRelativePath, PATHINFO_EXTENSION);
        $suffix = DocumentThumbnailGenerator::isSpreadsheet($extension) ? self::SHEET_CACHE_SUFFIX : self::CACHE_SUFFIX;

        return self::siblingPath($sourceRelativePath, $suffix);
    }

    /**
     * Every cache name this source may have been exported under, current
     * first. Cleanup must remove all of them, not only the one currently
     * served.
     *
     * @return list<string>
     */
    public static function cachedRelativePaths(string $sourceRelativePath): array
    {
        return array_values(array_unique([
            self::cachedRelativePath($sourceRelativePath),
            self::siblingPath($sourceRelativePath, self::CACHE_SUFFIX),
        ]));
    }

    /**
     * Convert options for a source of this type: workbooks render each sheet
     * on one content-sized page so long row labels are never cut at the cell
     * border (#1690); Writer/Impress sources keep the plain conversion.
     *
     * @return array<string, mixed>
     */
    public static function conversionOptions(string $extension): array
    {
        if (DocumentThumbnailGenerator::isSpreadsheet($extension)) {
            return [OfficeConverterClient::OPTION_FULL_SHEET_PREVIEW => true];
        }

        return [];
    }

    private static function siblingPath(string $sourceRelativePath, string $suffix): string
    {
        $pathInfo = pathinfo($sourceRelativePath);
        $dir = $pathInfo['dirname'] ?? '';
        $basename = '' !== $pathInfo['filename'] ? $pathInfo['filename'] : 'file';
        if ('.' === $dir || '' === $dir) {
            return $basename.$suffix;
        }

        return $dir.'/'.$basename.$suffix;
    }

    public static function pdfDownloadName(File $file): string
    {
        $base = pathinfo($file->getFileName(), PATHINFO_FILENAME);

        return ('' !== $base ? $base : 'document').'.pdf';
    }

    /**
     * Whether office sources can be exported at all on this install.
     */
    public function isEnabled(): bool
    {
        return $this->converter->isEnabled();
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
        foreach (self::cachedRelativePaths($sourceRelativePath) as $relative) {
            $absolute = $this->uploadDir.'/'.ltrim($relative, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }
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

        $converted = $this->converter->convert($source, 'pdf', self::conversionOptions($ext));
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
