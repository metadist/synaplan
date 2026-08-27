<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deployment-level switch for the browser's Web Speech API as the speech-to-
 * text path of the chat input.
 *
 * The Web Speech API streams the microphone audio to the browser vendor's
 * cloud recognizer (Google for Chrome). An operator running an air-gapped or
 * data-residency-bound instance sets `WEB_SPEECH_ENABLED=false` so the
 * frontend never offers it and instead records for the server-side
 * transcription path (local Whisper.cpp or an API speech-to-text model),
 * or hides the microphone entirely when the server cannot transcribe.
 *
 * Chromium builds without Google API keys expose the SpeechRecognition
 * constructor but never deliver results, which is a second reason to be able
 * to turn the path off: feature detection cannot tell the two apart.
 *
 * Read by the public runtime-config response only; there is no server-side
 * enforcement because the audio never reaches this backend on that path.
 *
 * Defaults ON when unset so existing installs are unchanged. Env flag like
 * {@see RegistrationConfig} and {@see GuestChatConfig}: an install-shape
 * decision, not a runtime admin preference.
 */
final readonly class WebSpeechConfig
{
    public function isEnabled(): bool
    {
        $raw = trim((string) ($_ENV['WEB_SPEECH_ENABLED'] ?? ''));

        if ('' === $raw) {
            return true;
        }

        // Only an explicit falsey value ('false'/'0'/'off'/'no') disables it;
        // an unrecognized value falls back to the safe default.
        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }
}
