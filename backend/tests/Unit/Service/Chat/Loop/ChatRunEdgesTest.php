<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallParser;
use App\Entity\Run;
use App\Repository\ConfigRepository;
use App\Repository\RunRepository;
use App\Service\Chat\Loop\ChatLoopConfig;
use App\Service\Chat\Loop\ChatLoopOff;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunPrompt;
use App\Service\Chat\Loop\ChatRunRecords;
use App\Service\Chat\Loop\ChatRunRequest;
use App\Service\Chat\Loop\ChatRunStarter;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStore;
use App\Service\Chat\Loop\FacadeChatRunModel;
use App\Service\Chat\Loop\MessengerChatRunUnitBus;
use App\Service\ModelConfigService;
use App\Tests\Unit\Service\Run\MemoryRunKeys;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChatRunEdgesTest extends TestCase
{
    private const RUN_ID = '01JD6Q3Z8Y9X7W6V5T4S3R2Q1P';

    public function testTheModelCallIsBilledOnTheTurnsModelAndItsToolCallsAreRead(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::once())->method('chat')
            ->with(self::anything(), 7, self::callback(static fn (array $o): bool => 'anthropic' === $o['provider'] && 'claude-sonnet-5-5' === $o['model']
                && 'chat-run-'.self::RUN_ID === $o['usage_turn_id'] && 'auto' === $o['tool_choice'] && 1 === \count($o['tools'])))
            ->willReturn([
                'content' => 'Looking.',
                'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'web_search', 'arguments' => '{"query":"x"}'], 'thought_signature' => 'sig']],
                'facade_charged_cost' => '0.004200',
            ]);

        $reply = $this->model($ai, true)->call($this->state('anthropic'), [], [['type' => 'function', 'function' => ['name' => 'web_search']]], 'auto');

        self::assertSame('Looking.', $reply->content);
        self::assertSame('web_search', $reply->toolCalls[0]['name']);
        self::assertSame(['query' => 'x'], $reply->toolCalls[0]['args']);
        self::assertSame(4_200, $reply->costMicro);
    }

    public function testAModelWithoutToolCallsIsAskedWithoutTools(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::once())->method('chat')
            ->with(self::anything(), 7, self::callback(static fn (array $o): bool => !isset($o['tools']) && !isset($o['tool_choice'])))
            ->willReturn(['content' => 'Plain answer.']);

        $reply = $this->model($ai, false)->call($this->state('ollama'), [], [['type' => 'function', 'function' => ['name' => 'web_search']]], 'auto');

        self::assertSame([], $reply->toolCalls);
    }

    public function testWithAListenerTheCallStreamsAndPassesOnlyTheVisibleText(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects(self::never())->method('chat');
        $ai->expects(self::once())->method('chatStream')
            ->with(self::anything(), self::isCallable(), 7, self::callback(static fn (array $o): bool => 'auto' === $o['tool_choice'] && 1 === \count($o['tools'])))
            ->willReturnCallback(static function (array $messages, callable $chunk): array {
                $chunk(['type' => 'reasoning', 'content' => 'thinking']);
                $chunk(['type' => 'content', 'content' => 'Hel']);
                $chunk('lo.');

                return ['content' => 'Hello.', 'tool_calls' => [], 'facade_charged_cost' => '0.001'];
            });
        $heard = [];

        $reply = $this->model($ai, true)->call($this->state('anthropic'), [], [['type' => 'function', 'function' => ['name' => 'web_search']]], 'auto', static function (string $text) use (&$heard): void {
            $heard[] = $text;
        });

        self::assertSame('Hello.', $reply->content);
        self::assertSame('Hello.', implode('', $heard), 'reasoning is never shown as the answer');
        self::assertSame(1_000, $reply->costMicro);
    }

    public function testAProviderFailureBecomesAModelFailure(): void
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('chat')->willThrowException(new ProviderException('boom', 'anthropic'));

        $this->expectException(ChatRunModelFailed::class);
        $this->model($ai, true)->call($this->state('anthropic'), [], [], null);
    }

    public function testNothingStartsWhileTheLoopIsOff(): void
    {
        $runs = $this->createMock(RunRepository::class);
        $runs->expects(self::never())->method('save');

        $this->expectException(ChatLoopOff::class);
        $this->starter($runs, '0', $this->createStub(MessageBusInterface::class))->start($this->request());
    }

    public function testARetriedStartReturnsTheFirstRunAndQueuesOneUnit(): void
    {
        $saved = [];
        $runs = $this->createStub(RunRepository::class);
        $runs->method('save')->willReturnCallback(static function (Run $run) use (&$saved): void {
            $saved[] = $run;
        });
        $queued = 0;
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $m) use (&$queued): Envelope {
            ++$queued;

            return new Envelope($m);
        });
        $starter = $this->starter($runs, '1', $bus);

        $first = $starter->start($this->request('click-1'));
        $again = $starter->start($this->request('click-1'));

        self::assertTrue($first->opened);
        self::assertFalse($again->opened);
        self::assertSame($first->runId, $again->runId);
        self::assertSame(1, $queued);
        self::assertCount(1, $saved);
        self::assertSame(Run::KIND_CHAT, $saved[0]->getKind());
        self::assertSame(Run::STATUS_RUNNING, $saved[0]->getStatus());
        self::assertSame(11, $saved[0]->getChatId());
    }

    public function testARunThatCouldNotOpenLeavesNoRowRunning(): void
    {
        $row = null;
        $runs = $this->createStub(RunRepository::class);
        $runs->method('save')->willReturnCallback(static function (Run $run) use (&$row): void {
            $row = $run;
        });
        $runs->method('findOneByUuid')->willReturnCallback(static function () use (&$row): ?Run {
            return $row;
        });
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('queue down'));

        try {
            $this->starter($runs, '1', $bus)->start($this->request());
            self::fail('The start must fail with the queue.');
        } catch (\RuntimeException) {
        }

        self::assertInstanceOf(Run::class, $row);
        self::assertSame(Run::STATUS_FAILED, $row->getStatus());
        self::assertSame(ChatRunRecords::NOT_STARTED, $row->getOutcome());
    }

    private function starter(RunRepository $runs, string $env, MessageBusInterface $bus): ChatRunStarter
    {
        $clock = new MockClock('2026-11-20 09:00:00');
        $keys = new MemoryRunKeys($clock);

        return new ChatRunStarter(
            new ChatRunStore($keys, $clock),
            new MessengerChatRunUnitBus($bus),
            new ChatRunRecords($runs, $clock),
            new ChatRunEvents($keys),
            new ChatLoopConfig($this->createStub(ConfigRepository::class), $env),
            $clock,
            $keys,
        );
    }

    private function request(?string $key = null): ChatRunRequest
    {
        return new ChatRunRequest(7, 7, 11, 5, new ChatRunPrompt('anthropic', 'claude-sonnet-5-5', 'de', 'Base', [['role' => 'user', 'content' => 'Hi']]), 'Hi', $key);
    }

    private function model(AiFacade $ai, bool $tools): FacadeChatRunModel
    {
        $capability = $this->createStub(ToolCallingCapability::class);
        $capability->method('supports')->willReturn($tools);

        return new FacadeChatRunModel($ai, $this->createStub(ModelConfigService::class), $capability, new ToolCallParser(), new NullLogger());
    }

    private function state(string $provider): ChatRunState
    {
        return new ChatRunState(self::RUN_ID, 7, 11, null, $provider, 'claude-sonnet-5-5', 'en', '', [], 1);
    }
}
