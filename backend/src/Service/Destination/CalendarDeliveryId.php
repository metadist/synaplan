<?php

declare(strict_types=1);

namespace App\Service\Destination;

/**
 * Stable id for one event delivered from a chat message.
 *
 * Several calendar steps in one reply each store their own .ics, and every
 * delivery is sent with file id 0. Keying only on the message id and event
 * index 0 makes every step the same Graph transactionId and the same CalDAV
 * UID, so the second event is rejected. The id follows the meeting itself:
 * a repeat of the same meeting still matches, a different meeting does not.
 */
final class CalendarDeliveryId
{
    public static function token(int $messageId, int $eventIndex, string $summary, string $start, string $end): string
    {
        $hash = substr(hash('sha256', implode("\n", [
            (string) $eventIndex,
            trim($summary),
            trim($start),
            trim($end),
        ])), 0, 16);

        return sprintf('synaplan-m%d-%s', $messageId, $hash);
    }
}
