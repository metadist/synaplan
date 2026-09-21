<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Repository\UserRepository;
use App\Service\Calendar\CalendarEventService;
use App\Service\Destination\RequestedCalendarDelivery;
use App\Service\File\FileStorageService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\StepApprovalGate;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
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
 * Timezone precedence (a wall-clock time is meaningless without its zone):
 *   1. A zone the user named outright in THIS message (IANA identifier or
 *      bare UTC/GMT token) — wins for this event, even over a stored profile
 *      zone and whatever the planner emitted.
 *   2. A planner zone corroborated by the user's own words (e.g. the city it
 *      is named after, "10 New York").
 *   3. The stored profile timezone — authoritative, beats any planner guess.
 *   4. The planner timezone, when valid and not UTC (country inference).
 *   5. Otherwise the event is NOT written: the runner asks for the timezone
 *      instead of stamping silent server UTC (#2010).
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

    /**
     * Asked instead of writing the event when no timezone can be trusted.
     * Returned as a successful text-only result (not failed()): reply nodes
     * only see `.text`, so a failure here would hide the recovery behind a
     * generic fallback and the user would never learn what to provide.
     */
    private const UNKNOWN_TIMEZONE_TEXT = [
        'en' => 'I did not create "%s": your timezone is unknown, so I cannot place the requested time. Set your timezone in your profile, or name one in your message (e.g. "tomorrow at 10, Europe/Berlin").',
        'de' => 'Ich habe "%s" nicht erstellt: Deine Zeitzone ist unbekannt, daher kann ich die gewünschte Zeit nicht einordnen. Stelle deine Zeitzone im Profil ein oder nenne eine in deiner Nachricht (z. B. "morgen um 10, Europe/Berlin").',
        'es' => 'No he creado "%s": tu zona horaria es desconocida, así que no puedo situar la hora solicitada. Configura tu zona horaria en tu perfil o indícala en tu mensaje (p. ej., "mañana a las 10, Europe/Berlin").',
        'fr' => 'Je n\'ai pas créé "%s" : votre fuseau horaire est inconnu, je ne peux donc pas situer l\'heure demandée. Définissez votre fuseau horaire dans votre profil ou indiquez-en un dans votre message (par ex. "demain à 10h, Europe/Berlin").',
        'tr' => '"%s" oluşturulmadı: saat diliminiz bilinmiyor, bu yüzden istenen saati yerleştiremiyorum. Saat diliminizi profilinizde ayarlayın ya da mesajınızda belirtin (örn. "yarın 10\'da, Europe/Berlin").',
    ];

    public function __construct(
        private CalendarEventService $calendarService,
        private FileStorageService $fileStorage,
        private RequestedCalendarDelivery $calendarDelivery,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
        private ?StepApprovalGate $approvalGate = null,
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

        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        $ownerId = (int) ($context->userId ?? $context->message->getUserId());

        $startStr = is_string($params['start'] ?? null) ? trim($params['start']) : '';
        if ('' === $startStr) {
            return NodeResult::failed('calendar event: missing start time');
        }

        $resolved = $this->resolveTimezone($params, (string) $context->message->getText(), $ownerId);
        if (null === $resolved) {
            $template = self::UNKNOWN_TIMEZONE_TEXT[$language] ?? self::UNKNOWN_TIMEZONE_TEXT['en'];

            return NodeResult::ok(sprintf($template, $title), [], [
                'calendar_event' => [
                    'title' => $title,
                    'created' => false,
                    'reason' => 'unknown_timezone',
                ],
            ]);
        }
        [$tz, $tzName, $stripExplicitOffset] = $resolved;

        try {
            $start = $this->parseInZone($startStr, $tz, $stripExplicitOffset);
        } catch (\Throwable $e) {
            return NodeResult::failed('calendar event: invalid start time: '.$e->getMessage());
        }

        $end = $this->resolveEnd($params, $start, $tz, $stripExplicitOffset);

        $channel = is_string($params['channel'] ?? null) ? trim($params['channel']) : '';
        $userAsked = $this->calendarDelivery->userAskedToPutInCalendar((string) $context->message->getText());
        if ('' === $channel && $userAsked) {
            $channel = $this->calendarDelivery->defaultCalendarChannel($ownerId) ?? '';
        }

        $gated = $this->approvalGate?->consult($context, $node, SkillToolSource::nameFor(Capability::CalendarEvent), [
            'title' => $title,
            'start' => $start->format(\DateTimeInterface::ATOM),
            'end' => $end->format(\DateTimeInterface::ATOM),
            'timezone' => $tzName,
            'channel' => $channel,
            'location' => is_string($params['location'] ?? null) ? $params['location'] : null,
            'description' => is_string($params['description'] ?? null) ? $params['description'] : null,
            'attendees' => $this->normalizeAttendees($params['attendees'] ?? null),
            'organizer_email' => is_string($params['organizer_email'] ?? null) ? $params['organizer_email'] : null,
        ]);
        if (null !== $gated) {
            return $gated;
        }

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

        if ('' !== $channel) {
            $delivery = $this->calendarDelivery->send(
                $ownerId,
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
        } elseif ($userAsked) {
            $note = 'The event was not added to a calendar — the .ics is attached. Connect a calendar under Settings → Connections.';
            $text .= ' '.$note;
            $metadata['calendar_delivery'] = [
                'ok' => false,
                'channel' => null,
                'connection' => null,
                'created' => 0,
                'skipped' => 0,
            ];
            $context->streamChunk($note);
        }

        return NodeResult::ok($text, [$file], $metadata);
    }

    /**
     * Resolve which zone a wall-clock event time belongs to.
     *
     * Returns the zone, its name, and whether an explicit offset in start/end
     * must be reinterpreted as wall-clock in that zone — or null when no zone
     * can be trusted and the event must not be written.
     *
     * @param array<string, mixed> $params
     *
     * @return array{0: \DateTimeZone, 1: string, 2: bool}|null
     */
    private function resolveTimezone(array $params, string $messageText, int $ownerId): ?array
    {
        $plannerTz = $this->validZone(is_string($params['timezone'] ?? null) ? trim($params['timezone']) : '');
        $profileTz = $this->profileTimezone($ownerId);

        // 1. A zone the user named outright (IANA identifier or bare UTC/GMT
        // token) wins even when the planner emitted something else.
        $namedTz = $this->extractUserNamedZone($messageText);
        if (null !== $namedTz && $namedTz !== $profileTz) {
            return [new \DateTimeZone($namedTz), $namedTz, false];
        }
        // 2. A planner zone corroborated by the user's own words (e.g. the
        // city it is named after) wins for this event.
        if (null !== $plannerTz && $plannerTz !== $profileTz && $this->isUserNamedZone($plannerTz, $messageText)) {
            return [new \DateTimeZone($plannerTz), $plannerTz, false];
        }
        // 3. The stored profile zone is authoritative.
        if (null !== $profileTz) {
            return [new \DateTimeZone($profileTz), $profileTz, true];
        }
        // 4. A planner zone that is not a silent UTC default (country inference).
        if (null !== $plannerTz && 'UTC' !== strtoupper($plannerTz)) {
            return [new \DateTimeZone($plannerTz), $plannerTz, false];
        }

        // 5. Server time only — never stamp it onto a wall-clock request.
        return null;
    }

    private function validZone(string $name): ?string
    {
        if ('' === $name) {
            return null;
        }
        try {
            new \DateTimeZone($name);
        } catch (\Throwable) {
            return null;
        }

        return $this->canonicalZoneName($name) ?? $name;
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

        return $this->validZone(trim((string) ($user->getUserDetails()['timezone'] ?? '')));
    }

    /**
     * A zone the user named outright in their own message: a bare UTC/GMT
     * token or an IANA identifier. Unlike isUserNamedZone() this does not
     * depend on the planner having emitted the same zone first, so an
     * explicit "10:00 UTC" wins even when the planner guessed otherwise.
     */
    private function extractUserNamedZone(string $messageText): ?string
    {
        if ('' === trim($messageText)) {
            return null;
        }
        if (1 === preg_match('/\b(UTC|GMT)\b(?![+-]\d)/i', $messageText)) {
            return 'UTC';
        }
        if (1 !== preg_match_all('#\b([A-Za-z][A-Za-z0-9_.+\-]*(?:/[A-Za-z0-9_.+\-]+)+)\b#', $messageText, $matches)) {
            return null;
        }
        foreach ($matches[1] as $candidate) {
            // Canonical lookup doubles as validation: lookalikes ("and/or",
            // "Q3/2026") are not zones, and any casing ("europe/berlin")
            // resolves to the spelled name ("Europe/Berlin").
            $canonical = $this->canonicalZoneName($candidate);
            if (null !== $canonical) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Canonical IANA spelling for a zone name in any casing, or null when it
     * names no zone at all. Cached per request; the list is ~400 entries.
     */
    private function canonicalZoneName(string $candidate): ?string
    {
        static $byLower = null;
        if (null === $byLower) {
            $byLower = [];
            foreach (\DateTimeZone::listIdentifiers() as $id) {
                $byLower[strtolower($id)] = $id;
            }
        }

        return $byLower[strtolower($candidate)] ?? null;
    }

    /**
     * Did the user name this planner zone in their own message (as opposed to
     * the planner inferring or defaulting it)? Matches the IANA name itself,
     * a bare UTC/GMT token for UTC, or the city the zone is named after.
     */
    private function isUserNamedZone(string $tzName, string $messageText): bool
    {
        if ('' === trim($messageText)) {
            return false;
        }
        if (str_contains(mb_strtolower($messageText), mb_strtolower($tzName))) {
            return true;
        }
        if ('UTC' === strtoupper($tzName)) {
            // Bare token only, word-boundary guarded: "production" must not
            // corroborate UTC, and "UTC+2" names an offset, not UTC itself.
            return 1 === preg_match('/\b(UTC|GMT)\b(?![+-]\d)/i', $messageText);
        }

        $slash = strrpos($tzName, '/');
        $city = str_replace('_', ' ', false === $slash ? $tzName : substr($tzName, $slash + 1));

        return '' !== $city && 1 === preg_match('/\b'.preg_quote($city, '/').'\b/iu', $messageText);
    }

    private function parseInZone(string $value, \DateTimeZone $zone, bool $stripExplicitOffset): \DateTimeImmutable
    {
        $value = trim($value);
        if ($stripExplicitOffset) {
            // new \DateTimeImmutable($str, $tz) ignores $tz when $str already
            // carries Z or an offset — a planner "…T10:00:00Z" must not
            // override the stored profile zone, so the wall clock is
            // reinterpreted in it.
            $value = trim((string) preg_replace('/(?:Z|[+-]\d{2}:?\d{2})\s*$/i', '', $value));
        }
        if ('' === $value) {
            throw new \InvalidArgumentException('empty datetime');
        }

        return new \DateTimeImmutable($value, $zone);
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
                $end = $this->parseInZone($endStr, $tz, $stripExplicitOffset);
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
