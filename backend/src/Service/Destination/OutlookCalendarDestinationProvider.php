<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Microsoft\GraphClient;
use App\Service\Microsoft\GraphException;
use App\Service\Microsoft\MicrosoftOAuthConfig;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthReauthRequiredException;
use Psr\Log\LoggerInterface;

/**
 * Delivers the events of an .ics file into the connected Microsoft 365
 * (Outlook) calendar via Graph — the Graph sibling of
 * {@see CalDavDestinationProvider} (Phase M steps M4–M6).
 *
 * Idempotency by construction (same S13 contract as CalDAV): every event is
 * created with a deterministic Graph `transactionId` derived from the file and
 * source message; Graph answers 409 for a repeated transaction, which counts
 * as "already delivered", never a duplicate.
 *
 * Requires the `Calendars.ReadWrite` delegated scope. Connections consented
 * before the scope expansion carry only the old grant — those fail with
 * Unauthorized and a `reason: missing_scope` context so the UI can ask for a
 * reconnect instead of surfacing a Graph 403.
 */
final readonly class OutlookCalendarDestinationProvider implements DestinationProvider
{
    /** A delivery carries meeting extractions, not calendar migrations. */
    private const MAX_EVENTS = 50;

    public function __construct(
        private GraphClient $graph,
        private ConnectionRepository $connections,
        private LoggerInterface $logger,
    ) {
    }

    public function id(): string
    {
        return 'm365_calendar';
    }

    public function send(ShareableFile $file, array $params): DestinationResult
    {
        $connectionId = is_numeric($params['connection_id'] ?? null) ? (int) $params['connection_id'] : 0;
        $connection = $this->connections->findByIdAndOwner($connectionId, $file->ownerId);
        if (null === $connection || Connection::TYPE_M365 !== $connection->getType()) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, ['connection' => 'm365']);
        }

        if (!$this->hasCalendarScope($connection)) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, [
                'connection' => $connection->getName(),
                'reason' => 'missing_scope',
            ]);
        }

        if (!is_file($file->absolutePath)) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        $content = file_get_contents($file->absolutePath);
        $events = false === $content ? [] : $this->parseEvents($content);
        if ([] === $events) {
            return DestinationResult::failure(DestinationFailureCode::Unsupported, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }
        if (count($events) > self::MAX_EVENTS) {
            return DestinationResult::failure(DestinationFailureCode::TooLarge, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        $messageId = is_numeric($params['message_id'] ?? null) ? (int) $params['message_id'] : 0;
        $created = 0;
        $skipped = 0;
        $lastWebLink = '';

        try {
            foreach ($events as $index => $event) {
                $result = $this->graph->createEvent(
                    $connection,
                    transactionId: sprintf('synaplan-f%d-m%d-e%d', $file->fileId, $messageId, $index),
                    subject: $event['summary'],
                    start: $event['start'],
                    end: $event['end'],
                    timezone: 'UTC',
                    body: $event['description'],
                    location: $event['location'],
                    attendees: $event['attendees'],
                );
                if ($result['created']) {
                    ++$created;
                    $lastWebLink = $result['webLink'];
                } else {
                    ++$skipped;
                }
            }
        } catch (OAuthReauthRequiredException|OAuthException $e) {
            $this->logger->warning('Outlook calendar delivery failed: token exchange rejected', [
                'connection_id' => $connection->getId(),
                'file' => $file->name,
                'error' => $e->getMessage(),
            ]);

            return DestinationResult::failure(DestinationFailureCode::Unauthorized, [
                'connection' => $connection->getName(),
            ]);
        } catch (GraphException $e) {
            $code = $this->failureCode($e);
            $this->logger->warning('Outlook calendar delivery failed', [
                'connection_id' => $connection->getId(),
                'file' => $file->name,
                'error' => $e->getMessage(),
                'code' => $code->value,
            ]);

            return DestinationResult::failure($code, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        $context = [
            'connection' => $connection->getName(),
            'target' => 'outlook.office.com',
            'created' => (string) $created,
            'skipped' => (string) $skipped,
        ];
        if ('' !== $lastWebLink) {
            $context['webLink'] = $lastWebLink;
        }

        return DestinationResult::success((string) $created, $context);
    }

    /**
     * The granted scopes recorded on the connection at consent — a connection
     * from before the scope expansion lacks the calendar grant and must be
     * reconnected (never a raw Graph 403 in the user's face).
     */
    private function hasCalendarScope(Connection $connection): bool
    {
        return in_array(MicrosoftOAuthConfig::SCOPE_CALENDAR_WRITE, $connection->getScopes() ?? [], true);
    }

    private function failureCode(GraphException $e): DestinationFailureCode
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'HTTP 401'), str_contains($message, 'HTTP 403') => DestinationFailureCode::Unauthorized,
            str_contains($message, 'HTTP 429') => DestinationFailureCode::RateLimited,
            default => DestinationFailureCode::Unreachable,
        };
    }

    /**
     * Parse the VEVENT blocks of an iCalendar document into Graph event
     * fields. Lines are unfolded first (RFC 5545 §3.1); TEXT values are
     * unescaped per §3.3.11. Only UTC instants ("...Z") are produced by our
     * own {@see \App\Service\Calendar\CalendarEventService}; events without a
     * parseable DTSTART are skipped.
     *
     * @return list<array{summary: string, description: ?string, location: ?string, start: \DateTimeImmutable, end: \DateTimeImmutable, attendees: list<string>}>
     */
    private function parseEvents(string $ics): array
    {
        $unfolded = str_replace(["\r\n ", "\r\n\t", "\n ", "\n\t"], '', $ics);
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $unfolded, $matches);

        $events = [];
        foreach ($matches[1] as $block) {
            $start = $this->parseUtcInstant($this->property($block, 'DTSTART'));
            if (null === $start) {
                continue;
            }
            $end = $this->parseUtcInstant($this->property($block, 'DTEND')) ?? $start->add(new \DateInterval('PT1H'));

            $attendees = [];
            if (preg_match_all('/^ATTENDEE[^:]*:mailto:([^\r\n]+)/mi', $block, $attendeeMatches)) {
                foreach ($attendeeMatches[1] as $address) {
                    $address = trim($address);
                    if (false !== filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                        $attendees[] = $address;
                    }
                }
            }

            $summary = $this->unescapeText($this->property($block, 'SUMMARY') ?? '');
            $description = $this->property($block, 'DESCRIPTION');
            $location = $this->property($block, 'LOCATION');

            $events[] = [
                'summary' => '' !== $summary ? $summary : 'Meeting',
                'description' => null !== $description ? $this->unescapeText($description) : null,
                'location' => null !== $location ? $this->unescapeText($location) : null,
                'start' => $start,
                'end' => $end,
                'attendees' => $attendees,
            ];
        }

        return $events;
    }

    /** First value of a property, parameters ignored (SUMMARY;LANGUAGE=… : value). */
    private function property(string $block, string $name): ?string
    {
        if (1 === preg_match('/^'.preg_quote($name, '/').'[;:]([^\r\n]*)/mi', $block, $m)) {
            $value = $m[1];
            // Strip any parameters left before the value separator.
            $colon = strpos($value, ':');
            if (false !== $colon && 1 === preg_match('/^[A-Z-]+=/i', $value)) {
                $value = substr($value, $colon + 1);
            }

            return trim($value);
        }

        return null;
    }

    private function parseUtcInstant(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || 1 !== preg_match('/^\d{8}T\d{6}Z$/', $value)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new \DateTimeZone('UTC'));

        return false !== $parsed ? $parsed : null;
    }

    /** Reverse of RFC 5545 §3.3.11 TEXT escaping. */
    private function unescapeText(string $value): string
    {
        $value = str_replace(['\\n', '\\N'], "\n", $value);
        $value = str_replace(['\\,', '\\;'], [',', ';'], $value);

        return str_replace('\\\\', '\\', $value);
    }
}
