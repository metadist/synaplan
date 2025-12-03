<?php

namespace App\AI\Interface;

interface SpeechToTextProviderInterface extends ProviderMetadataInterface
{
    /**
     * Transkribiert Audio-Datei
     */
    public function transcribe(string $audioPath, array $options = []): array;

    /**
     * Übersetzt Audio direkt zu Text in anderer Sprache
     */
    public function translateAudio(string $audioPath, string $targetLang): string;
}

