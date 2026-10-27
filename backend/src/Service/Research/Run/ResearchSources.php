<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Which places a run may search. The web is on unless the request turns it
 * off. Files are on when the request names a knowledge folder or the library.
 */
final readonly class ResearchSources
{
    private const MAX_KEYS = 8;

    private const KEY_CHARS = 120;

    /**
     * @param list<string> $knowledge
     */
    public function __construct(
        public bool $web = true,
        public array $knowledge = [],
        public bool $library = false,
    ) {
    }

    public function searchesFiles(): bool
    {
        return $this->library || [] !== $this->knowledge;
    }

    public function label(): string
    {
        $parts = [];
        if ($this->web) {
            $parts[] = 'web';
        }
        if ($this->searchesFiles()) {
            $parts[] = 'files';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        if (!array_key_exists('sources', $body) || null === $body['sources']) {
            return new self();
        }
        $sources = $body['sources'];
        if (!is_array($sources)) {
            throw new ResearchRejected(400, 'invalid', 'sources', 'Choose the web, files, or both.');
        }
        $knowledge = $sources['knowledge'] ?? null;
        $parsed = new self(
            self::flag($sources, 'web', true),
            self::keys($knowledge),
            self::flag($sources, 'library', false) || true === $knowledge,
        );
        if ('' === $parsed->label()) {
            throw new ResearchRejected(400, 'invalid', 'sources', 'Turn on the web or choose files in this project.');
        }

        return $parsed;
    }

    /**
     * @param list<string> $knowledge
     */
    public static function fromStored(string $label, array $knowledge): self
    {
        $parts = preg_split('/[\s,]+/', strtolower($label)) ?: [];

        return new self(
            in_array('web', $parts, true),
            $knowledge,
            in_array('files', $parts, true) && [] === $knowledge,
        );
    }

    /**
     * @param array<string, mixed> $sources
     */
    private static function flag(array $sources, string $field, bool $fallback): bool
    {
        if (!array_key_exists($field, $sources) || null === $sources[$field]) {
            return $fallback;
        }
        if (!is_bool($sources[$field])) {
            throw new ResearchRejected(400, 'invalid', 'sources', 'Choose the web, files, or both.');
        }

        return $sources[$field];
    }

    /**
     * @return list<string>
     */
    private static function keys(mixed $value): array
    {
        if (null === $value || is_bool($value)) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_KEYS) {
            throw new ResearchRejected(400, 'invalid', 'sources', 'Choose a folder this project can use.');
        }
        $keys = [];
        foreach ($value as $key) {
            if (!is_string($key) || '' === trim($key) || mb_strlen($key) > self::KEY_CHARS || 1 === preg_match('/[\x00-\x1F]/', $key)) {
                throw new ResearchRejected(400, 'invalid', 'sources', 'Choose a folder this project can use.');
            }
            $keys[] = trim($key);
        }

        return $keys;
    }
}
