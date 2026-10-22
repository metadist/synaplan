<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * One meeting-notes session per room:
 *   starting → running → stopping → saving → saved | nothing_to_save | failed
 * Every path ends in a final state: the watchdog (run on every read) finishes
 * sessions whose transcriber never came or whose bridge went away.
 */
final readonly class SessionService
{
    public const ACTIVE = ['starting', 'running', 'stopping'];
    public const FINAL = ['saved', 'nothing_to_save', 'failed'];

    /** After Stop, wait this long for the transcriber's last windows. */
    private const STOP_GRACE_SECONDS = 60;
    /** Bridge must connect within this time after Start. */
    private const CONNECT_GRACE_SECONDS = 45;
    /** How often a read re-checks Jitsi for a running session. */
    private const JITSI_CHECK_SECONDS = 20;
    private const MAX_WINDOW_BYTES = 2 * 1024 * 1024;
    private const MAX_WINDOW_MS = 30_000;
    private const CUTS = ['pause', 'soft', 'hard', 'stop'];
    /** Windows the transcriber may have in flight per session. */
    private const MAX_CONCURRENCY = 2;
    /** A finished session stays visible to its starter in the room this long. */
    private const RECENT_SECONDS = 300;

    public function __construct(
        private SessionStore $store,
        private ProsodyClient $prosody,
        private WhisperClient $whisper,
        private TranscriptRenderer $renderer,
        private TranscriptWriter $writer,
        private ParticipantResolver $participants,
        private UserRepository $users,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function start(User $user, string $room, string $language, ?string $folder): array
    {
        $this->assertReady();
        $room = $this->room($room);
        if (!in_array($language, $this->settings->languages(), true)) {
            throw new SessionException('language_not_offered', sprintf('Language "%s" is not offered here.', $language), 422);
        }

        $current = $this->forRoom($room);
        if (null !== $current && in_array($current['state'], self::ACTIVE, true)) {
            throw new SessionException('already_running', 'Meeting notes are already on in this meeting.', 409, ['session' => $current]);
        }

        $now = time();
        $session = [
            'ref' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'room' => $room,
            'starterId' => (int) $user->getId(),
            'starterName' => $user->getDisplayName(),
            'language' => $language,
            'folder' => $this->settings->sanitizeFolder($folder),
            'state' => 'starting',
            'startedAt' => $now,
            'checkedAt' => $now,
            'roster' => [],
            'attendees' => [],
            'files' => [],
        ];
        $this->store->save($session);
        $this->store->markRoom($room, $session['ref']);

        try {
            $answer = $this->prosody->start($room, $session['ref'], $language);
        } catch (SessionException $e) {
            $this->store->clearRoom($room, $session['ref']);

            return $this->fail($session, $e->errorCode);
        }

        $session['state'] = 'running';
        $session['meetingId'] = is_string($answer['meetingId'] ?? null) ? $answer['meetingId'] : null;
        $session = $this->absorb($session, $answer);
        $this->store->save($session);
        $this->logger->info('synascriber: notes started', ['ref' => $session['ref'], 'room' => $room, 'user' => $session['starterId']]);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $ref, User $user): array
    {
        $session = $this->load($ref);
        if (!$this->canStop($session, $user)) {
            throw new SessionException('not_allowed', 'Only the person who started the notes or an administrator can stop them here. A Jitsi moderator can stop them in Jitsi.', 403);
        }
        if (!in_array($session['state'], self::ACTIVE, true)) {
            return $session;
        }

        $session = $this->refresh($session);
        $session = $this->markStopping($session);
        try {
            $this->prosody->stop((string) $session['room'], $ref);
        } catch (SessionException $e) {
            $this->logger->warning('synascriber: stop not confirmed by Jitsi', ['ref' => $ref, 'error' => $e->errorCode]);
        }

        return $session;
    }

    /**
     * The room's current session after the watchdog ran, or null.
     *
     * @return array<string, mixed>|null
     */
    public function forRoom(string $room): ?array
    {
        $ref = $this->store->activeRefForRoom($this->room($room));
        if (null === $ref) {
            return null;
        }
        $session = $this->store->find($ref);

        return null === $session ? null : $this->tick($session);
    }

    /**
     * The transcriber binds a bridge connection, on connect and every 30 s.
     *
     * @return array<string, mixed>
     */
    public function bind(string $ref, string $meetingId): array
    {
        $session = $this->store->find($ref);
        if (null === $session) {
            throw new SessionException('unknown_session', 'These meeting notes do not exist.', 404);
        }
        if (!in_array($session['state'], ['starting', 'running', 'stopping'], true)) {
            throw new SessionException('not_active', 'These meeting notes are not active.', 409);
        }
        $known = (string) ($session['meetingId'] ?? '');
        if ('' !== $known && '' !== $meetingId && $known !== $meetingId) {
            throw new SessionException('wrong_meeting', 'These meeting notes belong to another meeting.', 409);
        }
        if ('' === $known && '' !== $meetingId) {
            $session['meetingId'] = $meetingId;
            $this->store->save($session);
        }

        $speakers = [];
        foreach (is_array($session['roster'] ?? null) ? $session['roster'] : [] as $endpoint => $name) {
            $speakers[(string) $endpoint] = ['name' => (string) $name];
        }

        return [
            'sessionId' => substr(hash('sha256', 'session:'.$ref), 0, 16),
            'state' => $session['state'],
            'language' => $session['language'],
            'profile' => 'balanced',
            'captions' => $this->settings->captionsOn(),
            'glossary' => [],
            'speakers' => $speakers,
            'excluded' => [],
            'maxConcurrency' => self::MAX_CONCURRENCY,
        ];
    }

    /**
     * One speech window of one speaker: recognised, stored once, answered.
     *
     * @param array{endpointId: string, seq: int, t0Ms: int, t1Ms: int, cut: string, audio: string} $window
     *
     * @return array<string, mixed>
     */
    public function window(string $ref, array $window): array
    {
        $session = $this->accepting($ref, ['running', 'stopping']);
        $this->assertWindow($window);
        if (!in_array($window['cut'], self::CUTS, true)) {
            throw new SessionException('invalid_window', 'Unknown window cut.', 422);
        }

        $segment = ['at' => $this->originMs($session) + $window['t0Ms'], 'speaker' => $window['endpointId'], 'seq' => $window['seq']];
        $answer = ['seq' => $window['seq'], 'language' => $session['language'], 'segmentSeq' => $window['seq']];
        $stored = $this->store->findSegment($ref, $segment);
        if (null !== $stored) {
            return $answer + ['text' => (string) ($stored['text'] ?? ''), 'dropped' => 'duplicate', 'speaker' => $this->speakerName($session, $window['endpointId'])];
        }

        $result = $this->whisper->recognize($window['audio'], (string) $session['language']);
        $session['lastAudioAt'] = time();
        if ('' !== $result['text']) {
            $this->store->addSegment($ref, $segment + ['text' => $result['text']]);
            $session['segmentCount'] = (int) ($session['segmentCount'] ?? 0) + 1;
            $session = $this->learnSpeaker($session, $window['endpointId']);
        }
        $this->store->save($session);

        return $answer + ['text' => $result['text'], 'dropped' => $result['dropped'], 'speaker' => $this->speakerName($session, $window['endpointId'])];
    }

    /**
     * Live caption of the window so far: recognised, never stored.
     *
     * @param array{endpointId: string, seq: int, t0Ms: int, t1Ms: int, audio: string} $window
     *
     * @return array<string, mixed>
     */
    public function preview(string $ref, array $window): array
    {
        $session = $this->accepting($ref, ['running']);
        if (!$this->settings->captionsOn()) {
            throw new SessionException('captions_off', 'Live captions are switched off.', 403);
        }
        $this->assertWindow($window);
        $result = $this->whisper->recognize($window['audio'], (string) $session['language']);

        return [
            'seq' => $window['seq'],
            'text' => $result['text'],
            'language' => $session['language'],
            'dropped' => $result['dropped'],
            'speechEndMs' => $result['speechEndMs'],
            'segmentEndsMs' => $result['segmentEndsMs'],
        ];
    }

    /**
     * Transcriber lifecycle: connected, speaker start/stop, gaps, the end.
     *
     * @param array<string, mixed> $event
     */
    public function event(string $ref, array $event): void
    {
        $session = $this->store->find($ref);
        if (null === $session) {
            throw new SessionException('unknown_session', 'These meeting notes do not exist.', 404);
        }
        $type = (string) ($event['type'] ?? '');
        if ('connected' === $type) {
            if (empty($session['connectedAt'])) {
                $session['connectedAt'] = time();
                $session['originMs'] = is_numeric($event['at'] ?? null) ? (int) $event['at'] : time() * 1000;
                $this->store->save($session);
            }

            return;
        }
        if ('stt-failure' === $type) {
            $session['gaps'] = (int) ($session['gaps'] ?? 0) + 1;
            $this->store->save($session);
            $this->logger->warning('synascriber: speech recognition gap', ['ref' => $ref, 'detail' => mb_substr((string) ($event['detail'] ?? ''), 0, 200)]);

            return;
        }
        if ('finished' === $type) {
            $this->finish($ref, (string) ($session['endReason'] ?? 'bridge_closed'));

            return;
        }
        if (!in_array($type, ['speaker-start', 'speaker-stop', 'session-end'], true)) {
            throw new SessionException('unknown_event', sprintf('Unknown transcriber event "%s".', mb_substr($type, 0, 40)), 422);
        }
    }

    /**
     * @param list<string> $states
     *
     * @return array<string, mixed>
     */
    private function accepting(string $ref, array $states): array
    {
        $session = $this->store->find($ref);
        if (null === $session) {
            throw new SessionException('unknown_session', 'These meeting notes do not exist.', 404);
        }
        if (!in_array($session['state'], $states, true)) {
            throw new SessionException('not_accepting', 'These meeting notes do not accept audio any more.', 409);
        }

        return $session;
    }

    /**
     * @param array{endpointId: string, seq: int, t0Ms: int, t1Ms: int, audio: string} $window
     */
    private function assertWindow(array $window): void
    {
        if ('' === $window['audio'] || strlen($window['audio']) > self::MAX_WINDOW_BYTES) {
            throw new SessionException('invalid_audio', 'The audio window is empty or larger than 2 MB.', 422);
        }
        if (1 !== preg_match('/^[A-Za-z0-9_-]{1,80}$/', $window['endpointId'])) {
            throw new SessionException('invalid_window', 'Unknown speaker.', 422);
        }
        if ($window['seq'] < 0 || $window['t0Ms'] < 0 || $window['t1Ms'] <= $window['t0Ms'] || $window['t1Ms'] - $window['t0Ms'] > self::MAX_WINDOW_MS) {
            throw new SessionException('invalid_window', 'The window times are not valid (at most 30 s).', 422);
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function originMs(array $session): int
    {
        if (isset($session['originMs']) && is_numeric($session['originMs'])) {
            return (int) $session['originMs'];
        }

        return (int) ($session['connectedAt'] ?? $session['startedAt']) * 1000;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function speakerName(array $session, string $endpoint): string
    {
        $roster = is_array($session['roster'] ?? null) ? $session['roster'] : [];

        return (string) ($roster[$endpoint] ?? '');
    }

    /**
     * Writes the transcript (once) and ends the session.
     *
     * @return array<string, mixed>
     */
    public function finish(string $ref, string $reason): array
    {
        $session = $this->load($ref);
        if (in_array($session['state'], self::FINAL, true) || 'saving' === $session['state']) {
            return $session;
        }
        if (empty($session['stoppedAt'])) {
            $session['stoppedAt'] = time();
        }
        $session['state'] = 'saving';
        $session['endReason'] = $reason;
        $this->store->save($session);

        $segments = $this->store->segments($ref);
        if ([] === $segments) {
            $session['state'] = 'nothing_to_save';

            return $this->close($session);
        }

        $session = $this->refresh($session);
        $zone = $this->settings->timezone();
        $filename = $this->renderer->filename($session, $zone);
        $markdown = $this->renderer->render($session, $segments, $zone);
        $files = is_array($session['files'] ?? null) ? $session['files'] : [];
        $failures = 0;

        foreach ($this->recipients($session) as $userId => $user) {
            if (isset($files[$userId])) {
                continue;
            }
            try {
                $files[$userId] = $this->writer->write($user, $ref, (string) $session['folder'], $filename, $markdown);
            } catch (SessionException $e) {
                ++$failures;
                $this->logger->error('synascriber: transcript not saved for one person', ['ref' => $ref, 'user' => $userId, 'error' => $e->getMessage()]);
            }
        }

        $session['files'] = $files;
        $session['fileName'] = $filename;
        $session['segmentCount'] = count($segments);
        $session['deliveryFailures'] = $failures;
        if (!isset($files[(int) $session['starterId']])) {
            return $this->fail($session, 'file_not_saved');
        }

        $session['state'] = 'saved';
        $session['fileId'] = $files[(int) $session['starterId']];
        $this->store->deleteSegments($ref);
        $this->logger->info('synascriber: transcript saved', ['ref' => $ref, 'recipients' => count($files), 'failed' => $failures, 'segments' => count($segments)]);

        return $this->close($session);
    }

    /**
     * The starter first, then every signed-in participant with a Synaplan
     * account (created on demand) unless the admin limited it to the starter.
     *
     * @param array<string, mixed> $session
     *
     * @return array<int, User>
     */
    private function recipients(array $session): array
    {
        $recipients = [];
        $starter = $this->users->find((int) $session['starterId']);
        if ($starter instanceof User) {
            $recipients[(int) $starter->getId()] = $starter;
        }
        if ($this->settings->shareWithParticipants()) {
            $attendees = is_array($session['attendees'] ?? null) ? $session['attendees'] : [];
            $recipients += $this->participants->accounts($attendees);
        }

        return $recipients;
    }

    /**
     * Public projection for the loader and the personal page.
     *
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    public function view(array $session, ?User $viewer): array
    {
        $viewerId = null === $viewer ? 0 : (int) $viewer->getId();
        $files = is_array($session['files'] ?? null) ? $session['files'] : [];
        $mine = $viewerId === (int) $session['starterId'];

        return [
            'id' => $session['ref'],
            'room' => $session['room'],
            'state' => $session['state'],
            'language' => $session['language'],
            'folder' => $session['folder'],
            'startedBy' => $session['starterName'] ?? '',
            'startedAt' => $this->iso($session['startedAt'] ?? null),
            'stoppedAt' => $this->iso($session['stoppedAt'] ?? null),
            'mine' => $mine,
            'received' => !$mine && isset($files[$viewerId]),
            'canStop' => null !== $viewer && $this->canStop($session, $viewer) && in_array($session['state'], self::ACTIVE, true),
            'fileId' => $files[$viewerId] ?? ($mine ? ($session['fileId'] ?? null) : null),
            'fileName' => $session['fileName'] ?? null,
            'recipients' => count($files),
            'notDelivered' => $mine ? (int) ($session['deliveryFailures'] ?? 0) : 0,
            'segments' => (int) ($session['segmentCount'] ?? 0),
            'error' => $session['error'] ?? null,
        ];
    }

    /**
     * The viewer's latest notes in this room (started or received) that ended
     * in the last few minutes, so the loader can say what happened after Stop.
     *
     * @return array<string, mixed>|null
     */
    public function recentForRoom(User $user, string $room): ?array
    {
        $room = $this->room($room);
        foreach ($this->store->forUser((int) $user->getId(), 10) as $session) {
            if ($session['room'] === $room && in_array($session['state'], self::FINAL, true)
                && time() - (int) ($session['finishedAt'] ?? 0) <= self::RECENT_SECONDS) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Notes the person started or received, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, int $limit = 50): array
    {
        return array_map(fn (array $s): array => $this->tick($s), $this->store->forUser((int) $user->getId(), $limit));
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function tick(array $session): array
    {
        $now = time();
        $state = $session['state'];

        if ('stopping' === $state && $now - (int) ($session['stoppedAt'] ?? $now) > self::STOP_GRACE_SECONDS) {
            return $this->finish((string) $session['ref'], 'stop_grace_elapsed');
        }
        if ('starting' === $state && $now - (int) $session['startedAt'] > self::CONNECT_GRACE_SECONDS) {
            return $this->fail($session, 'start_timeout');
        }
        if ('running' !== $state || $now - (int) ($session['checkedAt'] ?? 0) < self::JITSI_CHECK_SECONDS) {
            return $session;
        }

        $session['checkedAt'] = $now;
        if (empty($session['connectedAt']) && $now - (int) $session['startedAt'] > self::CONNECT_GRACE_SECONDS) {
            try {
                $this->prosody->stop((string) $session['room'], (string) $session['ref']);
            } catch (SessionException) {
            }

            return $this->fail($session, 'transcriber_not_connected');
        }

        try {
            $state = $this->prosody->state((string) $session['room']);
        } catch (SessionException) {
            $this->store->save($session);

            return $session;
        }
        $session = $this->absorb($session, $state);
        if (true !== ($state['transcribing'] ?? false) || ($state['ref'] ?? null) !== $session['ref']) {
            $session['endReason'] = true === ($state['exists'] ?? false) ? 'stopped_in_jitsi' : 'meeting_ended';

            return $this->markStopping($session);
        }
        $this->store->save($session);

        return $session;
    }

    /**
     * Merges the room's participants (names by endpoint) and attendees
     * (identities of everyone who was there while notes were on).
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $answer  a Prosody start or state answer
     *
     * @return array<string, mixed>
     */
    private function absorb(array $session, array $answer): array
    {
        $roster = is_array($session['roster'] ?? null) ? $session['roster'] : [];
        $attendees = is_array($session['attendees'] ?? null) ? $session['attendees'] : [];
        foreach (['participants', 'attendees'] as $list) {
            $roster = array_replace($roster, $this->roster($answer[$list] ?? []));
            $attendees = array_replace($attendees, $this->identities($answer[$list] ?? []));
        }
        $session['roster'] = $roster;
        $session['attendees'] = $attendees;

        return $session;
    }

    /**
     * Fresh participants from Jitsi, if the room still exists.
     *
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function refresh(array $session): array
    {
        try {
            return $this->absorb($session, $this->prosody->state((string) $session['room']));
        } catch (SessionException) {
            return $session;
        }
    }

    /**
     * @return array<string, array{name: string, email: string, sub: string}> keyed by subject
     */
    private function identities(mixed $participants): array
    {
        $identities = [];
        if (!is_array($participants)) {
            return $identities;
        }
        foreach ($participants as $participant) {
            if (!is_array($participant) || !is_string($participant['sub'] ?? null) || !is_string($participant['email'] ?? null)) {
                continue;
            }
            $identities[$participant['sub']] = [
                'name' => mb_substr(trim((string) ($participant['name'] ?? '')), 0, 80),
                'email' => mb_substr(trim($participant['email']), 0, 200),
                'sub' => mb_substr(trim($participant['sub']), 0, 80),
            ];
        }

        return $identities;
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function learnSpeaker(array $session, string $speaker): array
    {
        $endpoint = TranscriptRenderer::endpointOf($speaker);
        $roster = is_array($session['roster'] ?? null) ? $session['roster'] : [];
        if ('' !== trim((string) ($roster[$endpoint] ?? ''))) {
            return $session;
        }

        return $this->refresh($session);
    }

    /**
     * @return array<string, string> endpoint id => display name
     */
    private function roster(mixed $participants): array
    {
        $roster = [];
        if (!is_array($participants)) {
            return $roster;
        }
        foreach ($participants as $participant) {
            if (is_array($participant) && is_string($participant['id'] ?? null) && '' !== trim((string) ($participant['name'] ?? ''))) {
                $roster[$participant['id']] = mb_substr(trim((string) $participant['name']), 0, 80);
            }
        }

        return $roster;
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function markStopping(array $session): array
    {
        $session['state'] = 'stopping';
        $session['stoppedAt'] = $session['stoppedAt'] ?? time();
        $this->store->save($session);

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function fail(array $session, string $code): array
    {
        $session['state'] = 'failed';
        $session['error'] = $code;
        $this->logger->warning('synascriber: notes failed', ['ref' => $session['ref'], 'error' => $code]);

        return $this->close($session);
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function close(array $session): array
    {
        $session['finishedAt'] = time();
        $this->store->save($session);
        $this->store->clearRoom((string) $session['room'], (string) $session['ref']);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $ref): array
    {
        $session = $this->store->find($ref);
        if (null === $session) {
            throw new SessionException('not_found', 'These meeting notes do not exist.', 404);
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function canStop(array $session, User $user): bool
    {
        return (int) $user->getId() === (int) $session['starterId'] || $user->isAdmin();
    }

    private function assertReady(): void
    {
        if (!$this->settings->isEnabled()) {
            throw new SessionException('not_enabled', 'Meeting notes are turned off by the administrator.', 403);
        }
        if ('' === $this->settings->prosodyUrl() || '' === $this->settings->sttUrl() || $this->settings->ownerUserId() <= 0) {
            throw new SessionException('not_configured', 'Meeting notes are not fully set up yet. An administrator finishes the setup on the plugin page.', 503);
        }
    }

    private function room(string $room): string
    {
        $room = mb_strtolower(trim($room));
        if ('' === $room || mb_strlen($room) > 200 || preg_match('/[@\/\s]/u', $room)) {
            throw new SessionException('invalid_room', 'This is not a valid meeting name.', 422);
        }

        return $room;
    }

    private function iso(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) ? (new \DateTimeImmutable('@'.(int) $timestamp))->format(\DATE_ATOM) : null;
    }
}
