<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * Turns shared content (location, venue, contact, poll, dice) into a plain
 * text the AI can answer, plus the raw payload that is kept on the message.
 */
final class TelegramStructuredInput
{
    private const COORDINATE_DECIMALS = 6;

    /**
     * @param array<string, mixed> $message
     *
     * @return array{0: string|null, 1: array<string, mixed>|null, 2: bool} text, payload, live location
     */
    public static function describe(array $message): array
    {
        $venue = $message['venue'] ?? null;
        if (is_array($venue)) {
            $location = is_array($venue['location'] ?? null) ? $venue['location'] : [];
            $coordinates = self::coordinates($location);
            $lines = ['Shared place: '.self::text($venue['title'] ?? '')];
            $address = self::text($venue['address'] ?? '');
            if ('' !== $address) {
                $lines[] = 'Address: '.$address;
            }
            if (null !== $coordinates) {
                $lines[] = $coordinates;
            }

            return [implode("\n", $lines), ['venue' => $venue], false];
        }

        $location = $message['location'] ?? null;
        if (is_array($location)) {
            $coordinates = self::coordinates($location);
            if (null === $coordinates) {
                return [null, null, false];
            }
            $live = isset($location['live_period']);

            return ['Shared '.($live ? 'live ' : '').'location: '.$coordinates, ['location' => $location], $live];
        }

        $contact = $message['contact'] ?? null;
        if (is_array($contact)) {
            $name = trim(self::text($contact['first_name'] ?? '').' '.self::text($contact['last_name'] ?? ''));
            $phone = self::text($contact['phone_number'] ?? '');
            $parts = array_values(array_filter([$name, $phone], static fn (string $part): bool => '' !== $part));

            return ['Shared contact: '.implode(', ', $parts), ['contact' => $contact], false];
        }

        $poll = $message['poll'] ?? null;
        if (is_array($poll)) {
            return [self::poll($poll), ['poll' => $poll], false];
        }

        $dice = $message['dice'] ?? null;
        if (is_array($dice)) {
            $value = $dice['value'] ?? null;

            return ['Rolled '.self::text($dice['emoji'] ?? '🎲').': '.(is_int($value) ? $value : '?'), ['dice' => $dice], false];
        }

        return [null, null, false];
    }

    /**
     * @param array<string, mixed> $poll
     */
    private static function poll(array $poll): string
    {
        $quiz = 'quiz' === ($poll['type'] ?? null);
        $lines = [($quiz ? 'Shared quiz: ' : 'Shared poll: ').self::text($poll['question'] ?? '')];
        $options = is_array($poll['options'] ?? null) ? $poll['options'] : [];
        $correct = $poll['correct_option_id'] ?? null;
        foreach (array_values($options) as $index => $option) {
            $label = is_array($option) ? self::text($option['text'] ?? '') : '';
            $line = ($index + 1).'. '.$label;
            if ($quiz && $correct === $index) {
                $line .= ' (correct answer)';
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
