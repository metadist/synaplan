<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\Entity\Message;
use App\Service\Message\ProgressCallback;
use App\Service\Prompt\LanguageDirectiveBuilder;

/**
 * One file-analysis turn: the message, its classification, where status
 * events go and how the answer leaves.
 */
final readonly class AnalysisContext
{
    /**
     * @param array<string, mixed> $classification
     */
    private function __construct(
        public Message $message,
        public array $classification,
        public AnalysisReply $reply,
        private ?\Closure $progress,
    ) {
    }

    /**
     * @param array<string, mixed> $classification
     */
    public static function for(Message $message, array $classification, ?callable $progressCallback, AnalysisReply $reply): self
    {
        return new self($message, $classification, $reply, null === $progressCallback ? null : $progressCallback(...));
    }

    public function notify(string $status, string $message): void
    {
        ProgressCallback::notify($this->progress, $status, $message);
    }

    public function progressCallback(): ?\Closure
    {
        return $this->progress;
    }

    /**
     * Reply in the conversation language instead of English (#1163), with the
     * anti-echo clause ChatHandler uses. Empty when no language was classified.
     */
    public function languageDirective(): string
    {
        $language = is_string($this->classification['language'] ?? null)
            ? trim($this->classification['language'])
            : '';

        if ('' === $language) {
            return '';
        }

        return 'auto' === $language
            ? LanguageDirectiveBuilder::buildAutoDirective()
            : LanguageDirectiveBuilder::buildForLanguage($language);
    }

    /** The task prompt's `aiModel` override, if one is set. */
    public function promptModelId(): ?int
    {
        $aiModel = $this->classification['prompt_metadata']['aiModel'] ?? null;

        return (null !== $aiModel && (int) $aiModel > 0) ? (int) $aiModel : null;
    }
}
