<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * Gemini SSE (`data: {json}`). A chunk may split a line; a complete
 * `data:` object is also accepted without a trailing newline, which is
 * how a single HTTP chunk arrives today.
 */
final class GeminiStreamDecoder implements StreamDecoderInterface
{
    private string $buffer = '';

    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $events = [];
        while (false !== ($pos = strpos($this->buffer, "\n"))) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            $event = $this->eventFrom($line);
            if (null !== $event) {
                $events[] = new DecodedEvent('event', $event);
            }
        }
        $event = $this->eventFrom($this->buffer);
        if (null !== $event) {
            $events[] = new DecodedEvent('event', $event);
            $this->buffer = '';
        }

        return $events;
    }

    public function finish(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function eventFrom(string $line): ?array
    {
        $line = trim($line);
        if (!str_starts_with($line, 'data: ')) {
            return null;
        }
        $decoded = json_decode(substr($line, 6), true);

        return is_array($decoded) ? $decoded : null;
    }
}
