<?php

declare(strict_types=1);

namespace App\Service\Usage;

/**
 * The coarse kind of a BUSELOG row, for the admin cost board: what sort of
 * work a model request was (a chat answer, a video, an agent step, …).
 *
 * The raw BACTION strings have grown organically (MESSAGES next to
 * video_generation, EMBEDDINGS next to embedding), so the board never shows
 * them directly. Agent work is spread over several actions and over chat
 * rows that carry an agent id, so the caller passes that flag in.
 */
final class UsageKind
{
    public const CHAT = 'chat';
    public const VIDEO = 'video';
    public const IMAGE = 'image';
    public const AUDIO = 'audio';
    public const VOICE = 'voice';
    public const TRANSCRIPTION = 'transcription';
    public const EMBEDDING = 'embedding';
    public const AGENT = 'agent';
    public const COMPUTE = 'compute';
    public const FILE = 'file';
    public const INTERNAL = 'internal';
    public const OTHER = 'other';

    /** @var array<string, string> raw BACTION => kind, agent rows aside */
    private const BY_ACTION = [
        'MESSAGES' => self::CHAT,
        'AI_CALL' => self::CHAT,
        'VIDEOS' => self::VIDEO,
        'video_generation' => self::VIDEO,
        'IMAGES' => self::IMAGE,
        'image_generation' => self::IMAGE,
        'AUDIOS' => self::AUDIO,
        'VOICE_LIVE' => self::VOICE,
        'TRANSCRIPTION' => self::TRANSCRIPTION,
        'speech_to_text' => self::TRANSCRIPTION,
        'EMBEDDINGS' => self::EMBEDDING,
        'embedding' => self::EMBEDDING,
        'TOOLS' => self::AGENT,
        'PLANNING' => self::AGENT,
        'COMPUTE_RUNS' => self::COMPUTE,
        'FILE_ANALYSIS' => self::FILE,
        'FILE_SUMMARY' => self::FILE,
        'DOC_SUMMARY' => self::FILE,
        'FILE_UPLOADS' => self::FILE,
        'vision' => self::FILE,
        'SORTING' => self::INTERNAL,
        'SEARCH_QUERY' => self::INTERNAL,
        'MEMORY_EXTRACTION' => self::INTERNAL,
        'MEMORY_PARSE' => self::INTERNAL,
        'MESSAGE_DIGEST' => self::INTERNAL,
        'CHAT_TITLE' => self::INTERNAL,
        'WIDGET_SETUP' => self::INTERNAL,
        'WIDGET_TITLE' => self::INTERNAL,
        'CONDENSE' => self::INTERNAL,
        'EMAIL_ROUTING' => self::INTERNAL,
        'FEEDBACK' => self::INTERNAL,
        'MAIL_HANDLER_LOG' => self::INTERNAL,
    ];

    public static function fromAction(string $action, bool $hasAgent = false): string
    {
        if ($hasAgent) {
            return self::AGENT;
        }

        return self::BY_ACTION[$action] ?? self::OTHER;
    }

    /**
     * Whether BMETADATA marks the row as work of an assistant/agent run
     * (an agent id, or a channel that only agent runs use).
     *
     * @param array<string, mixed> $metadata
     */
    public static function hasAgent(array $metadata): bool
    {
        if (is_numeric($metadata['agentId'] ?? null) && (int) $metadata['agentId'] > 0) {
            return true;
        }
        if (is_numeric($metadata['agent_id'] ?? null) && (int) $metadata['agent_id'] > 0) {
            return true;
        }

        $source = $metadata['source'] ?? null;

        return is_string($source) && in_array(strtoupper($source), ['AGENT', 'SAVED_TASK', 'MULTITASK'], true);
    }

    /**
     * The channel a row came in on (WEB, WIDGET, WHATSAPP, MESSAGES_API, …)
     * from its metadata, WEB for rows written before channels were stored.
     *
     * @param array<string, mixed> $metadata
     */
    public static function channel(array $metadata): string
    {
        $source = $metadata['source'] ?? null;

        return is_string($source) && '' !== trim($source) ? strtoupper(trim($source)) : 'WEB';
    }

    public static function chatId(array $metadata): ?int
    {
        $chatId = $metadata['chat_id'] ?? $metadata['chatId'] ?? null;

        return is_numeric($chatId) && (int) $chatId > 0 ? (int) $chatId : null;
    }

    public static function agentId(array $metadata): ?int
    {
        $agentId = $metadata['agentId'] ?? $metadata['agent_id'] ?? null;

        return is_numeric($agentId) && (int) $agentId > 0 ? (int) $agentId : null;
    }
}
