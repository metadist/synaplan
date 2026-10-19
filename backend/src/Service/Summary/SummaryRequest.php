<?php

declare(strict_types=1);

namespace App\Service\Summary;

/**
 * What POST /api/v1/summary/generate asks for: a text or the id of an owned
 * file (exactly one), and the summary options with their defaults. The
 * source is checked when the request is read; the options are checked after
 * the file is found, as the endpoint always did, so they stay as sent until
 * then.
 */
final readonly class SummaryRequest
{
    public const TRANSLATION_PROMPT = <<<'PROMPT'
        You are a professional translator. Translate the document the user sends into the target language they name.
        Translate all of it, in its order, and keep headings, lists, tables, numbers, dates, names and links.
        Do not summarize, shorten, explain, judge or add anything. Reply with the translation only.
        PROMPT;

    private function __construct(
        public ?string $text,
        public ?int $fileId,
        public mixed $summaryType,
        public mixed $length,
        public mixed $customLength,
        public mixed $outputLanguage,
        public mixed $focusAreas,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the payload names neither or both sources
     */
    public static function fromPayload(mixed $payload): self
    {
        $data = \is_array($payload) ? $payload : [];
        $text = isset($data['text']) && \is_string($data['text']) && '' !== trim($data['text']) ? trim($data['text']) : null;
        $fileId = null;
        if (\is_int($data['fileId'] ?? null)) {
            $fileId = $data['fileId'];
        } elseif (\is_string($data['fileId'] ?? null) && ctype_digit($data['fileId'])) {
            $fileId = (int) $data['fileId'];
        }

        if (null === $text && null === $fileId) {
            throw new \InvalidArgumentException('Either text or fileId is required');
        }
        if (null !== $text && null !== $fileId) {
            throw new \InvalidArgumentException('Provide either text or fileId, not both');
        }

        return new self(
            $text,
            $fileId,
            $data['summaryType'] ?? 'abstractive',
            $data['length'] ?? 'medium',
            $data['customLength'] ?? null,
            $data['outputLanguage'] ?? 'en',
            $data['focusAreas'] ?? ['main-ideas', 'key-facts'],
        );
    }

    /**
     * Why the options cannot be served, or null.
     */
    public function optionsError(): ?string
    {
        if (!\in_array($this->summaryType, SummaryOptions::TYPES, true)) {
            return 'Invalid summary type. Must be: '.implode(', ', SummaryOptions::TYPES);
        }
        if (!\in_array($this->length, SummaryOptions::LENGTHS, true)) {
            return 'Invalid length. Must be: '.implode(', ', SummaryOptions::LENGTHS);
        }

        return null;
    }

    public function isTranslation(): bool
    {
        return 'translation' === $this->summaryType;
    }

    public function systemPrompt(string $summaryPrompt): string
    {
        return $this->isTranslation() ? self::TRANSLATION_PROMPT : $summaryPrompt;
    }

    /**
     * Low temperatures keep summaries consistent and translations literal.
     */
    public function temperature(): float
    {
        return $this->isTranslation() ? 0.2 : 0.5;
    }

    /**
     * The message that hands the model the options and the document.
     */
    public function message(string $document): string
    {
        if ($this->isTranslation()) {
            return "Target language: {$this->outputLanguage}\n\nDocument to translate:\n\n{$document}";
        }

        $targetWordCount = match ($this->length) {
            'short' => '50-150',
            'long' => '500-1000',
            'custom' => (string) ($this->customLength ?? 300),
            default => '200-500',
        };

        return "Configuration:\n"
            ."- Summary Type: {$this->summaryType}\n"
            ."- Target Length: {$targetWordCount} words\n"
            ."- Output Language: {$this->outputLanguage}\n"
            .'- Focus Areas: '.implode(', ', $this->focusAreas)."\n\n"
            ."Document to summarize:\n\n{$document}";
    }
}
