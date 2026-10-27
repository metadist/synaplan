<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Capability of an {@see AiCall} passing through {@see AiCallPipeline}.
 */
enum AiCapability: string
{
    case Chat = 'chat';
    case ChatStream = 'chat_stream';
    case Embed = 'embed';
    case EmbedBatch = 'embed_batch';
    case Vision = 'vision';
    case Image = 'image';
    case VideoStart = 'video_start';
    case VideoPoll = 'video_poll';
    case Transcribe = 'transcribe';
    case Synthesize = 'synthesize';
    case SynthesizeStream = 'synthesize_stream';
    case Passthrough = 'passthrough';
    case VoiceSession = 'voice_session';
    case Probe = 'probe';

    /**
     * Capability name used by {@see \App\AI\Health\ModelHealthRecorder}.
     */
    public function healthCapability(): ?string
    {
        return match ($this) {
            self::Chat, self::ChatStream, self::Passthrough => 'chat',
            self::Embed, self::EmbedBatch => 'embedding',
            self::Vision => 'vision',
            self::Image => 'image_generation',
            self::VideoStart => 'video_generation',
            self::Transcribe => 'speech_to_text',
            self::Synthesize, self::SynthesizeStream => 'text_to_speech',
            self::VideoPoll, self::VoiceSession, self::Probe => null,
        };
    }

    /**
     * Service name used by {@see \App\Service\Infrastructure\CircuitBreaker},
     * or null when the capability bypasses the circuit breaker (e.g. embedding
     * uses its own cache + fallback; async video poll/start are non-blocking).
     */
    public function circuitBreakerService(string $providerName): ?string
    {
        return match ($this) {
            self::Chat, self::ChatStream, self::Passthrough => 'ai_provider_'.$providerName,
            self::Vision => 'ai_provider_vision_'.$providerName,
            self::Image => 'ai_provider_image_'.$providerName,
            self::VideoStart => 'ai_provider_video_'.$providerName,
            self::Transcribe => 'ai_provider_stt_'.$providerName,
            self::Synthesize => 'ai_provider_tts_'.$providerName,
            self::Embed, self::EmbedBatch, self::VideoPoll, self::SynthesizeStream, self::VoiceSession, self::Probe => null,
        };
    }
}
