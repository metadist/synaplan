<?php

namespace App\AI\Interface;

interface ImageGenerationProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generiert Bild aus Text-Prompt
     */
    public function generateImage(string $prompt, array $options = []): array;

    /**
     * Erstellt Variationen eines Bildes
     */
    public function createVariations(string $imageUrl, int $count = 1): array;

    /**
     * Image Editing mit Mask
     */
    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string;
}

