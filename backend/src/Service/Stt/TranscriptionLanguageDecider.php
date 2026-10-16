<?php

declare(strict_types=1);

namespace App\Service\Stt;

/**
 * Decides whether a speech-to-text call needs a second pass with a language hint.
 *
 * The first call is always unhinted. Whisper is right often enough that forcing
 * one language would garble a note in any other language. A second call happens
 * only when the detected language is outside the user's expected set (#2288).
 * A recording that mixes two languages is out of scope: one file still yields
 * one language.
 */
final class TranscriptionLanguageDecider
{
    /**
     * Whisper verbose_json uses ISO-639-1, but some providers return the
     * English name. BLANG is two characters, so the stored value has to be a code.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'german' => 'de',
        'english' => 'en',
        'spanish' => 'es',
        'french' => 'fr',
        'turkish' => 'tr',
        'russian' => 'ru',
        'italian' => 'it',
        'portuguese' => 'pt',
        'dutch' => 'nl',
        'polish' => 'pl',
        'ukrainian' => 'uk',
        'chinese' => 'zh',
        'japanese' => 'ja',
        'korean' => 'ko',
        'arabic' => 'ar',
    ];

    /**
     * @param callable(?string): array<string, mixed> $transcribe hint, or null for the unhinted pass
     * @param list<string>                            $expected   ISO-639-1 codes the user may speak
     *
     * @return array<string, mixed>
     */
    public function transcribe(callable $transcribe, array $expected, ?string $hint): array
    {
        $expected = $this->codes($expected);
        $hint = $this->code($hint);

        $first = $transcribe(null);
        $detected = $this->code($first['language'] ?? null);
        if (null !== $detected) {
            $first['language'] = $detected;
        }

        if (null === $hint || [] === $expected || (null !== $detected && in_array($detected, $expected, true))) {
            return $first;
        }

        try {
            $second = $transcribe($hint);
        } catch (\Throwable) {
            return $first;
        }

        $secondDetected = $this->code($second['language'] ?? null);
        if (null !== $secondDetected) {
            $second['language'] = $secondDetected;
        }
        $second['retried_with_language'] = $hint;
        $second['first_detected_language'] = $detected;
        $second['first_pass_duration'] = (float) ($first['duration'] ?? 0);

        return $second;
    }

    public function code(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(str_replace('_', '-', trim($value)));
        if ('' === $value || 'unknown' === $value || 'nn' === $value) {
            return null;
        }

        $base = explode('-', $value, 2)[0];
        if (isset(self::NAMES[$base])) {
            return self::NAMES[$base];
        }

        return 1 === preg_match('/^[a-z]{2}$/', $base) ? $base : null;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function codes(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = $this->code($code);
            if (null !== $code && !in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }

        return $normalized;
    }
}
