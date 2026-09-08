<?php

declare(strict_types=1);

namespace App\Plug\Extraction\Adapter;

use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Service\File\TextCleaner;
use App\Service\File\TikaClient;

/**
 * Wraps {@see TikaClient}. Bodies are not rewritten; strategy string stays `tika`.
 *
 * @internal
 */
final readonly class TikaExtractor implements ContentExtractorInterface
{
    public function __construct(
        private TikaClient $tikaClient,
        private TextCleaner $textCleaner,
    ) {
    }

    public function key(): string
    {
        return 'tika';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'tika',
            'Apache Tika',
            'https://tika.apache.org/',
            ['TIKA_BASE_URL'],
            'self-hosted',
        );
    }

    public function supports(ExtractionRequest $request): bool
    {
        return $this->tikaClient->isEnabled() && 'document' === $request->family;
    }

    public function extract(ExtractionRequest $request): ExtractionResult
    {
        [$text, $tikaMeta] = $this->tikaClient->extractText($request->absolutePath, $request->mime);
        $cleaned = \is_string($text) ? $this->textCleaner->clean($text) : '';

        return ExtractionResult::of(
            $cleaned,
            'tika',
            array_merge(
                [
                    'mime' => $request->mime,
                    'ext' => $request->ext,
                    'file' => basename($request->absolutePath),
                ],
                \is_array($tikaMeta) ? $tikaMeta : [],
            ),
        );
    }

    public function health(): PlugHealth
    {
        if (!$this->tikaClient->isEnabled()) {
            return PlugHealth::unavailable('TIKA_BASE_URL is unset or disabled');
        }

        return PlugHealth::available();
    }
}
