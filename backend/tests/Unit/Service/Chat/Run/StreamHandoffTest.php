<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Run;

use App\Entity\User;
use App\Service\Chat\Run\ChatTurnJob;
use App\Service\Chat\Run\MemoryRunStream;
use App\Service\Chat\Run\PumpFrames;
use App\Service\Chat\Run\RunReapDecision;
use App\Service\Chat\Run\RunStreamFrameSink;
use App\Service\Chat\Run\RunStreamTail;
use App\Service\Chat\Run\StreamJobGuard;
use App\Service\Chat\Stream\AttachToken;
use App\Service\Chat\Stream\RelayProof;
use App\Service\Chat\Stream\StreamCaller;
use App\Service\Chat\Stream\StreamTurnOptions;
use App\Service\Chat\Stream\StreamTurnRequest;
use App\Service\Chat\Stream\StreamUserInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StreamHandoffTest extends TestCase
{
    public function testAJobRejectsAProviderKeyAndKeepsASentence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamJobGuard::reject(['api_key' => 'sk-secret']);
    }

    public function testASentenceAboutAuthorizationIsStillAJob(): void
    {
        StreamJobGuard::reject([
            'message' => 'Explain authorization in Symfony.',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testAHeaderLineIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamJobGuard::reject(['headers' => ['Authorization: Bearer sk-example']]);
    }

    public function testTheAttachTokenRejectsAWidenedCursorAndAnExpiredToken(): void
    {
        $token = AttachToken::sign('test-secret', 'run-1', 'user:7', 4, 2_000_000_000);
        self::assertTrue(AttachToken::verify('test-secret', $token, 'run-1', 'user:7', 4, 1_900_000_000));
        self::assertFalse(AttachToken::verify('test-secret', $token, 'run-1', 'user:7', 0, 1_900_000_000));
        self::assertFalse(AttachToken::verify('test-secret', $token, 'run-1', 'user:7', 4, 2_000_000_001));
        self::assertFalse(AttachToken::verify('other', $token, 'run-1', 'user:7', 4, 1_900_000_000));
    }

    public function testTheRelayHeaderOneIsNotAProof(): void
    {
        putenv('SYNAPLAN_RELAY_SECRET=s3cret');
        try {
            $proof = new RelayProof();
            $loopback = Request::create('/api/v1/messages/stream', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
            $loopback->headers->set('X-Synaplan-Relay', '1');
            self::assertFalse($proof->accepts($loopback));

            $external = Request::create('/api/v1/messages/stream', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.8']);
            $external->headers->set('X-Synaplan-Relay', 's3cret');
            self::assertFalse($proof->accepts($external));

            $watchdog = Request::create('/api/v1/messages/stream', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
            $watchdog->headers->set('X-Synaplan-Relay', 's3cret');
            self::assertTrue($proof->accepts($watchdog));
        } finally {
            putenv('SYNAPLAN_RELAY_SECRET');
        }
    }

    public function testAQueuedRunPastItsDeadlineFailsAndALivePumpDoesNot(): void
    {
        self::assertTrue(RunReapDecision::shouldFail('queued', 100, 101, true));
        self::assertFalse(RunReapDecision::shouldFail('queued', 100, 100, false));
        self::assertTrue(RunReapDecision::shouldFail('running', 100, 200, false));
        self::assertFalse(RunReapDecision::shouldFail('running', 100, 200, true));
        self::assertFalse(RunReapDecision::shouldFail('complete', 100, 200, false));
    }

    public function testACompleteEventIsTheTerminalFrame(): void
    {
        $stream = new MemoryRunStream();
        $sink = new RunStreamFrameSink($stream, 'run-1');
        $sink->write("data: {\"status\":\"data\",\"chunk\":\"Hi\"}\n\n");
        $sink->write("data: {\"status\":\"complete\"}\n\n");
        $frames = $stream->after('run-1', 0);

        self::assertSame('frame', $frames[0]->kind);
        self::assertSame(1, $frames[0]->seq);
        self::assertSame('terminal', $frames[1]->kind);
        self::assertSame('0-2', $frames[1]->id());
    }

    public function testTheTailStopsAtTheTerminalFrame(): void
    {
        $stream = new MemoryRunStream();
        $stream->append('run-1', 1, 'frame', "data: {\"status\":\"data\",\"chunk\":\"Hi\"}\n\n");
        $stream->append('run-1', 2, 'terminal', "data: {\"status\":\"complete\"}\n\n");
        $written = '';
        (new RunStreamTail($stream))->follow('run-1', 1, static function (string $bytes) use (&$written): void {
            $written .= $bytes;
        });

        self::assertStringContainsString('"chunk":"Hi"', $written);
        self::assertStringContainsString('"status":"complete"', $written);
        self::assertStringNotContainsString(PumpFrames::DID_NOT_START, $written);
    }

    public function testAChatTurnRoundTripsWithoutAKey(): void
    {
        $user = new User();
        $id = new \ReflectionProperty(User::class, 'id');
        $id->setValue($user, 7);
        $turn = new StreamTurnRequest(
            caller: new StreamCaller($user, 'de', 'DE'),
            chatId: '12',
            trackId: 99,
            input: new StreamUserInput('Hello', [3], null, null),
            options: new StreamTurnOptions(reasoning: true, webSearch: true, modelId: '53'),
        );
        $payload = ChatTurnJob::payload($turn, 'run-9');
        StreamJobGuard::reject($payload);
        $restored = ChatTurnJob::restore($payload, $user);

        self::assertNotNull($restored);
        self::assertSame('run-9', $restored->assignedRunId);
        self::assertSame('Hello', $restored->input->message);
        self::assertSame([3], $restored->input->fileIds);
        self::assertSame('de', $restored->caller->uiLanguage);
        self::assertTrue($restored->options->reasoning);
        self::assertSame('53', $restored->options->modelId);
        self::assertSame('12', $restored->chatId);
        self::assertSame(7, $restored->caller->user->getId());
    }
}
