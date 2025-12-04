<?php

namespace App\AI\Interface;

/**
 * Video Generation Provider Interface.
 *
 * Providers that can generate videos from text prompts
 */
interface VideoGenerationProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generate video from text prompt.
     *
     * @param string $prompt  The text prompt describing the video
     * @param array  $options Provider-specific options (duration, resolution, etc.)
     *
     * @return array Array of generated videos with metadata
     *               [
     *               ['url' => string, 'duration' => int, 'resolution' => string],
     *               ...
     *               ]
     */
    public function generateVideo(string $prompt, array $options = []): array;
}
