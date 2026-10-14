<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Entity\Message;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Runtime\RuntimeProfile;

/**
 * What a chat turn knows before a model is chosen: the message and its
 * thread, the classification after the runtime profile, the topic prompt,
 * and the knowledge-base and personal context for the system prompt.
 *
 * `knowledgeMiss` is the reply for a /docs question the knowledge base
 * cannot answer. Such a turn ends with it; the personal context is not
 * loaded.
 */
final readonly class ChatTurn
{
    /** Processing source of a question delegated from a live voice call. */
    private const SOURCE_LIVE_VOICE = 'live_voice';

    /**
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     * @param array<string, mixed>|null                                $promptData     the topic prompt and its metadata
     */
    public function __construct(
        public Message $message,
        public array $thread,
        public array $classification,
        public array $options,
        public ?RuntimeProfile $profile,
        public ?array $promptData,
        public PerfTimer $perfTimer,
        public ChatKnowledge $knowledge = new ChatKnowledge(),
        public ChatPersonalContext $personal = new ChatPersonalContext(),
        public ?string $knowledgeMiss = null,
    ) {
    }

    public function topic(): string
    {
        return (string) ($this->classification['topic'] ?? 'general');
    }

    public function language(): string
    {
        return (string) ($this->classification['language'] ?? 'en');
    }

    /**
     * @return array<string, mixed>
     */
    public function promptMetadata(): array
    {
        return $this->promptData['metadata'] ?? [];
    }

    /**
     * A question the live voice delegated: the answer is spoken aloud, so it
     * stays short and plain. A document is written into the file, not
     * spoken, so it keeps its full length.
     */
    public function isLiveVoice(): bool
    {
        return self::SOURCE_LIVE_VOICE === ($this->options['source'] ?? null) && 'officemaker' !== $this->topic();
    }

    public function withKnowledgeMiss(string $reply): self
    {
        return new self($this->message, $this->thread, $this->classification, $this->options, $this->profile, $this->promptData, $this->perfTimer, $this->knowledge, knowledgeMiss: $reply);
    }

    public function withPersonalContext(ChatPersonalContext $personal): self
    {
        return new self($this->message, $this->thread, $this->classification, $this->options, $this->profile, $this->promptData, $this->perfTimer, $this->knowledge, $personal);
    }

    /**
     * The `metadata` a handler reports for the context it used.
     *
     * @param list<array<string, string>> $docs platform documentation the self-aware prompt cited
     *
     * @return array{memories: array<int, array<string, mixed>>, feedbacks: array<int, array<string, mixed>>, digests: array<int, array<string, mixed>>, docs: list<array<string, string>>, rag_sources: list<array<string, mixed>>}
     */
    public function contextMetadata(array $docs): array
    {
        return [
            'memories' => $this->personal->loadedMemories(),
            'feedbacks' => $this->personal->feedback['feedbacks'] ?? [],
            'digests' => $this->personal->digests['digests'] ?? [],
            'docs' => $docs,
            'rag_sources' => $this->knowledge->sources,
        ];
    }
}
