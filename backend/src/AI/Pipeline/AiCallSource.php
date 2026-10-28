<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Origin of an {@see AiCall} passing through {@see AiCallPipeline}.
 */
enum AiCallSource: string
{
    case ChatWeb = 'chat_web';
    case ChatStream = 'chat_stream';
    case Widget = 'widget';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Telegram = 'telegram';
    case OpenAiApi = 'openai_api';
    case MessagesApi = 'messages_api';
    case SavedTask = 'saved_task';
    case Sorter = 'sorter';
    case Memory = 'memory';
    case Summary = 'summary';
    case Vectorize = 'vectorize';
    case Rerank = 'rerank';
    case Media = 'media';
    case VoiceLive = 'voice_live';
    case Probe = 'probe';
    case System = 'system';

    public static function fromChannel(?string $channel, self $default = self::ChatWeb): self
    {
        if (null === $channel || '' === trim($channel)) {
            return $default;
        }

        return match (strtoupper(trim($channel))) {
            'WEB', 'CHAT_WEB' => self::ChatWeb,
            'CHAT_STREAM' => self::ChatStream,
            'WIDGET' => self::Widget,
            'EMAIL', 'MAIL' => self::Email,
            'WHATSAPP' => self::WhatsApp,
            'TELEGRAM' => self::Telegram,
            'OPENAI_API' => self::OpenAiApi,
            'MESSAGES_API' => self::MessagesApi,
            'SAVED_TASK', 'MULTITASK', 'AGENT' => self::SavedTask,
            'SORTING', 'SORTER' => self::Sorter,
            'MEMORY', 'MEMORY_EXTRACTION', 'MEMORY_PARSE' => self::Memory,
            'SUMMARY', 'FILE_SUMMARY', 'DOC_SUMMARY', 'MESSAGE_DIGEST', 'CONDENSE' => self::Summary,
            'VECTORIZATION', 'VECTORIZE', 'EMBEDDINGS' => self::Vectorize,
            'RAG_RERANK', 'RERANK' => self::Rerank,
            'MEDIA', 'IMAGES', 'VIDEOS', 'AUDIOS', 'TRANSCRIPTION' => self::Media,
            'VOICE_LIVE', 'LIVE_VOICE' => self::VoiceLive,
            'PROBE' => self::Probe,
            default => $default,
        };
    }

    /**
     * Whether this source already runs through {@see AiCallPipeline} (`03_contracts.md` §1.5).
     *
     * `/v1/messages` (`MessagesApi`) and live voice (`VoiceLive`) stay `false`
     * in AU11 so their own writers keep metering under every mode until AU12
     * joins them.
     */
    public function isJoined(): bool
    {
        return true;
    }
}
