<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * Ollama streams one JSON object per line. A partial line stays buffered.
 */
final class OllamaNdjsonDecoder implements StreamDecoderInterface
{
    private string $buffer = '';

    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $chunks = [];
        while (false !== ($pos = strpos($this->buffer, "\n"))) {
            $line = trim(substr($this->buffer, 0, $pos));
            $this->buffer = substr($this->buffer, $pos + 1);
            if ('' === $line) {
                continue;
            }
            try {
                $data = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $chunks[] = new DecodedEvent('malformed', substr($line, 0, 200));
                continue;
            }
            if (is_array($data)) {
                $chunks[] = new DecodedEvent('event', $data);
            }
        }

        return $chunks;
    }

    public function finish(): array
    {
        return [];
    }
}
