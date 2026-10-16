<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

/**
 * Everyday words for settings whose description uses the technical term
 * ("Enable audio transcription" for speech to text). They feed the keyword
 * matcher and the embedded catalog text, so a multilingual embedding model
 * also maps "Spracherkennung" onto them. Added where app:search:eval missed.
 */
final class SettingSearchTerms
{
    private const TERMS = [
        'WHISPER_ENABLED' => 'speech to text, speech recognition, dictation, voice messages, transcribe audio',
        'WHISPER_DEFAULT_MODEL' => 'speech to text model, speech recognition model',
        'SYNAPLAN_TTS_URL' => 'text to speech, read aloud, voice output',
        'ELEVENLABS_API_KEY' => 'text to speech, voice output',
        'BRAVE_SEARCH_ENABLED' => 'web search, internet search, search the web',
        'BRAVE_SEARCH_API_KEY' => 'web search, internet search, search the web',
        'MAILER_DSN' => 'smtp, outgoing email, mail server',
        'GUEST_CHAT_ENABLED' => 'anonymous chat, chat without login',
        'REGISTRATION_ENABLED' => 'sign up, self-service registration',
    ];

    public static function of(string $key): string
    {
        return self::TERMS[$key] ?? '';
    }
}
