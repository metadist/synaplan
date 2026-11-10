<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * OpenAI chat-completions SSE, and the same JSON events the SDK already parsed.
 *
 * `$includeReasoningAlias` also reads `delta.reasoning` (Ollama). Mistral
 * only sends `reasoning_content`.
 */
final class OpenAiChatCompletionsDecoder implements StreamDecoderInterface
{
    private string $buffer = '';

    /** @var array<string, mixed> */
    private array $usage = [];
    private ?string $finishReason = null;

    public function __construct(
        private readonly bool $includeReasoningAlias = true,
    ) {
    }

    public function feed(string $bytes): array
    {
        $chunks = [];
        foreach ($this->events($bytes) as $event) {
            array_push($chunks, ...$this->accept($event));
        }

        return $chunks;
    }

    /**
     * Decoded SSE payloads, for a translator that maps each event itself.
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $bytes): array
    {
        $this->buffer .= $bytes;
        $events = [];
        while (false !== ($pos = strpos($this->buffer, "\n"))) {
            $line = trim(substr($this->buffer, 0, $pos));
            $this->buffer = substr($this->buffer, $pos + 1);
            if ('' === $line || !str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ('' === $payload || '[DONE]' === $payload) {
                continue;
            }
            try {
                $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * One SDK event, already decoded. The chat-completions loop uses this
     * so the request and the chunks stay the ones the SDK produced.
     *
     * @param array<string, mixed> $event
     *
     * @return list<DecodedEvent>
     */
    public function accept(array $event): array
    {
        if ('error' === ($event['object'] ?? null) || is_array($event['error'] ?? null)) {
            return [new DecodedEvent('error', $event)];
        }
        if (is_array($event['usage'] ?? null)) {
            $this->usage = $event['usage'];
        }
        $choice = $event['choices'][0] ?? null;
        if (!is_array($choice)) {
            return [];
        }
        $finish = $choice['finish_reason'] ?? null;
        if (is_string($finish) && '' !== $finish) {
            $this->finishReason = $finish;
        }
        $chunks = [];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        $reasoning = $delta['reasoning_content'] ?? null;
        if ($this->includeReasoningAlias && !is_string($reasoning)) {
            $reasoning = $delta['reasoning'] ?? null;
        }
        if (is_string($reasoning) && '' !== $reasoning) {
            $chunks[] = new DecodedEvent('reasoning', self::toUtf8($reasoning));
        }
        if (array_key_exists('content', $delta)) {
            $chunks[] = new DecodedEvent('content', $delta['content']);
        }
        $chunks[] = new DecodedEvent('tool_choice', $choice);

        return $chunks;
    }

    public function finish(): array
    {
        if (null === $this->finishReason) {
            return [];
        }
        $reason = $this->finishReason;
        $this->finishReason = null;

        return [new DecodedEvent('finish', $reason)];
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->usage;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    private static function toUtf8(string $text): string
    {
        if ('' === $text || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
