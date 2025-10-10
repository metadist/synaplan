<?php

namespace App\AI\Interface;

interface VisionProviderInterface extends ProviderMetadataInterface
{
    /**
     * Erklärt Bild-Inhalt
     */
    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string;

    /**
     * OCR: Extrahiert Text aus Bild
     */
    public function extractTextFromImage(string $imageUrl): string;

    /**
     * Vergleicht zwei Bilder
     */
    public function compareImages(string $imageUrl1, string $imageUrl2): array;
}

