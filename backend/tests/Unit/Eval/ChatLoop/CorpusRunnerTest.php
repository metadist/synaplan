<?php

declare(strict_types=1);

namespace App\Tests\Unit\Eval\ChatLoop;

use App\Tests\Eval\ChatLoop\CorpusRunner;
use App\Tests\Eval\ChatLoop\CorpusTurn;
use App\Tests\Eval\ChatLoop\StackClient;
use App\Tests\Eval\ChatLoop\TurnOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CorpusRunnerTest extends TestCase
{
    private const ORIGIN = 'http://localhost:8000';

    /** @var list<string> */
    private array $requests = [];
    /** @var list<array<string, mixed>> */
    private array $inputs = [];
    /** @var list<string> */
    private array $sentCookies = [];
    /** The cookie every request must carry; null when a test follows it itself. */
    private ?string $cookie = 'Cookie: BEARER=abc';
    private int $deleteStatus = 200;
    /** @var list<int> statuses for the next chat creations; 200 once empty */
    private array $chatStatuses = [];

    public function testTheRunFailsWhenATurnHasNoTerminalResult(): void
    {
        $runner = $this->runner(['/api/v1/messages/stream' => [$this->stream(['status' => 'generating'], ['status' => 'data', 'chunk' => 'Half an ans'])]]);

        $outcomes = $runner->run([$this->turn('cut-off')]);

        self::assertSame(TurnOutcome::STATE_NONE, $outcomes[0]->state);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No terminal result for: cut-off');
        CorpusRunner::requireTerminalResults($outcomes);
    }

    public function testAStreamThatAnswersAnErrorHasNoTerminalResultAndSaysWhy(): void
    {
        $runner = $this->runner(['/api/v1/messages/stream' => [new MockResponse('{"error":"busy"}', ['http_code' => 503])]]);

        $outcome = $runner->run([$this->turn('busy')])[0];

        self::assertFalse($outcome->hasTerminalResult());
        self::assertStringContainsString('HTTP 503', (string) $outcome->reason);
    }

    public function testAnAnsweredTurnPassesAndItsChatIsDeleted(): void
    {
        $runner = $this->runner(['/api/v1/messages/stream' => [$this->stream(
            ['status' => 'data', 'chunk' => 'It is 391.'],
            ['status' => 'complete', 'usage' => ['cost' => '0.002', 'totalTokens' => 30]],
        )]]);

        $outcomes = $runner->run([$this->turn('sum', [['391']])]);

        self::assertSame(TurnOutcome::STATE_ANSWERED, $outcomes[0]->state);
        self::assertSame(1.0, $outcomes[0]->recall);
        CorpusRunner::requireTerminalResults($outcomes);
        self::assertSame(['POST /api/v1/auth/login', 'POST /api/v1/chats', 'POST /api/v1/messages/stream', 'DELETE /api/v1/chats/7'], $this->requests);
    }

    public function testAResearchRunIsFollowedToItsEndAndAQuestionIsSkipped(): void
    {
        $runner = $this->runner([
            '/api/v1/messages/stream' => [$this->stream(['status' => 'complete', 'researchRunId' => 'rr_1'])],
            '/api/v1/research/rr_1' => [
                $this->json(['status' => 'waiting_answer', 'questions' => [
                    ['id' => 'q1', 'answer' => 'EU', 'skipped' => false],
                    ['id' => 'q2', 'answer' => null, 'skipped' => false],
                ]]),
                $this->json(['status' => 'waiting_plan']),
                $this->json(['status' => 'complete', 'report' => ['markdown' => 'The report.', 'version' => 1], 'cost' => ['spent' => 0.5]]),
            ],
            '/api/v1/research/rr_1/input' => [$this->json(['status' => 'planning']), $this->json(['status' => 'gathering'])],
        ]);

        $outcome = $runner->run([$this->turn('long')])[0];

        self::assertSame(TurnOutcome::STATE_ANSWERED, $outcome->state);
        self::assertSame('The report.', $outcome->text);
        self::assertSame(0.5, $outcome->cost);
        self::assertSame([['type' => 'skip_question', 'question' => 'q2'], ['type' => 'approve_plan']], $this->inputs);
    }

    public function testAResearchRunStillWorkingAtTheTimeoutHasNoTerminalResult(): void
    {
        $now = 0.0;
        $runner = $this->runner(
            [
                '/api/v1/messages/stream' => [$this->stream(['status' => 'complete', 'researchRunId' => 'rr_1'])],
                '/api/v1/research/rr_1' => [$this->json(['status' => 'gathering']), $this->json(['status' => 'gathering'])],
            ],
            clock: static function () use (&$now): float {
                return $now;
            },
            sleep: static function (float $seconds) use (&$now): void {
                $now += 40.0;
            },
        );

        $outcome = $runner->run([$this->turn('slow')])[0];

        self::assertFalse($outcome->hasTerminalResult());
        self::assertStringContainsString('still working', (string) $outcome->reason);
        self::assertContains('POST /api/v1/research/rr_1/cancel', $this->requests);
    }

    public function testARunThatMovedOnBeforeTheReplyIsReadAgain(): void
    {
        $runner = $this->runner([
            '/api/v1/messages/stream' => [$this->stream(['status' => 'complete', 'researchRunId' => 'rr_1'])],
            '/api/v1/research/rr_1' => [
                $this->json(['status' => 'waiting_plan']),
                $this->json(['status' => 'complete', 'report' => ['markdown' => 'Done.']]),
            ],
            '/api/v1/research/rr_1/input' => [new MockResponse('{"error":"not waiting"}', ['http_code' => 409])],
        ]);

        $outcome = $runner->run([$this->turn('raced')])[0];

        self::assertSame(TurnOutcome::STATE_ANSWERED, $outcome->state);
        self::assertNotContains('POST /api/v1/research/rr_1/cancel', $this->requests);
    }

    public function testAnExpiredAccessCookieIsRefreshedAndTheRequestRepeated(): void
    {
        $runner = $this->runner([
            '/api/v1/messages/stream' => [
                new MockResponse('{"error":"expired"}', ['http_code' => 401]),
                $this->stream(['status' => 'data', 'chunk' => 'Hi.'], ['status' => 'complete']),
            ],
            '/api/v1/auth/refresh' => [new MockResponse('{"success":true}', ['response_headers' => ['set-cookie: BEARER=fresh; path=/; HttpOnly']])],
        ]);
        $this->cookie = null;

        $outcome = $runner->run([$this->turn('late')])[0];

        self::assertSame(TurnOutcome::STATE_ANSWERED, $outcome->state);
        self::assertSame(
            ['POST /api/v1/chats', 'POST /api/v1/messages/stream', 'POST /api/v1/auth/refresh', 'POST /api/v1/messages/stream', 'DELETE /api/v1/chats/7'],
            \array_slice($this->requests, 1),
        );
        self::assertSame(['Cookie: BEARER=abc', 'Cookie: BEARER=abc', 'Cookie: BEARER=abc', 'Cookie: BEARER=fresh', 'Cookie: BEARER=fresh'], $this->sentCookies);
    }

    public function testTheUploadsOfATurnAreDeletedWithItsChat(): void
    {
        $runner = $this->runner([
            '/api/v1/messages/upload-file' => [$this->json(['success' => true, 'file_id' => 41])],
            '/api/v1/messages/stream' => [$this->stream(['status' => 'data', 'chunk' => 'Read.'], ['status' => 'complete'])],
        ]);

        $runner->run([new CorpusTurn('with-file', 'files', 'en', 'Question', false, ['measurements.txt'], [])]);

        self::assertSame(
            ['POST /api/v1/chats', 'POST /api/v1/messages/upload-file', 'POST /api/v1/messages/stream', 'DELETE /api/v1/files/41', 'DELETE /api/v1/chats/7'],
            \array_slice($this->requests, 1),
        );
        self::assertSame([], $runner->leftovers());
    }

    public function testWhatCouldNotBeDeletedIsNamed(): void
    {
        $this->deleteStatus = 500;
        $runner = $this->runner(['/api/v1/messages/stream' => [$this->stream(['status' => 'complete'])]]);

        $outcomes = $runner->run([$this->turn('kept')]);

        self::assertSame(TurnOutcome::STATE_ANSWERED, $outcomes[0]->state);
        self::assertCount(1, $runner->leftovers());
        self::assertStringStartsWith('chat 7: ', $runner->leftovers()[0]);
    }

    public function testARunThatIsNotLiveStopsAtTheFirstChargedTurn(): void
    {
        $runner = $this->runner(
            ['/api/v1/messages/stream' => [$this->stream(['status' => 'complete', 'usage_extra' => [['cost' => '0.004', 'kind' => 'PLAN']]])]],
            live: false,
        );

        $outcomes = $runner->run([$this->turn('planned'), $this->turn('never-sent')]);

        self::assertCount(1, $outcomes);
        self::assertSame('planned', $runner->spendStop());
    }

    public function testAChatThatCannotBeOpenedCostsOneTurnAndNotTheRun(): void
    {
        $this->chatStatuses = [503];
        $runner = $this->runner(['/api/v1/messages/stream' => [$this->stream(['status' => 'data', 'chunk' => 'Yes.'], ['status' => 'complete'])]]);

        $outcomes = $runner->run([$this->turn('no-chat'), $this->turn('fine')]);

        self::assertSame(['none', 'answered'], array_map(static fn (TurnOutcome $o): string => $o->state, $outcomes));
        self::assertStringContainsString('HTTP 503', (string) $outcomes[0]->reason);
    }

    public function testAScriptedChatUsesOneChatAndStopsAtTheFirstTurnWithoutAResult(): void
    {
        $runner = $this->runner(['/api/v1/messages/stream' => [
            $this->stream(['status' => 'data', 'chunk' => 'One.'], ['status' => 'complete']),
            $this->stream(['status' => 'generating']),
        ]]);

        $outcomes = $runner->runAsOneChat('long', [$this->turn('m1'), $this->turn('m2'), $this->turn('m3')]);

        self::assertSame(['answered', 'none'], array_map(static fn (TurnOutcome $o): string => $o->state, $outcomes));
        self::assertSame(1, \count(array_filter($this->requests, static fn (string $r): bool => 'POST /api/v1/chats' === $r)));
    }

    public function testThePasswordIsNotSentOverPlainHttpToAnotherMachine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plain HTTP');
        new StackClient(new MockHttpClient(), 'http://staging.example.com');
    }

    public function testAnOriginWithCredentialsIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        new StackClient(new MockHttpClient(), 'https://user:secret@staging.example.com');
    }

    public function testAnOriginWithAPathIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        new StackClient(new MockHttpClient(), 'https://staging.example.com/api');
    }

    public function testSigningOutRevokesTheSessionAndDropsTheCookies(): void
    {
        $paths = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$paths): MockResponse {
            $paths[] = (string) parse_url($url, PHP_URL_PATH);

            return new MockResponse('{}', ['response_headers' => ['set-cookie: BEARER=abc; path=/']]);
        });
        $stack = new StackClient($http, self::ORIGIN);
        $stack->signIn('eval@example.com', 'not-a-real-password');

        $stack->signOut();
        $stack->signOut();

        self::assertSame(['/api/v1/auth/login', '/api/v1/auth/logout'], $paths);
        $this->expectExceptionMessage('Not signed in');
        $stack->createChat('x');
    }

    public function testNothingIsSentBeforeSigningIn(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            self::fail('A request left without a session.');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not signed in');
        (new StackClient($http, self::ORIGIN))->createChat('x');
    }

    /**
     * @param array<string, list<MockResponse>> $responses path => responses in the order they are asked for
     */
    private function runner(array $responses, ?\Closure $clock = null, ?\Closure $sleep = null, bool $live = true): CorpusRunner
    {
        $responses['/api/v1/auth/login'] = [new MockResponse('{}', ['response_headers' => ['set-cookie: BEARER=abc; path=/; HttpOnly']])];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->requests[] = $method.' '.$path;
            self::assertStringStartsWith(self::ORIGIN, $url);
            if ('/api/v1/auth/login' !== $path) {
                $sent = array_values(array_filter($options['headers'], static fn (string $header): bool => str_starts_with($header, 'Cookie: ')));
                self::assertCount(1, $sent);
                $this->sentCookies[] = $sent[0];
                if (null !== $this->cookie) {
                    self::assertSame($this->cookie, $sent[0]);
                }
            }
            if (str_ends_with($path, '/input')) {
                $this->inputs[] = json_decode((string) $options['body'], true);
            }
            if ('POST' === $method && '/api/v1/chats' === $path) {
                $status = array_shift($this->chatStatuses) ?? 200;

                return 200 === $status ? $this->json(['chat' => ['id' => 7]]) : new MockResponse('{}', ['http_code' => $status]);
            }
            if ('DELETE' === $method) {
                return new MockResponse('{"success":true}', ['http_code' => $this->deleteStatus]);
            }
            if (str_ends_with($path, '/cancel')) {
                return $this->json(['status' => 'stopped']);
            }

            return array_shift($responses[$path]) ?? self::fail('Unexpected request '.$method.' '.$path);
        });
        $stack = new StackClient($http, self::ORIGIN);
        $stack->signIn('eval@example.com', 'not-a-real-password');

        return new CorpusRunner($stack, 60.0, 0.0, $clock, $sleep ?? static function (float $seconds): void {}, live: $live);
    }

    /**
     * @param array<string, mixed> ...$events
     */
    private function stream(array ...$events): MockResponse
    {
        $body = implode('', array_map(static fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));

        // Two chunks, cut inside a frame, as a network would.
        return new MockResponse([substr($body, 0, 9), substr($body, 9)], ['response_headers' => ['content-type: text/event-stream']]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }

    /**
     * @param list<list<string>> $expect
     */
    private function turn(string $id, array $expect = []): CorpusTurn
    {
        return new CorpusTurn($id, 'simple', 'en', 'Question', false, [], $expect);
    }
}
