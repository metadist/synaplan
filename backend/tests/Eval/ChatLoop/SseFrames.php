<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * Turns the bytes of an event stream into decoded `data:` frames. A frame
 * split across two network chunks is held back until its line is complete.
 */
final class SseFrames
{
    private string $buffer = '';

    /**
     * @return list<array<mixed>>
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $frames = [];
        while (false !== ($end = strpos($this->buffer, "\n"))) {
            $line = rtrim(substr($this->buffer, 0, $end), "\r");
            $this->buffer = substr($this->buffer, $end + 1);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $decoded = json_decode(substr($line, 5), true);
            if (\is_array($decoded)) {
                $frames[] = $decoded;
            }
        }

        return $frames;
    }
}
