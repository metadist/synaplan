<?php

namespace App\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;

/**
 * What an inbound WhatsApp message says, read from the webhook alone: its
 * text (the body, a caption, a media placeholder, a location or contact
 * card), its media id, and whether it is answered with a spoken reply.
 */
final class WhatsAppInboundContent
{
    /**
     * Determine if we should send an audio response (MP3) back to the user.
     * This is true for voice messages (audio without meaningful caption)
     * and video messages (as requested by user).
     */
    public static function shouldSendAudioResponse(IncomingMessageDto $dto): bool
    {
        if ('audio' === $dto->type) {
            // Check for caption (some audio messages might have text)
            $caption = $dto->incomingMsg['audio']['caption'] ?? null;

            // Voice-only if no caption or caption is just whitespace
            return empty(trim((string) $caption));
        }

        if ('video' === $dto->type) {
            // User requested that video answers are sent as MP3
            return true;
        }

        return false;
    }

    /**
     * Extract initial message text from the WhatsApp message.
     * For media messages, this returns captions or placeholders that will be
     * replaced with transcribed/extracted content during media processing.
     *
     * The `default` branch is defense-in-depth only: the outer
     * {@see handleUnsupportedTypeIfNeeded()} filter (issue #633) already
     * short-circuits any type that is not in {@see SUPPORTED_MESSAGE_TYPES},
     * so this method should never see one in production. The fallback
     * string stays around so direct callers (e.g. reflection-driven
     * unit tests) still get a deterministic value instead of an
     * UnhandledMatchError.
     */
    public static function extractMessageText(IncomingMessageDto $dto): string
    {
        return match ($dto->type) {
            'text' => $dto->incomingMsg['text']['body'] ?? '',
            'image' => $dto->incomingMsg['image']['caption'] ?? '[Image]',
            'sticker' => '[Sticker]', // Treated like images, analyzed via Vision AI
            'audio' => '[Audio message]', // Will be replaced with transcription
            'video' => $dto->incomingMsg['video']['caption'] ?? '[Video]', // Audio track will be transcribed
            'document' => $dto->incomingMsg['document']['caption'] ?? '[Document]',
            'location' => self::formatLocationMessage($dto),
            'contacts' => self::formatContactsMessage($dto),
            default => "[Unsupported message type: {$dto->type}]",
        };
    }

    /**
     * Format a location message into a readable text for AI processing.
     * WhatsApp location payload: { latitude, longitude, name?, address?, url? }.
     */
    private static function formatLocationMessage(IncomingMessageDto $dto): string
    {
        $location = $dto->incomingMsg['location'] ?? [];

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;

        if (null === $latitude || null === $longitude) {
            return '[Location shared - coordinates not available]';
        }

        $parts = ["📍 Standort geteilt: {$latitude}, {$longitude}"];

        // Include location name if available (e.g., "Philz Coffee")
        if (!empty($location['name'])) {
            $parts[] = "Name: {$location['name']}";
        }

        // Include address if available
        if (!empty($location['address'])) {
            $parts[] = "Adresse: {$location['address']}";
        }

        // Include Google Maps URL for context
        if (!empty($location['url'])) {
            $parts[] = "Maps: {$location['url']}";
        } else {
            // Generate a Google Maps URL if not provided
            $parts[] = "Maps: https://www.google.com/maps?q={$latitude},{$longitude}";
        }

        return implode("\n", $parts);
    }

    /**
     * Format a contacts message into a readable text for AI processing.
     * WhatsApp contacts payload: array of contact objects with name, phones, etc.
     */
    private static function formatContactsMessage(IncomingMessageDto $dto): string
    {
        $contacts = $dto->incomingMsg['contacts'] ?? [];

        if (empty($contacts)) {
            return '[Contact shared - details not available]';
        }

        $parts = ['📇 Kontakt(e) geteilt:'];

        foreach ($contacts as $contact) {
            $name = $contact['name']['formatted_name']
                ?? $contact['name']['first_name'] ?? 'Unbekannt';
            $contactInfo = ["- {$name}"];

            // Add phone numbers
            if (!empty($contact['phones'])) {
                foreach ($contact['phones'] as $phone) {
                    $phoneType = $phone['type'] ?? 'phone';
                    $phoneNumber = $phone['phone'] ?? $phone['wa_id'] ?? '';
                    if ($phoneNumber) {
                        $contactInfo[] = "  {$phoneType}: {$phoneNumber}";
                    }
                }
            }

            // Add emails
            if (!empty($contact['emails'])) {
                foreach ($contact['emails'] as $email) {
                    $emailType = $email['type'] ?? 'email';
                    $emailAddress = $email['email'] ?? '';
                    if ($emailAddress) {
                        $contactInfo[] = "  {$emailType}: {$emailAddress}";
                    }
                }
            }

            $parts[] = implode("\n", $contactInfo);
        }

        return implode("\n", $parts);
    }

    public static function extractMediaId(IncomingMessageDto $dto): ?string
    {
        return $dto->incomingMsg[$dto->type]['id'] ?? null;
    }
}
