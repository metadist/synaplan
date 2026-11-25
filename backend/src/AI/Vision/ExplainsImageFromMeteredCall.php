<?php

declare(strict_types=1);

namespace App\AI\Vision;

/**
 * `explainImage()` for a {@see \App\AI\Interface\MeteredVisionProviderInterface}:
 * the answer of {@see explainImageMetered()} without its token counts.
 */
trait ExplainsImageFromMeteredCall
{
    /**
     * @param array<string, mixed> $options
     */
    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        return $this->explainImageMetered($imageUrl, $prompt, $options)->content;
    }

    /**
     * @param array<string, mixed> $options
     */
    abstract public function explainImageMetered(string $imageUrl, string $prompt = '', array $options = []): VisionReply;
}
