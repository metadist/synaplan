<?php

declare(strict_types=1);

namespace App\Service\Widget\Turn;

/**
 * Turns the processor's chunks into the pieces a widget answer streams.
 * Reasoning is gathered into one `<think>` block that is sent when the
 * answer text starts (or when the stream ends); an answer that arrives as
 * JSON is held until its BTEXT can be read. Keeps the stored answer text and
 * the finish reason. One instance per answer.
 */
final class WidgetAnswerChunks
{
    private string $text = '';
    private string $reasoning = '';
    private bool $reasoningOpen = false;
    private string $json = '';
    private bool $bufferingJson = false;
    private int $count = 0;
    private ?string $finishReason = null;

    /**
     * @return list<string> the pieces to stream now, in order
     */
    public function push(mixed $chunk): array
    {
        if (is_array($chunk)) {
            return $this->pushTyped($chunk);
        }

        $pieces = $this->closeReasoning();
        $chunkStr = (string) $chunk;

        if (0 === $this->count && !$this->bufferingJson) {
            $trimmed = trim($chunkStr);
            if ('' !== $trimmed && str_starts_with($trimmed, '{')) {
                $this->bufferingJson = true;
                $this->json = $chunkStr;
                ++$this->count;

                return $pieces;
            }
        }

        if ($this->bufferingJson) {
            $this->json .= $chunkStr;
            ++$this->count;
            $text = $this->jsonText();
            if (null !== $text) {
                $this->bufferingJson = false;
                $this->json = '';
                $pieces = [...$pieces, ...$this->emit($text)];
            }

            return $pieces;
        }

        ++$this->count;

        return [...$pieces, ...$this->emit($chunkStr)];
    }

    /**
     * The reasoning block still open when the stream ended, closed.
     *
     * @return list<string>
     */
    public function finish(): array
    {
        if (!$this->reasoningOpen || '' === $this->reasoning) {
            return [];
        }
        $this->reasoning .= '</think>';
        $this->text .= $this->reasoning;

        return [$this->reasoning];
    }

    public function text(): string
    {
        return $this->text;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    /**
     * @param array<mixed> $chunk
     *
     * @return list<string>
     */
    private function pushTyped(array $chunk): array
    {
        $type = $chunk['type'] ?? 'content';
        if ('finish' === $type) {
            $reason = $chunk['finish_reason'] ?? null;
            $this->finishReason = is_string($reason) ? $reason : null;

            return [];
        }

        $content = (string) ($chunk['content'] ?? '');
        if ('reasoning' === $type) {
            if (!$this->reasoningOpen) {
                $this->reasoning = '<think>';
                $this->reasoningOpen = true;
            }
            $this->reasoning .= $content;

            return [];
        }

        return [...$this->closeReasoning(), ...$this->emit($content)];
    }

    /**
     * @return list<string>
     */
    private function closeReasoning(): array
    {
        if (!$this->reasoningOpen) {
            return [];
        }
        $block = $this->reasoning.'</think>';
        $this->reasoning = '';
        $this->reasoningOpen = false;

        return $this->emit($block);
    }

    /**
     * @return list<string>
     */
    private function emit(string $content): array
    {
        if ('' === $content) {
            return [];
        }
        $this->text .= $content;

        return [$content];
    }

    /**
     * The BTEXT of the buffered JSON once it parses, with an empty BFILE
     * read as 0; null while the answer is still incomplete.
     */
    private function jsonText(): ?string
    {
        if (!str_contains($this->json, '}')) {
            return null;
        }
        $trimmed = trim($this->json);
        $lastBrace = strrpos($trimmed, '}');
        if (false === $lastBrace) {
            return null;
        }

        $candidate = substr($trimmed, 0, $lastBrace + 1);
        $candidate = (string) preg_replace('/"BFILE":\s*\n/', '"BFILE": 0'."\n", $candidate);
        $candidate = (string) preg_replace('/"BFILE":\s*\r\n/', '"BFILE": 0'."\r\n", $candidate);
        $candidate = (string) preg_replace('/"BFILE":\s*}/', '"BFILE": 0}', $candidate);

        try {
            $data = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && isset($data['BTEXT']) ? (string) $data['BTEXT'] : null;
    }
}
