<?php

declare(strict_types=1);

namespace App\Plug\Extraction\Adapter;

use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Service\File\TextCleaner;

/**
 * Strategy 1: plain-text MIME types read from disk. Strategy string `native_text`.
 *
 * @internal
 */
final readonly class NativeTextExtractor implements ContentExtractorInterface
{
    private const PLAIN_TEXT_MIMES = [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'text/csv',
        'text/html',
    ];

    public function __construct(
        private TextCleaner $textCleaner,
    ) {
    }

    public function key(): string
    {
        return 'native';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'native',
            'Native text',
            '',
            [],
            'in-process',
        );
    }

    public function supports(ExtractionRequest $request): bool
    {
        return \in_array($request->mime, self::PLAIN_TEXT_MIMES, true);
    }

    public function extract(ExtractionRequest $request): ExtractionResult
    {
        $raw = is_file($request->absolutePath) ? (string) file_get_contents($request->absolutePath) : '';

        return ExtractionResult::of(
            $this->textCleaner->clean($raw),
            'native_text',
            [
                'mime' => $request->mime,
                'ext' => $request->ext,
                'file' => basename($request->absolutePath),
            ],
        );
    }

    public function health(): PlugHealth
    {
        return PlugHealth::available();
    }
}
