<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Repository\ConnectionRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Dav\CalDavClient;
use App\Service\Dav\DavConnectionResolver;
use App\Service\Dav\DavException;

/**
 * Delivers the events of an .ics file into a CalDAV calendar (connector plan
 * 07 C12 — the sovereign calendar). The generated `.ics` download stays the
 * no-connection fallback; this provider is the "actually put it in my
 * calendar" upgrade.
 *
 * Idempotency by construction (sign-off S13): every event gets a
 * deterministic UID derived from the file and source message, the calendar is
 * queried for that UID before writing, and the PUT itself is create-only —
 * an event that already exists is counted as delivered, never duplicated.
 */
final readonly class CalDavDestinationProvider implements DestinationProvider
{
    /** A Saved Task run delivers meeting extractions, not calendar migrations. */
    private const MAX_EVENTS = 50;

    public function __construct(
        private CalDavClient $calDav,
        private ConnectionRepository $connections,
        private CredentialVaultInterface $vault,
    ) {
    }

    public function id(): string
    {
        return 'caldav';
    }

    public function send(ShareableFile $file, array $params): DestinationResult
    {
        $connectionId = is_numeric($params['connection_id'] ?? null) ? (int) $params['connection_id'] : 0;
        $connection = $this->connections->findByIdAndOwner($connectionId, $file->ownerId);
        if (null === $connection || 'caldav' !== $connection->getType()) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, ['connection' => 'caldav']);
        }

        $target = DavConnectionResolver::resolve($connection, $this->vault);
        if (null === $target) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, ['connection' => $connection->getName()]);
        }

        if (!is_file($file->absolutePath)) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        $content = file_get_contents($file->absolutePath);
        $events = false === $content ? [] : $this->extractEvents($content);
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

        try {
            foreach ($events as $index => $event) {
                $uid = $this->deterministicUid($file->fileId, $messageId, $index);
                if ($this->calDav->eventExists($target, $uid)) {
                    ++$skipped;
                    continue;
                }
                if ($this->calDav->putEvent($target, $uid, $this->wrapEvent($this->withUid($event, $uid)))) {
                    ++$created;
                } else {
                    ++$skipped;
                }
            }
        } catch (DavException $e) {
            return DestinationResult::failure($e->toFailureCode(), [
                'target' => $file->name,
                'connection' => $connection->getName(),
            ]);
        }

        return DestinationResult::success((string) $created, [
            'connection' => $connection->getName(),
            'target' => $target->host(),
            'created' => (string) $created,
            'skipped' => (string) $skipped,
        ]);
    }

    /**
     * Deterministic per-event UID from the delivery identity, so the same file
     * re-sent (manually or by a re-run schedule) addresses the same calendar
     * resources.
     */
    private function deterministicUid(int $fileId, int $messageId, int $eventIndex): string
    {
        return sprintf('synaplan-f%d-m%d-e%d@synaplan', $fileId, $messageId, $eventIndex);
    }

    /**
     * VEVENT blocks of an iCalendar document, with folded lines unfolded first
     * (RFC 5545 §3.1) so property rewriting sees whole lines.
     *
     * @return list<string>
     */
    private function extractEvents(string $ics): array
    {
        $unfolded = str_replace(["\r\n ", "\r\n\t", "\n ", "\n\t"], '', $ics);
        preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $unfolded, $matches);

        return $matches[0];
    }

    /** Replace (or insert) the UID property of one unfolded VEVENT block. */
    private function withUid(string $event, string $uid): string
    {
        $withoutUid = preg_replace('/^UID:[^\r\n]*(\r?\n)?/m', '', $event);
        $event = is_string($withoutUid) ? $withoutUid : $event;

        return (string) preg_replace('/^BEGIN:VEVENT/', "BEGIN:VEVENT\r\nUID:".$uid, $event);
    }

    /** A calendar object resource must be a full VCALENDAR, not a bare VEVENT. */
    private function wrapEvent(string $event): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Synaplan//Calendar 1.0//EN',
            $event,
            'END:VCALENDAR',
        ])."\r\n";
    }
}
