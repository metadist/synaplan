<?php

declare(strict_types=1);

namespace App\Service\WhatsApp;

/**
 * Install-wide WhatsApp numbers people write to for phone verification.
 *
 * Parsed from WHATSAPP_DISPLAY_NUMBERS (comma-separated E.164). Empty is
 * valid: the UI then says the numbers are not listed, instead of inventing
 * a hosted Synaplan number on a self-hosted install.
 */
final readonly class WhatsAppDisplayNumbers
{
    private const MIN_DIGITS = 8;
    private const MAX_DIGITS = 15;
    private const WA_ME = 'https://wa.me/';

    public function __construct(
        private string $raw = '',
    ) {
    }

    /**
     * @return list<array{e164: string, wa_me: string}>
     */
    public function list(): array
    {
        $seen = [];
        $numbers = [];
        foreach (explode(',', $this->raw) as $part) {
            $number = $this->normalize($part);
            if (null === $number) {
                continue;
            }
            if (isset($seen[$number['e164']])) {
                continue;
            }
            $seen[$number['e164']] = true;
            $numbers[] = $number;
        }

        return $numbers;
    }

    /**
     * @return array{e164: string, wa_me: string}|null
     */
    private function normalize(string $part): ?array
    {
        $digits = preg_replace('/\D/', '', $part) ?? '';
        $length = strlen($digits);
        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        return [
            'e164' => '+'.$digits,
            'wa_me' => self::WA_ME.$digits,
        ];
    }
}
