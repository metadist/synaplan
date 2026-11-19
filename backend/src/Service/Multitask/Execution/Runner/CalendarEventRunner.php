<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Repository\UserRepository;
use App\Service\Calendar\CalendarEntry;
use App\Service\Calendar\CalendarEventService;
use App\Service\Calendar\CalendarEventSpeech;
use App\Service\Calendar\CalendarTurnEvidence;
use App\Service\Calendar\EventTimezone;
use App\Service\Destination\RequestedCalendarDelivery;
use App\Service\File\FileStorageService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\StepApprovalGate;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\Prompt\UserUtteranceLanguage;
use App\Service\Tool\Source\SkillToolSource;
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
 *   - timezone         IANA tz, e.g. "Europe/Berlin" (optional; see precedence below)
 *   - location         string (optional)
 *   - description      string (optional)
 *   - attendees        list<string>|string (optional; emails become ATTENDEEs)
 *   - organizer_email  string (optional)
 *   - channel          calendar channel name from [CHANNELLIST] (optional;
 *                      e.g. "outlook", "calendar" — delivers the event into
 *                      that connected calendar)
 *
 * Clock time: a start the plan carries is used only when the person named
 * it, in the latest message or as that very hour and minute earlier in the
 * conversation ({@see CalendarTurnEvidence}). The planner's favourite 09:00
 * is not a substitute.
 *
 * Entries from what was read: without such a start the runner takes its
 * entries from the steps it depends on, else from the files of the turn
 * ({@see CalendarEntrySource}) — "put the three flights of this booking
 * into my calendar" names no hour, the picture does. Every entry found is
 * written in one step and into one .ics; an entry counts only when the read
 * text names its time. With nothing to read the runner asks for the time.
 *
 * Timezone: a wall-clock time is meaningless without its zone. The
 * precedence (a zone the person named, the stored profile zone, a planner
 * zone that is not UTC) is {@see EventTimezone}. When no zone can be trusted
 * the event is NOT written: the runner asks for the timezone instead of
 * stamping silent server UTC (#2010).
 */
final readonly class CalendarEventRunner implements TaskRunner
{
    public function __construct(
        private CalendarEventService $calendarService,
        private FileStorageService $fileStorage,
        private RequestedCalendarDelivery $calendarDelivery,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
        private ?StepApprovalGate $approvalGate = null,
        private ?CalendarEntrySource $entrySource = null,
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
            new SkillDescriptor(
                Capability::CalendarEvent,
                'Create a calendar meeting/invite as a downloadable .ics file. params: title, start (ISO-8601 local datetime, e.g. "2026-06-09T15:00:00"), end (ISO-8601) or duration_minutes, timezone (IANA, e.g. "Europe/Berlin"), location, description, attendees (list of names/emails). Resolve a clock time the user named against the current time context. If they named no hour, do not invent 09:00 — the runner asks in their language. When the dates and times are in an attached file (a booking picture, an invitation), do NOT ask and do NOT write a start: let ONE calendar_event node depend on the file_analysis step that reads it and set inputs.source to "$nX.text" — the runner takes every entry from that text, however many there are. When they ask to put the event INTO a connected calendar and the Connected channels list has a calendar channel, also set params.channel to that channel name (e.g. "outlook") — never invent one. compose_reply inputs.text must be "$nX.text" so the confirmation stays in their language.',
                planAdjuster: static function (array $payload, string $messageText, ?int $userId, ?array $allowed, string $topic, ?Message $message = null, array $thread = []): array {
                    unset($userId, $allowed, $topic);

                    return CalendarEventSpeech::adjustPlan(
                        $payload,
                        $messageText,
                        null !== $message ? CalendarTurnEvidence::ofTurn($message, $thread) : null,
                    );
                },
            ),
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

        $messageText = (string) $context->message->getText();
        $language = $this->utteranceLanguage($context, $messageText);
        $ownerId = (int) ($context->userId ?? $context->message->getUserId());

        // A resumed step writes the entries its owner approved, not a second
        // reading. Every approval states its entries (none for an event the
        // plan named), so what is found here is what the owner was shown.
        $approved = $context->isApproved($node->id) ? $this->approvedEntries($params) : [];
        if ([] !== $approved) {
            return $this->writeApprovedEntries($node, $context, $approved, $params, $language, $ownerId);
        }

        $startStr = is_string($params['start'] ?? null) ? trim($params['start']) : '';
        $evidence = CalendarTurnEvidence::ofTurn($context->message, $context->thread);
        if ('' === $startStr || !$evidence->backsStart($startStr)) {
            // The hour is not the person's. Take the entries from what was read.
            $readText = $this->entrySource?->readText($node, $context, $evidence) ?? '';
            if ('' !== $readText) {
                return $this->enterWhatWasRead($node, $context, $params, $readText, $title, $language, $ownerId);
            }
        }

        $ask = $this->askInsteadOfWriting($evidence, $startStr, $messageText, $language, $title, $params, $ownerId);
        if (null !== $ask) {
            return $ask;
        }

        $resolved = $this->resolveTimezone($params, $messageText, $ownerId)
            ?? throw new \RuntimeException('calendar event: timezone unresolved');
        [$tz, $tzName, $stripExplicitOffset] = $resolved;

        try {
            $start = EventTimezone::parse($startStr, $tz, $stripExplicitOffset);
        } catch (\Throwable $e) {
            return NodeResult::failed('calendar event: invalid start time: '.$e->getMessage());
        }

        return $this->writeEvents($node, $context, [[
            'title' => $title,
            'start' => $start,
            'end' => $this->resolveEnd($params, $start, $tz, $stripExplicitOffset),
            'location' => is_string($params['location'] ?? null) ? $params['location'] : null,
            'description' => is_string($params['description'] ?? null) ? $params['description'] : null,
            'attendees' => $this->normalizeAttendees($params['attendees'] ?? null),
            'organizer_email' => is_string($params['organizer_email'] ?? null) ? $params['organizer_email'] : null,
        ]], $tzName, $language, $ownerId, $params, false);
    }

    /**
     * The plan names no hour the person gave, and something was read: write
     * every entry that text holds. One step, one file, one confirmation.
     *
     * @param array<string, mixed> $params
     */
    private function enterWhatWasRead(
        TaskNode $node,
        NodeContext $context,
        array $params,
        string $readText,
        string $title,
        string $language,
        int $ownerId,
    ): NodeResult {
        $source = $this->entrySource;
        [$channel] = $this->destination($params, $context, $ownerId);
        if (null === $source || $source->alreadyEntered($context, $readText, $channel)) {
            return self::alreadyEntered();
        }

        $resolved = $this->resolveTimezone($params, (string) $context->message->getText(), $ownerId);
        if (null === $resolved) {
            return NodeResult::ok(CalendarEventSpeech::unknownTimezone($language, $title), [], [
                'calendar_event' => ['title' => $title, 'created' => false, 'reason' => 'unknown_timezone'],
            ]);
        }
        [$tz, $tzName] = $resolved;

        $extraction = $source->read($context, $readText, $tz, $language);
        if ($extraction instanceof NodeResult) {
            return $extraction;
        }

        $metadata = $extraction->usage + [CalendarEntrySource::META_READ => CalendarEntrySource::key($readText, $channel)];
        $events = $this->eventsFromEntries($extraction->entries, $params, $tz);
        if ([] === $events) {
            return NodeResult::ok(CalendarEventSpeech::nothingToEnter($language), [], $metadata + [
                'calendar_event' => ['created' => false, 'reason' => 'no_entries_found'],
            ]);
        }

        return $this->writeEvents($node, $context, $events, $tzName, $language, $ownerId, $params, true, $metadata);
    }

    /**
     * The entries an approval was given for. They carry what was read and
     * where it goes, like the step that asked, so a second step with the same
     * text and the same calendar still enters nothing. Should such a step
     * have finished already, this one has nothing left to write.
     *
     * @param non-empty-list<array{title: string, start: \DateTimeImmutable, end: \DateTimeImmutable, location: ?string, description: ?string, attendees: list<string>, organizer_email: ?string}> $approved
     * @param array<string, mixed>                                                                                                                                                                 $params
     */
    private function writeApprovedEntries(
        TaskNode $node,
        NodeContext $context,
        array $approved,
        array $params,
        string $language,
        int $ownerId,
    ): NodeResult {
        $metadata = [];
        $readText = $this->entrySource?->readText($node, $context, CalendarTurnEvidence::ofTurn($context->message, $context->thread)) ?? '';
        if (null !== $this->entrySource && '' !== $readText) {
            [$channel] = $this->destination($params, $context, $ownerId);
            if ($this->entrySource->enteredByAFinishedStep($context, $readText, $channel)) {
                return self::alreadyEntered();
            }
            $metadata[CalendarEntrySource::META_READ] = CalendarEntrySource::key($readText, $channel);
        }

        $zone = is_string($params['timezone'] ?? null) ? $params['timezone'] : $approved[0]['start']->getTimezone()->getName();

        return $this->writeEvents($node, $context, $approved, $zone, $language, $ownerId, $params, true, $metadata);
    }

    private static function alreadyEntered(): NodeResult
    {
        return NodeResult::ok(null, [], ['calendar_event' => ['created' => false, 'reason' => 'already_entered']]);
    }

    /**
     * The calendar channel this step delivers to and whether the person
     * asked for a calendar: the channel the plan names, else the person's
     * default calendar when they asked for one. Empty: the file alone.
     *
     * @param array<string, mixed> $params
     *
     * @return array{0: string, 1: bool}
     */
    private function destination(array $params, NodeContext $context, int $ownerId): array
    {
        $channel = is_string($params['channel'] ?? null) ? trim($params['channel']) : '';
        $userAsked = $this->calendarDelivery->userAskedToPutInCalendar((string) $context->message->getText());
        if ('' === $channel && $userAsked) {
            $channel = $this->calendarDelivery->defaultCalendarChannel($ownerId) ?? '';
        }

        return [$channel, $userAsked];
    }

    /**
     * The times of an entry are the ones on the page. They are placed in the
     * person's zone, whatever offset the model wrote next to them.
     *
     * @param list<CalendarEntry>  $entries
     * @param array<string, mixed> $params
     *
     * @return list<array{title: string, start: \DateTimeImmutable, end: \DateTimeImmutable, location: ?string, description: ?string, attendees: list<string>, organizer_email: ?string}>
     */
    private function eventsFromEntries(array $entries, array $params, \DateTimeZone $tz): array
    {
        $events = [];
        foreach ($entries as $entry) {
            try {
                $start = EventTimezone::parse($entry->start, $tz, true);
            } catch (\Throwable) {
                continue;
            }
            $events[] = [
                'title' => $entry->title,
                'start' => $start,
                'end' => $this->resolveEnd(['end' => $entry->end, 'duration_minutes' => $params['duration_minutes'] ?? null], $start, $tz, true),
                'location' => $entry->location,
                'description' => $entry->description,
                'attendees' => [],
                'organizer_email' => null,
            ];
        }

        return $events;
    }

    /**
     * The entries an approval was given for, as the resumed step receives
     * them back in its params.
     *
     * @param array<string, mixed> $params
     *
     * @return list<array{title: string, start: \DateTimeImmutable, end: \DateTimeImmutable, location: ?string, description: ?string, attendees: list<string>, organizer_email: ?string}>
     */
    private function approvedEntries(array $params): array
    {
        $rows = $params['entries'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['start'] ?? null) || !is_string($row['end'] ?? null)) {
                continue;
            }
            try {
                $start = new \DateTimeImmutable($row['start']);
                $end = new \DateTimeImmutable($row['end']);
            } catch (\Throwable) {
                continue;
            }
            $events[] = [
                'title' => is_string($row['title'] ?? null) && '' !== trim($row['title']) ? trim($row['title']) : 'Meeting',
                'start' => $start,
                'end' => $end > $start ? $end : $start->add(new \DateInterval('PT1H')),
                'location' => is_string($row['location'] ?? null) ? $row['location'] : null,
                'description' => is_string($row['description'] ?? null) ? $row['description'] : null,
                'attendees' => [],
                'organizer_email' => null,
            ];
        }

        return $events;
    }

    /**
     * Approval, the .ics, the connected calendar and the confirmation, for
     * one event or several.
     *
     * @param non-empty-list<array{title: string, start: \DateTimeImmutable, end: \DateTimeImmutable, location: ?string, description: ?string, attendees: list<string>, organizer_email: ?string}> $events
     * @param array<string, mixed>                                                                                                                                                                 $params
     * @param bool                                                                                                                                                                                 $read     the entries were read, not planned: the approval names each of them
     * @param array<string, mixed>                                                                                                                                                                 $metadata carried onto the result (the usage of the reading call)
     */
    private function writeEvents(
        TaskNode $node,
        NodeContext $context,
        array $events,
        string $tzName,
        string $language,
        int $ownerId,
        array $params,
        bool $read,
        array $metadata = [],
    ): NodeResult {
        [$channel, $userAsked] = $this->destination($params, $context, $ownerId);

        $gated = $this->approvalGate?->consult(
            $context,
            $node,
            SkillToolSource::nameFor(Capability::CalendarEvent),
            $this->approvalArguments($events, $tzName, $channel, $read),
        );
        if (null !== $gated) {
            return $this->rememberingWhatWasRead($gated, $metadata);
        }

        $first = $events[0];
        if (1 === count($events)) {
            $ics = $this->calendarService->buildIcs(
                title: $first['title'],
                start: $first['start'],
                end: $first['end'],
                description: $first['description'],
                location: $first['location'],
                attendees: $first['attendees'],
                organizerEmail: $first['organizer_email'],
                timezoneLabel: $tzName,
            );
            $filename = 'meeting_'.$first['start']->format('Ymd_His').'.ics';
        } else {
            $ics = $this->calendarService->buildIcsForEntries($events, $tzName);
            $filename = sprintf('calendar_%s_%d_entries.ics', $first['start']->format('Ymd'), count($events));
        }

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

        return $this->confirmStoredEvents(
            $context,
            $file,
            $stored['path'],
            $filename,
            $events,
            $tzName,
            $language,
            $ownerId,
            $channel,
            $userAsked,
            $metadata,
        );
    }

    /**
     * A step that waits for its approval has read its text already. It says
     * so, or a second calendar step of the same run would read that text
     * again and ask for the same entries a second time.
     *
     * @param array<string, mixed> $metadata
     */
    private function rememberingWhatWasRead(NodeResult $gated, array $metadata): NodeResult
    {
        $read = $metadata[CalendarEntrySource::META_READ] ?? null;
        if (!$gated->isWaitingApproval() || !is_string($read)) {
            return $gated;
        }

        return new NodeResult(
            $gated->status,
            $gated->text,
            $gated->files,
            $gated->metadata + [CalendarEntrySource::META_READ => $read],
            $gated->error,
        );
    }

    /**
     * What an approval is asked for. A planned event keeps its fields at the
     * top level. Entries that were read are listed, with one line that names
     * them: the approval preview shows scalar values only.
     *
     * `entries` is always stated, empty for a planned event. The approved
     * arguments replace the step's own, so a list the plan itself carried
     * cannot be written on the strength of an approval that showed one event.
     *
     * @param non-empty-list<array{title: string, start: \DateTimeImmutable, end: \DateTimeImmutable, location: ?string, description: ?string, attendees: list<string>, organizer_email: ?string}> $events
     *
     * @return array<string, mixed>
     */
    private function approvalArguments(array $events, string $tzName, string $channel, bool $read): array
    {
        if (!$read) {
            $event = $events[0];

            return [
                'title' => $event['title'],
                'start' => $event['start']->format(\DateTimeInterface::ATOM),
                'end' => $event['end']->format(\DateTimeInterface::ATOM),
                'timezone' => $tzName,
                'channel' => $channel,
                'location' => $event['location'],
                'description' => $event['description'],
                'attendees' => $event['attendees'],
                'organizer_email' => $event['organizer_email'],
                'entries' => [],
            ];
        }

        return [
            'summary' => count($events).' × '.implode('; ', array_map(
                static fn (array $event): string => $event['start']->format('Y-m-d H:i').' '.$event['title'],
                $events,
            )),
            'timezone' => $tzName,
            'channel' => $channel,
            'entries' => array_map(static fn (array $event): array => [
                'title' => $event['title'],
                'start' => $event['start']->format(\DateTimeInterface::ATOM),
                'end' => $event['end']->format(\DateTimeInterface::ATOM),
                'location' => $event['location'],
                'description' => $event['description'],
            ], $events),
        ];
    }

    private function utteranceLanguage(NodeContext $context, string $messageText): string
    {
        $classified = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        return UserUtteranceLanguage::detect($messageText, $classified);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function askInsteadOfWriting(
        CalendarTurnEvidence $evidence,
        string $startStr,
        string $messageText,
        string $language,
        string $title,
        array $params,
        int $ownerId,
    ): ?NodeResult {
        if (!$evidence->backsStart($startStr)) {
            return NodeResult::ok(CalendarEventSpeech::askForTime($language), [], [
                'calendar_event' => [
                    'title' => $title,
                    'created' => false,
                    'reason' => 'missing_clock_time',
                ],
            ]);
        }

        if ('' === $startStr) {
            return NodeResult::failed('calendar event: missing start time');
        }

        if (null !== $this->resolveTimezone($params, $messageText, $ownerId)) {
            return null;
        }

        return NodeResult::ok(CalendarEventSpeech::unknownTimezone($language, $title), [], [
            'calendar_event' => [
                'title' => $title,
                'created' => false,
                'reason' => 'unknown_timezone',
            ],
        ]);
    }

    /**
     * @param array<string, mixed>                                                                                                                                                                 $file
     * @param non-empty-list<array{title: string, start: \DateTimeImmutable, end: \DateTimeImmutable, location: ?string, description: ?string, attendees: list<string>, organizer_email: ?string}> $events
     * @param array<string, mixed>                                                                                                                                                                 $metadata
     */
    private function confirmStoredEvents(
        NodeContext $context,
        array $file,
        string $storedPath,
        string $filename,
        array $events,
        string $tzName,
        string $language,
        int $ownerId,
        string $channel,
        bool $userAsked,
        array $metadata = [],
    ): NodeResult {
        $webLink = null;
        $deliveryMeta = null;
        $deliveryNote = '';

        if ('' !== $channel) {
            $delivery = $this->calendarDelivery->send(
                $ownerId,
                rtrim($this->uploadDir, '/').'/'.ltrim($storedPath, '/'),
                $filename,
                (int) ($context->message->getId() ?? 0),
                $channel,
            );
            $webLink = $delivery['ok'] ? $delivery['webLink'] : null;
            $deliveryMeta = [
                'ok' => $delivery['ok'],
                'channel' => $delivery['channel'],
                'connection' => $delivery['connection'],
                'created' => $delivery['created'],
                'skipped' => $delivery['skipped'],
            ];
            if (!$delivery['ok']) {
                $deliveryNote = ' '.$delivery['message'];
            }
        } elseif ($userAsked) {
            $deliveryMeta = [
                'ok' => false,
                'channel' => null,
                'connection' => null,
                'created' => 0,
                'skipped' => 0,
            ];
            $deliveryNote = ' '.CalendarEventSpeech::notDelivered($language, count($events) > 1);
        }

        $deliveredChannel = (true === ($deliveryMeta['ok'] ?? false)) ? $channel : '';
        $first = $events[0];
        if (1 === count($events)) {
            $durationMinutes = (int) round(($first['end']->getTimestamp() - $first['start']->getTimestamp()) / 60);
            $text = CalendarEventSpeech::confirmed(
                $language,
                $first['start'],
                max(1, $durationMinutes),
                $deliveredChannel,
                $webLink,
                $first['title'],
            ).$deliveryNote;
        } else {
            // One link cannot stand for several events; the list names each.
            $text = CalendarEventSpeech::confirmedSeveral($language, $events, $deliveredChannel);
            $text .= '' !== $deliveryNote ? "\n\n".ltrim($deliveryNote) : '';
        }

        $metadata['media_type'] = 'document';
        $metadata['calendar_event'] = [
            'title' => $first['title'],
            'start' => $first['start']->format(\DateTimeInterface::ATOM),
            'end' => $first['end']->format(\DateTimeInterface::ATOM),
            'timezone' => $tzName,
        ];
        if (count($events) > 1) {
            $metadata['calendar_events'] = array_map(static fn (array $event): array => [
                'title' => $event['title'],
                'start' => $event['start']->format(\DateTimeInterface::ATOM),
                'end' => $event['end']->format(\DateTimeInterface::ATOM),
            ], $events);
        }
        if (null !== $deliveryMeta) {
            $metadata['calendar_delivery'] = $deliveryMeta;
        }

        return NodeResult::ok($text, [$file], $metadata);
    }

    /**
     * The zone of this event's wall-clock times ({@see EventTimezone}), or
     * null when none can be trusted and the event must not be written.
     *
     * @param array<string, mixed> $params
     *
     * @return array{0: \DateTimeZone, 1: string, 2: bool}|null
     */
    private function resolveTimezone(array $params, string $messageText, int $ownerId): ?array
    {
        return EventTimezone::resolve(
            is_string($params['timezone'] ?? null) ? $params['timezone'] : '',
            $messageText,
            $this->profileTimezone($ownerId),
        );
    }

    private function profileTimezone(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $user = $this->userRepository->find($userId);
        } catch (\Throwable) {
            return null;
        }
        if (null === $user) {
            return null;
        }

        return EventTimezone::valid((string) ($user->getUserDetails()['timezone'] ?? ''));
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
    private function resolveEnd(array $params, \DateTimeImmutable $start, \DateTimeZone $tz, bool $stripExplicitOffset): \DateTimeImmutable
    {
        $endStr = is_string($params['end'] ?? null) ? trim($params['end']) : '';
        if ('' !== $endStr) {
            try {
                $end = EventTimezone::parse($endStr, $tz, $stripExplicitOffset);
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
