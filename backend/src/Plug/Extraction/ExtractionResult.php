<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

/**
 * Adapter output. `rewrite()` is for preparer steps (office convert) that
 * replace the file on disk and let the next adapter continue.
 *
 * @phpstan-type ExtractionMeta array<string, mixed>
 */
final readonly class ExtractionResult
{
    /**
     * @param ExtractionMeta    $meta
     * @param list<string>|null $pages
     */
    public function __construct(
        public string $text,
        public ?string $markdown,
        public string $strategy,
        public array $meta,
        public ?array $pages = null,
        public ?string $rewrittenAbsolutePath = null,
        public ?string $rewrittenExt = null,
    ) {
    }

    /**
     * @param ExtractionMeta $meta
     */
    public static function of(string $text, string $strategy, array $meta = [], ?string $markdown = null): self
    {
        return new self($text, $markdown, $strategy, $meta);
    }

    /**
     * @param ExtractionMeta $meta
     */
    public static function rewrite(string $newAbsolutePath, string $ext, array $meta = []): self
    {
        return new self('', null, 'rewrite', $meta, null, $newAbsolutePath, $ext);
    }

    public function isRewrite(): bool
    {
        return null !== $this->rewrittenAbsolutePath;
    }

    public function hasText(): bool
    {
        return '' !== $this->text;
    }

    /**
     * FileProcessor::extractText return shape: [text, meta].
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function toLegacyPair(): array
    {
        $meta = $this->meta;
        $meta['strategy'] = $this->strategy;
        if (null !== $this->markdown) {
            $meta['markdown'] = $this->markdown;
        }

        return [$this->text, $meta];
    }
}
