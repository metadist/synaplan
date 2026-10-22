<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Plugin\SynaScriber\Service\ParticipantResolver;
use Plugin\SynaScriber\Service\ProsodyClient;
use Plugin\SynaScriber\Service\SessionException;
use Plugin\SynaScriber\Service\SessionService;
use Plugin\SynaScriber\Service\SessionStore;
use Plugin\SynaScriber\Service\TranscriptRenderer;
use Plugin\SynaScriber\Service\TranscriptWriter;
use Plugin\SynaScriber\Service\WhisperClient;
use Psr\Log\NullLogger;

/**
 * The transcriber's session API on SessionService: bind, audio windows,
 * previews and lifecycle events (docs/02_architecture.md §5.5).
 */
final class TranscriberSessionTest extends PluginTestCase
{
    private SessionStore $store;

    /**
     * @param array<string, string> $settings
     */
    private function service(array $settings = [], ?WhisperClient $whisper = null, ?TranscriptWriter $writer = null): SessionService
    {
        $config = $this->settings($settings);
        $this->store = new SessionStore($this->pluginData(), $config);
        $prosody = $this->createStub(ProsodyClient::class);
        $prosody->method('state')->willReturn([]);
        $participants = $this->createStub(ParticipantResolver::class);
        $participants->method('accounts')->willReturn([]);
        $starter = $this->createStub(User::class);
        $starter->method('getId')->willReturn(2);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($starter);

        return new SessionService(
            $this->store,
            $prosody,
            $whisper ?? $this->createStub(WhisperClient::class),
            new TranscriptRenderer(),
            $writer ?? $this->createStub(TranscriptWriter::class),
            $participants,
            $users,
            $config,
            new NullLogger(),
        );
    }

    /**
     * @param array{text: string, dropped: string|null, speechEndMs: int|null, segmentEndsMs: list<int>} $result
     */
    private function whisperAnswering(array $result): WhisperClient
    {
        $whisper = $this->createStub(WhisperClient::class);
        $whisper->method('recognize')->willReturn($result);

        return $whisper;
    }

    /**
     * @return array{endpointId: string, seq: int, t0Ms: int, t1Ms: int, cut: string, audio: string}
     */
    private static function window(int $seq = 1, int $t0 = 0, int $t1 = 2000, string $cut = 'pause'): array
    {
        return ['endpointId' => 'ep1', 'seq' => $seq, 't0Ms' => $t0, 't1Ms' => $t1, 'cut' => $cut, 'audio' => 'OggS-window'];
    }

    /**
     * @param array{text: string, dropped: string|null} $result
     *
     * @return array{text: string, dropped: string|null, speechEndMs: int|null, segmentEndsMs: list<int>}
     */
    private static function recognized(array $result, ?int $end = null): array
    {
        return $result + ['speechEndMs' => $end, 'segmentEndsMs' => null === $end ? [] : [$end]];
    }

    private static function assertFails(string $code, int $status, callable $call): void
    {
        try {
            $call();
            self::fail(sprintf('Expected %s (%d).', $code, $status));
        } catch (SessionException $e) {
            self::assertSame([$code, $status], [$e->errorCode, $e->httpStatus]);
        }
    }

    public function testBindAnswersWithLanguageCaptionsAndSpeakers(): void
    {
        $sessions = $this->service(['captions' => '0']);
        $this->seedSession($this->store);

        $binding = $sessions->bind(self::REF, 'meeting-1');

        self::assertSame('running', $binding['state']);
        self::assertSame('de', $binding['language']);
        self::assertFalse($binding['captions']);
        self::assertSame(['ep1' => ['name' => 'Ada']], $binding['speakers']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $binding['sessionId']);
    }

    public function testBindRefusesUnknownEndedAndForeignSessions(): void
    {
        $sessions = $this->service();
        self::assertFails('unknown_session', 404, fn () => $sessions->bind(self::REF, 'meeting-1'));

        $this->seedSession($this->store, ['state' => 'saved']);
        self::assertFails('not_active', 409, fn () => $sessions->bind(self::REF, 'meeting-1'));

        $this->seedSession($this->store, ['state' => 'running']);
        self::assertFails('wrong_meeting', 409, fn () => $sessions->bind(self::REF, 'meeting-2'));
    }

    public function testBindLearnsTheMeetingIdOnce(): void
    {
        $sessions = $this->service();
        $this->seedSession($this->store, ['meetingId' => null]);

        $sessions->bind(self::REF, 'meeting-7');

        self::assertSame('meeting-7', $this->store->find(self::REF)['meetingId'] ?? null);
        self::assertFails('wrong_meeting', 409, fn () => $sessions->bind(self::REF, 'meeting-8'));
    }

    public function testAWindowIsRecognisedAndStoredOnceAtItsMeetingTime(): void
    {
        $whisper = $this->createMock(WhisperClient::class);
        $whisper->expects(self::once())->method('recognize')->willReturn(self::recognized(['text' => 'Guten Morgen.', 'dropped' => null], 1500));
        $sessions = $this->service([], $whisper);
        $this->seedSession($this->store, ['originMs' => 1_700_000_000_000]);

        $first = $sessions->window(self::REF, self::window(4, 2500, 4500));
        $retry = $sessions->window(self::REF, self::window(4, 2500, 4500));

        self::assertSame(['Guten Morgen.', null, 'Ada'], [$first['text'], $first['dropped'], $first['speaker']]);
        self::assertSame(['Guten Morgen.', 'duplicate'], [$retry['text'], $retry['dropped']]);
        self::assertSame([['at' => 1_700_000_002_500, 'speaker' => 'ep1', 'text' => 'Guten Morgen.']], $this->store->segments(self::REF));
    }

    public function testAWindowWithoutSpeechStoresNothing(): void
    {
        $sessions = $this->service([], $this->whisperAnswering(self::recognized(['text' => '', 'dropped' => 'no_speech'])));
        $this->seedSession($this->store);

        $answer = $sessions->window(self::REF, self::window());

        self::assertSame(['', 'no_speech'], [$answer['text'], $answer['dropped']]);
        self::assertSame([], $this->store->segments(self::REF));
    }

    public function testInvalidWindowsAreRefusedBeforeRecognition(): void
    {
        $whisper = $this->createMock(WhisperClient::class);
        $whisper->expects(self::never())->method('recognize');
        $sessions = $this->service([], $whisper);
        $this->seedSession($this->store);

        self::assertFails('invalid_window', 422, fn () => $sessions->window(self::REF, self::window(cut: 'sideways')));
        self::assertFails('invalid_window', 422, fn () => $sessions->window(self::REF, self::window(t0: 0, t1: 31_000)));
        self::assertFails('invalid_window', 422, fn () => $sessions->window(self::REF, ['endpointId' => 'ep 1'] + self::window()));
        self::assertFails('invalid_audio', 422, fn () => $sessions->window(self::REF, ['audio' => ''] + self::window()));
    }

    public function testEndedSessionsAcceptNoAudio(): void
    {
        $sessions = $this->service();
        $this->seedSession($this->store, ['state' => 'saved']);

        self::assertFails('not_accepting', 409, fn () => $sessions->window(self::REF, self::window()));
        self::assertFails('not_accepting', 409, fn () => $sessions->preview(self::REF, self::window()));
    }

    public function testAPreviewIsRecognisedButNeverStored(): void
    {
        $sessions = $this->service([], $this->whisperAnswering(['text' => 'Guten', 'dropped' => null, 'speechEndMs' => 900, 'segmentEndsMs' => [400, 900]]));
        $this->seedSession($this->store);

        $preview = $sessions->preview(self::REF, self::window(2));

        self::assertSame(['seq' => 2, 'text' => 'Guten', 'language' => 'de', 'dropped' => null, 'speechEndMs' => 900, 'segmentEndsMs' => [400, 900]], $preview);
        self::assertSame([], $this->store->segments(self::REF));
    }

    public function testNoPreviewsWhileLiveCaptionsAreOffOrTheSessionIsStopping(): void
    {
        $sessions = $this->service(['captions' => '0']);
        $this->seedSession($this->store);
        self::assertFails('captions_off', 403, fn () => $sessions->preview(self::REF, self::window()));

        $sessions = $this->service();
        $this->seedSession($this->store, ['state' => 'stopping']);
        self::assertFails('not_accepting', 409, fn () => $sessions->preview(self::REF, self::window()));
    }

    public function testConnectedSetsTheTimeOriginOnlyOnce(): void
    {
        $sessions = $this->service();
        $this->seedSession($this->store);

        $sessions->event(self::REF, ['type' => 'connected', 'at' => 1_700_000_000_123]);
        $sessions->event(self::REF, ['type' => 'connected', 'at' => 1_700_000_999_999]);

        self::assertSame(1_700_000_000_123, $this->store->find(self::REF)['originMs'] ?? null);
    }

    public function testRecognitionGapsAreCountedAndNamedInTheTranscript(): void
    {
        $writer = $this->createMock(TranscriptWriter::class);
        $sessions = $this->service([], null, $writer);
        $this->seedSession($this->store);
        $this->store->addSegment(self::REF, ['at' => time() * 1000, 'speaker' => 'ep1', 'seq' => 1, 'text' => 'Hallo.']);
        $sessions->event(self::REF, ['type' => 'stt-failure', 'endpointId' => 'ep1', 'at' => 5, 'detail' => 'gap 0-8000']);
        $writer->expects(self::once())->method('write')
            ->with(self::anything(), self::REF, 'Meetings', self::stringStartsWith('Mitschrift '), self::stringContains('Einige Abschnitte fehlen'))
            ->willReturn(77);

        $sessions->event(self::REF, ['type' => 'finished', 'at' => 9]);

        $session = $this->store->find(self::REF);
        self::assertSame(['saved', 1, [2 => 77]], [$session['state'] ?? null, $session['gaps'] ?? null, $session['files'] ?? null]);
        self::assertSame([], $this->store->segments(self::REF));
    }

    public function testFinishedWithoutSpeechWritesNoFile(): void
    {
        $writer = $this->createMock(TranscriptWriter::class);
        $writer->expects(self::never())->method('write');
        $sessions = $this->service([], null, $writer);
        $this->seedSession($this->store);

        $sessions->event(self::REF, ['type' => 'finished', 'at' => 1]);

        self::assertSame('nothing_to_save', $this->store->find(self::REF)['state'] ?? null);
    }

    public function testUnknownEventsAreRefused(): void
    {
        $sessions = $this->service();
        $this->seedSession($this->store);

        self::assertFails('unknown_event', 422, fn () => $sessions->event(self::REF, ['type' => 'reboot']));
        self::assertFails('unknown_session', 404, fn () => $sessions->event('Zz3dEf9hJkLmN0pQrStUvW', ['type' => 'connected']));
    }
}
