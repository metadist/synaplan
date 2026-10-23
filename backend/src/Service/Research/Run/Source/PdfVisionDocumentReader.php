<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

use App\AI\Service\AiFacade;
use App\Service\File\PdfRasterizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends a few PDF pages to the vision model when the file has no text layer.
 * The images are deleted afterwards. Nothing is stored as the person's file.
 */
#[AsAlias(CloudDocumentReader::class)]
final readonly class PdfVisionDocumentReader implements CloudDocumentReader
{
    private const MAX_PAGES = 3;
    private const PROMPT = 'Extract every piece of written text from this PDF page. '
        .'Return only the text exactly as it appears, preserving line breaks. '
        .'Do not provide any descriptions. If no text is present, return an empty string.';

    public function __construct(
        private PdfRasterizer $rasterizer,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
        #[Autowire('%app.upload_dir%')]
        private string $uploadDir,
    ) {
    }

    public function read(string $absolutePath, string $extension, ?int $userId): string
    {
        if ('pdf' !== strtolower($extension)) {
            return '';
        }

        $images = $this->rasterizer->pdfToPng($absolutePath, self::MAX_PAGES);
        $uploadBase = rtrim($this->uploadDir, '/').'/';
        $text = '';
        foreach ($images as $image) {
            try {
                if (!str_starts_with($image, $uploadBase)) {
                    continue;
                }
                $relative = substr($image, \strlen($uploadBase));
                $result = $this->aiFacade->analyzeImage($relative, self::PROMPT, $userId);
                $page = trim((string) ($result['content'] ?? ''));
                if ('' !== $page) {
                    $text .= ('' === $text ? '' : "\n\n").$page;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('PdfVisionDocumentReader: vision failed', [
                    'error' => $e->getMessage(),
                ]);
            } finally {
                if (is_file($image)) {
                    unlink($image);
                }
            }
        }

        return $text;
    }
}
