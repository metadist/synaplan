<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Interpret;

/**
 * One result the palette already shows. The model may only point at these,
 * so the answer is always bound to something that exists.
 */
final readonly class InterpretCandidate
{
    public const MAX_CANDIDATES = 30;
    public const KINDS = ['command', 'page', 'setting', 'chat', 'file', 'memory', 'widget', 'assistant', 'task'];

    private const MAX_ID_LENGTH = 120;
    private const MAX_TEXT_LENGTH = 160;
    private const MAX_VALUE_LENGTH = 40;

    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public ?string $subtitle = null,
        public ?string $value = null,
    ) {
    }

    /**
     * @return list<self>
     *
     * @throws \InvalidArgumentException when the list is missing, too long or holds an unusable entry
     */
    public static function listFromPayload(mixed $payload): array
    {
        if (!is_array($payload) || [] === $payload || count($payload) > self::MAX_CANDIDATES) {
            throw new \InvalidArgumentException(sprintf('Send 1 to %d candidates in "candidates".', self::MAX_CANDIDATES));
        }

        $candidates = [];
        $seen = [];
        foreach ($payload as $index => $item) {
            $candidate = is_array($item) ? self::fromArray($item) : null;
            if (null === $candidate) {
                throw new \InvalidArgumentException(sprintf('Candidate %s needs an id, a known kind and a title.', (string) $index));
            }
            if (isset($seen[$candidate->id])) {
                continue;
            }
            $seen[$candidate->id] = true;
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * @param array<mixed> $item
     */
    private static function fromArray(array $item): ?self
    {
        $id = self::text($item['id'] ?? null, self::MAX_ID_LENGTH);
        $kind = $item['kind'] ?? null;
        $title = self::text($item['title'] ?? null, self::MAX_TEXT_LENGTH);
        if (null === $id || null === $title || !is_string($kind) || !in_array($kind, self::KINDS, true)) {
            return null;
        }

        return new self(
            $id,
            $kind,
            $title,
            self::text($item['subtitle'] ?? null, self::MAX_TEXT_LENGTH),
            self::text($item['value'] ?? null, self::MAX_VALUE_LENGTH),
        );
    }

    private static function text(mixed $raw, int $max): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ('' === $clean) {
            return null;
        }

        return mb_substr($clean, 0, $max);
    }

    public function toPromptLine(): string
    {
        $parts = ['id='.$this->id, 'kind='.$this->kind, 'title='.$this->title];
        if (null !== $this->subtitle) {
            $parts[] = 'where='.$this->subtitle;
        }
        if (null !== $this->value) {
            $parts[] = 'current='.$this->value;
        }

        return '- '.implode(' | ', $parts);
    }
}
