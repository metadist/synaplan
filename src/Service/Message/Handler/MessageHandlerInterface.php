<?php

namespace App\Service\Message\Handler;

use App\Entity\Message;

/**
 * Interface für Message Handler
 */
interface MessageHandlerInterface
{
    /**
     * Handler Name (für Routing)
     */
    public function getName(): string;

    /**
     * Handled eine Message und gibt Response zurück
     * 
     * @param Message $message Die zu verarbeitende Message
     * @param array $thread Conversation Thread
     * @param array $classification Klassifizierungs-Daten (topic, language, intent)
     * @param callable|null $progressCallback Optional callback für Progress Updates
     * @return array ['content' => string, 'metadata' => array]
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null
    ): array;
    
    /**
     * Handled eine Message mit Streaming-Support
     * 
     * @param Message $message Die zu verarbeitende Message
     * @param array $thread Conversation Thread
     * @param array $classification Klassifizierungs-Daten (topic, language, intent)
     * @param callable $streamCallback Callback für Response-Chunks (string $chunk)
     * @param callable|null $progressCallback Optional callback für Progress Updates
     * @param array $options Processing options (e.g., reasoning, temperature)
     * @return array ['metadata' => array] (content wird gestreamt)
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = []
    ): array;
}

