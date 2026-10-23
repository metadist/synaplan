<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final class JsonSlice
{
    /**
     * @return array<string, mixed>
     */
    public function object(string $content): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if (false === $start || false === $end || $end <= $start) {
            return [];
        }
        try {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
