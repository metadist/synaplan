<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\User;

/**
 * What the voice model is told. Persona and the recent chat only.
 * Memories, files and tools stay in Synaplan and come back through the answer.
 */
final class VoiceLiveInstructions
{
    private const HISTORY_CHARS = 24000;

    public function build(User $user, string $history, ?string $locale = null): string
    {
        $name = trim($user->getDisplayName());
        if ('' === $name) {
            $name = 'the user';
        }
        $spoken = substr($user->getLocale(), 0, 12);
        if (null !== $locale && '' !== trim($locale)) {
            $spoken = substr(trim($locale), 0, 12);
        }
        if ('' === $spoken) {
            $spoken = 'en';
        }
        $history = trim(mb_substr($history, -self::HISTORY_CHARS));
        $recent = '' === $history ? 'None yet.' : $history;

        return <<<TEXT
        You are Synaplan's voice for {$name}. Speak in {$spoken}. Keep every spoken reply to two short sentences.
        You never answer factual questions, files, memories, calendar, mail, or tools yourself. Ask Synaplan and speak only the answer Synaplan returns.
        Recent chat:
        {$recent}
        TEXT;
    }
}
