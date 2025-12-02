<?php

namespace App\AI\Interface;

interface TextToSpeechProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generiert Audio aus Text
     */
    public function synthesize(string $text, array $options = []): string;

    /**
     * Liste verfügbarer Stimmen
     */
    public function getVoices(): array;
}

