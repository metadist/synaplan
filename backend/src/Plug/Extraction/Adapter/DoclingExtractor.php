<?php

declare(strict_types=1);

namespace App\Plug\Extraction\Adapter;

use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\Docling\DoclingClient;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;

/**
 * docling-serve adapter. Lands in FileProcessor via extraExtractorKeys().
 *
 * @internal
 */
final readonly class DoclingExtractor implements ContentExtractorInterface
{
    private const DOCUMENT_EXT = ['pdf', 'docx', 'pptx', 'xlsx', 'html', 'htm'];
    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'tiff', 'tif'];

    public function __construct(
        private DoclingClient $client,
        private int $maxBytes,
    ) {
    }

    public function key(): string
    {
        return 'docling';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'docling',
            'Docling',
            'https://github.com/docling-project/docling-serve',
            ['DOCLING_BASE_URL'],
            'self-hosted',
        );
    }

    public function supports(ExtractionRequest $request): bool
    {
        if (!$this->client->isEnabled()) {
            return false;
        }
        if (!$this->familyAllowed($request)) {
            return false;
        }
        if (!is_file($request->absolutePath)) {
            return false;
        }
        $size = filesize($request->absolutePath);
        if (false === $size || $size > $this->maxBytes) {
            return false;
        }

        return true;
    }

    public function extract(ExtractionRequest $request): ExtractionResult
    {
        $converted = $this->client->convertFile($request->absolutePath);

        return ExtractionResult::of(
            $converted['text'],
            'docling',
            [
                'mime' => $request->mime,
                'ext' => $request->ext,
                'file' => basename($request->absolutePath),
                'pages' => $converted['pages'],
            ],
            '' !== $converted['markdown'] ? $converted['markdown'] : null,
        );
    }

    public function health(): PlugHealth
    {
        return $this->client->health();
    }

    private function familyAllowed(ExtractionRequest $request): bool
    {
        $ext = strtolower($request->ext);
        if ('document' === $request->family) {
            return \in_array($ext, self::DOCUMENT_EXT, true) || '' === $ext;
        }
        if ('text' === $request->family && \in_array($ext, ['html', 'htm'], true)) {
            return true;
        }
        if ('image' === $request->family) {
            return \in_array($ext, self::IMAGE_EXT, true);
        }

        return false;
    }
}
