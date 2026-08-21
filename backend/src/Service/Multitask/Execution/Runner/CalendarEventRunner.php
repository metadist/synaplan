<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Calendar\CalendarEventService;
use App\Service\Destination\RequestedCalendarDelivery;
use App\Service\File\FileStorageService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use Psr\Log\LoggerInterface;

/**
 * `calendar_event` runner — turns planner-resolved event params into a .ics
 * meeting file attached to the reply, and (when the planner names a calendar
 * channel) creates the event directly in that connected calendar — CalDAV or
 * Outlook (Phase M step M6).
 *
 * The planner resolves relative phrases ("tomorrow at 15:00") into an absolute
 * local datetime + IANA timezone using the time context injected into its system
 * prompt (server now + message receipt time). This runner just renders + stores
 * the calendar file; it picks no model. A failed calendar delivery degrades to
 * the .ics download with an honest note — it never sinks the node.
 *
 * Expected node params:
 *   - title            string
 *   - start            ISO-8601 local datetime, e.g. "2026-06-09T15:00:00"
 *   - end              ISO-8601 local datetime (optional)
 *   - duration_minutes int (optional; used when no end; default 60)
 *   - timezone         IANA tz, e.g. "Europe/Berlin" (optional; server tz fallback)
 *   - location         string (optional)
 *   - description      string (optional)
 *   - attendees        list<string>|string (optional; emails become ATTENDEEs)
 *   - organizer_email  string (optional)
 *   - channel          calendar channel name from [CHANNELLIST] (optional;
 *                      e.g. "outlook", "calendar" — delivers the event into
 *                      that connected calendar)
 */
final readonly class CalendarEventRunner implements TaskRunner
{
    /**
     * User-facing confirmation line, keyed by detected message language
     * (frontend-supported set, English fallback). The datetime is rendered
     * in a locale-neutral ISO shape on purpose — no intl dependency.
     */
    private const INVITE_TEXT = [
        'en' => 'Calendar invite "%s" — %s (%s).',
        'de' => 'Kalendereinladung "%s" — %s (%s).',
        'es' => 'Invitación de calendario "%s" — %s (%s).',
        'tr' => 'Takvim daveti "%s" — %s (%s).',
    ];

    public function __construct(
        private CalendarEventService $calendarService,
        private FileStorageService $fileStorage,
        private RequestedCalendarDelivery $calendarDelivery,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::CalendarEvent];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(Capability::CalendarEvent, 'Create a calendar meeting/invite as a downloadable .ics file. params: title, start (ISO-8601 local datetime, e.g. "2026-06-09T15:00:00"), end (ISO-8601) or duration_minutes, timezone (IANA, e.g. "Europe/Berlin"), location, description, attendees (list of names/emails). Resolve relative times against the current time context below. When the user asks to put the event INTO a connected calendar and the Connected channels list has a calendar channel, also set params.channel to that channel name (e.g. "outlook") — never invent one.'),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        // The planner is free to place the event fields under `params` OR under
        // `inputs` — even strong models routinely emit
        // {title, start, timezone, …} as `inputs` because they read like data.
        // Accept both: resolved inputs first, then params override.
        $params = $this->effectiveParams($node, $context);

        $title = is_string($params['title'] ?? null) && '' !== trim($params['title'])
            ? trim($params['title'])
            : 'Meeting';

        $tzName = is_string($params['timezone'] ?? null) && '' !== trim($params['timezone'])
            ? trim($params['timezone'])
            : date_default_timezone_get();
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
            $tzName = 'UTC';
        }

        $startStr = is_string($params['start'] ?? null) ? trim($params['start']) : '';
        if ('' === $startStr) {
            return NodeResult::failed('calendar event: missing start time');
        }
        try {
            $start = new \DateTimeImmutable($startStr, $tz);
        } catch (\Throwable $e) {
            return NodeResult::failed('calendar event: invalid start time: '.$e->getMessage());
        }

        $end = $this->resolveEnd($params, $start, $tz);

        $ics = $this->calendarService->buildIcs(
            title: $title,
            start: $start,
            end: $end,
            description: is_string($params['description'] ?? null) ? $params['description'] : null,
            location: is_string($params['location'] ?? null) ? $params['location'] : null,
            attendees: $this->normalizeAttendees($params['attendees'] ?? null),
            organizerEmail: is_string($params['organizer_email'] ?? null) ? $params['organizer_email'] : null,
            timezoneLabel: $tzName,
        );

        $filename = 'meeting_'.$start->format('Ymd_His').'.ics';
        $stored = $this->fileStorage->storeRawContent($ics, $context->userId, $filename, 'text/calendar');
        if (!$stored['success'] || '' === $stored['path']) {
            $this->logger->warning('CalendarEventRunner: failed to store ics', ['error' => $stored['error'] ?? null]);

            return NodeResult::failed('calendar event: could not save the .ics file');
        }

        $file = [
            'path' => '/api/v1/files/uploads/'.$stored['path'],
            'type' => 'document',
            'local_path' => $stored['path'],
        ];

        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');
        $template = self::INVITE_TEXT[$language] ?? self::INVITE_TEXT['en'];
        $text = sprintf($template, $title, $start->format('Y-m-d H:i'), $tzName);

        $metadata = [
            'media_type' => 'document',
            'calendar_event' => [
                'title' => $title,
                'start' => $start->format(\DateTimeInterface::ATOM),
                'end' => $end->format(\DateTimeInterface::ATOM),
                'timezone' => $tzName,
            ],
        ];

        // Optional delivery into a connected calendar (params.channel from
        // [CHANNELLIST]). A failure degrades to the .ics download with an
        // honest note; the node itself never fails on delivery.
        $channel = is_string($params['channel'] ?? null) ? trim($params['channel']) : '';
        if ('' !== $channel) {
            $delivery = $this->calendarDelivery->send(
                (int) ($context->userId ?? $context->message->getUserId()),
                rtrim($this->uploadDir, '/').'/'.ltrim($stored['path'], '/'),
                $filename,
                (int) ($context->message->getId() ?? 0),
                $channel,
            );
            $text .= ' '.$delivery['message'];
            if (null !== $delivery['webLink']) {
                $text .= ' '.$delivery['webLink'];
            }
            $metadata['calendar_delivery'] = [
                'ok' => $delivery['ok'],
                'channel' => $delivery['channel'],
                'connection' => $delivery['connection'],
                'created' => $delivery['created'],
                'skipped' => $delivery['skipped'],
            ];
            $context->streamChunk($delivery['message']);
        }

        return NodeResult::ok($text, [$file], $metadata);
    }

    /**
     * Merge a node's resolved `inputs` and `params` into a single field bag the
     * runner reads from. `params` wins on key collision; null-valued resolved
     * inputs (unresolved references) are ignored so they never mask a real param.
     *
     * @return array<string, mixed>
     */
    private function effectiveParams(TaskNode $node, NodeContext $context): array
    {
        $merged = [];
        foreach ($context->resolveInputs($node) as $key => $value) {
            if (null !== $value) {
                $merged[$key] = $value;
            }
        }
        foreach ($node->params as $key => $value) {
            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveEnd(array $params, \DateTimeImmutable $start, \DateTimeZone $tz): \DateTimeImmutable
    {
        $endStr = is_string($params['end'] ?? null) ? trim($params['end']) : '';
        if ('' !== $endStr) {
            try {
                $end = new \DateTimeImmutable($endStr, $tz);
                if ($end > $start) {
                    return $end;
                }
            } catch (\Throwable) {
                // fall through to duration
            }
        }

        $minutes = 60;
        $rawMinutes = $params['duration_minutes'] ?? null;
        if (is_int($rawMinutes) || (is_string($rawMinutes) && ctype_digit($rawMinutes))) {
            $minutes = max(5, min(24 * 60, (int) $rawMinutes));
        }

        return $start->add(new \DateInterval('PT'.$minutes.'M'));
    }

    /**
     * @return list<string>
     */
    private function normalizeAttendees(mixed $raw): array
    {
        $attendees = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_string($entry) && '' !== trim($entry)) {
                    $attendees[] = trim($entry);
                }
            }
        } elseif (is_string($raw) && '' !== trim($raw)) {
            foreach (explode(',', $raw) as $entry) {
                if ('' !== trim($entry)) {
                    $attendees[] = trim($entry);
                }
            }
        }

        return $attendees;
    }
}
