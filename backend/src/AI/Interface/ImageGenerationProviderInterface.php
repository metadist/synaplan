<?php

declare(strict_types=1);

namespace App\AI\Interface;

interface ImageGenerationProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generates images from a text prompt. Image-to-image (pic2pic) passes the
     * source images as $options['images'].
     */
    public function generateImage(string $prompt, array $options = []): array;

    /**
     * Creates variations of an existing image.
     */
    public function createVariations(string $imageUrl, int $count = 1): array;
}
