<?php

namespace App\AI\Interface;

interface TextToSpeechProviderInterface extends ProviderMetadataInterface
{
    /**
     * Synthesize text to audio, save to file, return filename.
     */
    public function synthesize(string $text, array $options = []): string;

    /**
     * Stream audio chunks as a Generator for real-time playback.
     *
     * Yields binary audio data chunks. The content type depends on the provider
     * (e.g. audio/mpeg for OpenAI, audio/webm for Piper).
     *
     * @param array{voice?: string, model?: string, speed?: float, format?: string, language?: string} $options
     *
     * @return \Generator<int, string, void, void> Yields binary audio chunks
     */
    public function synthesizeStream(string $text, array $options = []): \Generator;

    /**
     * Return the MIME type used by synthesizeStream().
     */
    public function getStreamContentType(array $options = []): string;

    /**
     * Whether this provider supports real-time streaming.
     */
    public function supportsStreaming(): bool;

    /**
     * List available voices.
     */
    public function getVoices(): array;
}
