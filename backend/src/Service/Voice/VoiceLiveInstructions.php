<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\User;
use App\Service\AccountLanguage;

/**
 * What the voice model is told. Persona and the recent chat only.
 * Memories, files and tools stay in Synaplan and come back through the answer.
 *
 * The app language is only the fallback. A hard "Speak in en" made the model
 * answer English small talk while the person was speaking German, and then
 * German again as soon as a longer sentence made the audio unambiguous.
 */
final class VoiceLiveInstructions
{
    private const HISTORY_CHARS = 24000;

    /** @var array<string, string> */
    private const LANGUAGE_NAMES = [
        'de' => 'German',
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'tr' => 'Turkish',
    ];

    public function build(User $user, string $history, ?string $locale = null): string
    {
        $name = trim($user->getDisplayName());
        if ('' === $name) {
            $name = 'the user';
        }
        $fallback = self::LANGUAGE_NAMES[self::languageCode($user, $locale)];
        $history = trim(mb_substr($history, -self::HISTORY_CHARS));
        $recent = '' === $history ? 'None yet.' : $history;

        return <<<TEXT
        You are Synaplan's voice for {$name}. Reply in the language the person is speaking right now.
        Deutsch, wenn die Person Deutsch spricht. English, when they speak English. Español, si habla español. Français, si elle parle français. Türkçe, kişi Türkçe konuşuyorsa.
        These instructions are written in English. That does not choose the reply. Until you can tell which language they are speaking, use {$fallback}. Keep every spoken reply to two short sentences.
        Answer these yourself, at once, without asking Synaplan: greetings, small talk, how you are, thanks, goodbyes, "repeat that", "slower", "louder", and asking what they need. Asking Synaplan takes several seconds, so never ask it for these.
        Ask Synaplan for everything else: facts, current events, the web, their files, memories, calendar, mail, or tools. Never answer those yourself. Speak only the answer Synaplan returns, in the language the person is speaking.
        Recent chat:
        {$recent}
        TEXT;
    }

    private static function languageCode(User $user, ?string $locale): string
    {
        $candidate = null !== $locale && '' !== trim($locale) ? $locale : $user->getLocale();

        return AccountLanguage::normalize($candidate) ?? 'en';
    }
}
