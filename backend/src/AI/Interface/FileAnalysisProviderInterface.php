<?php

namespace App\AI\Interface;

interface FileAnalysisProviderInterface extends ProviderMetadataInterface
{
    /**
     * Analysiert Datei-Inhalt und gibt Zusammenfassung
     */
    public function analyzeFile(string $filePath, string $fileType, array $options = []): array;

    /**
     * Beantwortet Fragen über Datei-Inhalt
     */
    public function askAboutFile(string $filePath, string $question): string;
}

