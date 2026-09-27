<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Why a recording did not become text.
 *
 * Setup reasons name the one next step (turn local speech on, or choose a
 * speech model). {@see TRANSCRIPTION_FAILED} means speech is set up and the
 * recording itself could not be read. None of these values is an HTTP status
 * or a provider error string.
 */
final class SpeechFailure
{
    public const SPEECH_OFF = 'speech_off';

    public const BINARY_MISSING = 'binary_missing';

    public const MODEL_MISSING = 'model_missing';

    public const TRANSCRIPTION_FAILED = 'transcription_failed';

    /**
     * @var list<string>
     */
    public const CODES = [
        self::SPEECH_OFF,
        self::BINARY_MISSING,
        self::MODEL_MISSING,
        self::TRANSCRIPTION_FAILED,
    ];

    public static function normalize(?string $code): string
    {
        return in_array($code, self::CODES, true) ? $code : self::TRANSCRIPTION_FAILED;
    }
}
