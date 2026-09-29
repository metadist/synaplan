<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * Turns shared content (location, venue, contact, poll, dice) into a plain
 * text the AI can answer, in the person's language, plus the raw payload
 * that is kept on the message.
 */
final class TelegramStructuredInput
{
    private const COORDINATE_DECIMALS = 6;

    /**
     * The raw shared content, or null when the message carries none.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>|null
     */
    public static function payload(array $message): ?array
    {
        foreach (['venue', 'location', 'contact', 'poll', 'dice'] as $kind) {
            $value = $message[$kind] ?? null;
            if (!is_array($value)) {
                continue;
            }
            if ('location' === $kind && null === self::coordinates($value)) {
                return null;
            }

            return [$kind => $value];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function isShared(array $message): bool
    {
        return null !== self::payload($message);
    }

    /**
     * @param array<string, mixed>                                $message
     * @param callable(string, array<string, string|int>): string $say     translation of a copy key
     */
    public static function describe(array $message, callable $say): ?string
    {
        $payload = self::payload($message);
        if (null === $payload) {
            return null;
        }
        $kind = (string) array_key_first($payload);
        $value = $payload[$kind];
        if (!is_array($value)) {
            return null;
        }

        return match ($kind) {
            'venue' => self::venue($value, $say),
            'location' => $say(isset($value['live_period']) ? 'shared_live_location' : 'shared_location', ['%coordinates%' => (string) self::coordinates($value)]),
            'contact' => self::contact($value, $say),
            'poll' => self::poll($value, $say),
            default => $say('shared_dice', [
                '%emoji%' => '' !== self::text($value['emoji'] ?? '') ? self::text($value['emoji']) : '🎲',
                '%value%' => is_int($value['value'] ?? null) ? $value['value'] : '?',
            ]),
        };
    }

    /**
     * @param array<mixed>                                        $venue
     * @param callable(string, array<string, string|int>): string $say
     */
    private static function venue(array $venue, callable $say): string
    {
        $lines = [$say('shared_place', ['%title%' => self::text($venue['title'] ?? '')])];
        $address = self::text($venue['address'] ?? '');
        if ('' !== $address) {
            $lines[] = $say('shared_address', ['%address%' => $address]);
        }
        $coordinates = self::coordinates(is_array($venue['location'] ?? null) ? $venue['location'] : []);
        if (null !== $coordinates) {
            $lines[] = $coordinates;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed>                                        $contact
     * @param callable(string, array<string, string|int>): string $say
     */
    private static function contact(array $contact, callable $say): string
    {
        $name = trim(self::text($contact['first_name'] ?? '').' '.self::text($contact['last_name'] ?? ''));
        $phone = self::text($contact['phone_number'] ?? '');
        $parts = array_values(array_filter([$name, $phone], static fn (string $part): bool => '' !== $part));

        return $say('shared_contact', ['%contact%' => implode(', ', $parts)]);
    }

    /**
     * @param array<mixed>                                        $poll
     * @param callable(string, array<string, string|int>): string $say
     */
    private static function poll(array $poll, callable $say): string
    {
        $quiz = 'quiz' === ($poll['type'] ?? null);
        $lines = [$say($quiz ? 'shared_quiz' : 'shared_poll', ['%question%' => self::text($poll['question'] ?? '')])];
        $options = is_array($poll['options'] ?? null) ? $poll['options'] : [];
        $correct = $poll['correct_option_id'] ?? null;
        foreach (array_values($options) as $index => $option) {
            $label = is_array($option) ? self::text($option['text'] ?? '') : '';
            $line = ($index + 1).'. '.$label;
            if ($quiz && $correct === $index) {
                $line .= ' '.$say('shared_correct_answer', []);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $location
     */
    private static function coordinates(array $location): ?string
    {
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $lat = round((float) $lat, self::COORDINATE_DECIMALS);
        $lng = round((float) $lng, self::COORDINATE_DECIMALS);

        return sprintf('%s, %s (https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=16/%s/%s)', $lat, $lng, $lat, $lng, $lat, $lng);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value) ?? $value) : '';
    }
}
