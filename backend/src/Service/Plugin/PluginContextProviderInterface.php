<?php

declare(strict_types=1);

namespace App\Service\Plugin;

/**
 * Interface for plugins that provide additional context to the chat system prompt.
 *
 * Implementations are auto-tagged via services.yaml and injected into ChatHandler.
 * Context is appended to the system prompt alongside RAG, memories, and feedback.
 *
 * Example: A casting data plugin fetches production/audition data from an external
 * API and injects it so the LLM can answer performer questions accurately.
 */
interface PluginContextProviderInterface
{
    /**
     * Check if this provider should inject context for the given request.
     *
     * @param int   $userId         The user who owns the chat/widget
     * @param array $classification Classification data (topic, language, source, etc.)
     * @param array $options        Processing options (is_widget_mode, channel, etc.)
     *
     * @return bool True if this provider has context to contribute
     */
    public function supports(int $userId, array $classification, array $options): bool;

    /**
     * Get context string to append to the system prompt.
     *
     * Should return a formatted markdown block that the LLM can use.
     * Return empty string if no relevant data was found.
     *
     * @param int    $userId         The user who owns the chat/widget
     * @param string $userMessage    The current user message text
     * @param array  $classification Classification data
     * @param array  $options        Processing options
     *
     * @return string Formatted context block or empty string
     */
    public function getContext(int $userId, string $userMessage, array $classification, array $options): string;
}
