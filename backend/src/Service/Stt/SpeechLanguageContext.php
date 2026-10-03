<?php

declare(strict_types=1);

namespace App\Service\Stt;

use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\AccountLanguage;

/**
 * Languages a transcription may treat as expected for one user.
 *
 * The set is the UI language, an explicit API language, the channel language
 * (Telegram language_code stored on the message), the account language, and
 * the languages of recent messages. English is included whenever any other
 * language is known, so a correctly detected English note is not re-run in
 * the account language (#2288). The account language is the stored preference
 * only — the English fallback of {@see User::getLocale()} is not evidence.
 *
 * @phpstan-type SpeechLanguages array{expected: list<string>, hint: ?string}
 */
final class SpeechLanguageContext
{
    public function __construct(
        private UserRepository $users,
        private MessageRepository $messages,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return SpeechLanguages
     */
    public function resolve(?int $userId, array $options, ?string $explicit): array
    {
        $profile = null;
        $recent = [];
        if (null !== $userId && $userId > 0) {
            $user = $this->users->find($userId);
            if ($user instanceof User) {
                $profile = $user->getPreferredLanguage();
            }
            $recent = $this->messages->findRecentLanguageCodes($userId);
        }

        return self::fromSignals($options, $explicit, $profile, $recent);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $recent
     *
     * @return SpeechLanguages
     */
    public static function fromSignals(array $options, ?string $explicit, ?string $profile, array $recent): array
    {
        $decider = new TranscriptionLanguageDecider();
        $expected = [];
        foreach (['ui_language', 'channel_language'] as $key) {
            self::push($decider, $expected, $options[$key] ?? null);
        }
        if (isset($options['expected_languages']) && is_array($options['expected_languages'])) {
            foreach ($options['expected_languages'] as $code) {
                self::push($decider, $expected, $code);
            }
        }
        self::push($decider, $expected, $profile);
        // Recent message languages are only trusted inside the app's own set.
        // A bad transcript used to store "ru" and would otherwise count as a
        // language the user speaks, which skips the corrective second pass.
        foreach ($recent as $code) {
            self::push($decider, $expected, AccountLanguage::normalize($code));
        }

        $explicitCode = $decider->code($explicit);
        if (null !== $explicitCode) {
            self::push($decider, $expected, $explicitCode);
        }

        if ([] !== $expected && !in_array('en', $expected, true)) {
            $expected[] = 'en';
        }

        return [
            'expected' => $expected,
            'hint' => $explicitCode ?? self::preferredHint($expected),
        ];
    }

    /**
     * @param list<string> $expected
     */
    private static function push(TranscriptionLanguageDecider $decider, array &$expected, mixed $value): void
    {
        $code = $decider->code($value);
        if (null !== $code && !in_array($code, $expected, true)) {
            $expected[] = $code;
        }
    }

    /**
     * @param list<string> $expected
     */
    private static function preferredHint(array $expected): ?string
    {
        foreach ($expected as $code) {
            if ('en' !== $code) {
                return $code;
            }
        }

        return $expected[0] ?? null;
    }
}
