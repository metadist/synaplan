<?php

namespace App\Service\Message\Handler;

use App\Entity\Message;

/**
 * Interface für Message Handler.
 */
interface MessageHandlerInterface
{
    /**
     * Result metadata key under which a handler reports that it answered the
     * turn under a DIFFERENT classification than the one it was dispatched
     * with (e.g. a misrouted audio request answered as chat). The value is a
     * partial classification (`topic`, `intent`, `media_type`, …); `null`
     * entries remove the key. {@see \App\Service\Message\MessageProcessor}
     * folds it into the classification returned to the persistence layer so
     * the stored topic and media meta describe what was actually produced.
     */
    public const EFFECTIVE_CLASSIFICATION_KEY = 'effective_classification';

    /**
     * Handler Name (für Routing).
     */
    public function getName(): string;

    /**
     * Handled eine Message und gibt Response zurück.
     *
     * Mirrors {@see handleStream()}'s `$options` so non-streaming callers
     * (email/generic webhook) can forward the same channel/disable flags
     * the Web UI sets — without those, channels like email lose access to
     * memory + reasoning configuration the streaming path takes for
     * granted (issue #615).
     *
     * @param Message              $message          Die zu verarbeitende Message
     * @param array                $thread           Conversation Thread
     * @param array<string, mixed> $classification   Klassifizierungs-Daten (topic, language, intent)
     * @param callable|null        $progressCallback Optional callback für Progress Updates
     * @param array<string, mixed> $options          Processing options (channel, disable_memories, reasoning, …)
     *
     * @return array ['content' => string, 'metadata' => array]
     */
    public function handle(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array;

    /**
     * Handled eine Message mit Streaming-Support.
     *
     * @param Message       $message          Die zu verarbeitende Message
     * @param array         $thread           Conversation Thread
     * @param array         $classification   Klassifizierungs-Daten (topic, language, intent)
     * @param callable      $streamCallback   Callback für Response-Chunks (string $chunk)
     * @param callable|null $progressCallback Optional callback für Progress Updates
     * @param array         $options          Processing options (e.g., reasoning, temperature)
     *
     * @return array ['metadata' => array] (content wird gestreamt)
     */
    public function handleStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array;
}
