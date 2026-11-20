<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Service\Chat\Loop\ChatRunApprovals;
use App\Service\Chat\Loop\ChatRunDelivery;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunRecords;
use App\Service\Chat\Loop\ChatRunScope;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStep;
use App\Service\Chat\Loop\ChatRunStore;
use App\Service\Chat\Loop\ChatRunUnitHandler;
use App\Service\Project\ProjectTurnContext;
use App\Service\Run\RunIds;
use App\Service\Run\RunUnit;
use App\Tests\Unit\Service\Run\MemoryRunKeys;
use App\Tests\Unit\Service\Run\RecordingRunUnitBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * A chat run on the real kernel with an in-memory Redis: the store, the
 * events, the unit handler and the queue are the production classes; the
 * model, the tools, the budget and the world are scripted.
 */
abstract class ChatLoopCase extends TestCase
{
    protected const USER_ID = 7;
    protected const CHAT_ID = 11;

    protected MockClock $clock;
    protected MemoryRunKeys $keys;
    protected ChatRunStore $store;
    protected ChatRunEvents $events;
    protected RecordingRunUnitBus $bus;
    protected FakeChatRunToolbox $tools;
    protected SwitchedChatRunBudget $budget;
    protected FixedChatRunWorld $world;
    protected ChatRunStep $step;
    protected ChatRunUnitHandler $handler;
    protected ChatRunDelivery $delivery;
    protected User $user;

    /** @var list<string> the run statuses the row was synced to */
    protected array $synced = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-20 09:00:00');
        $this->keys = new MemoryRunKeys($this->clock);
        $this->store = new ChatRunStore($this->keys, $this->clock);
        $this->events = new ChatRunEvents($this->keys);
        $this->bus = new RecordingRunUnitBus();
        $this->tools = new FakeChatRunToolbox();
        $this->budget = new SwitchedChatRunBudget();
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('isActive')->willReturn(true);
        $this->user = $user;
        $this->world = new FixedChatRunWorld($this->scope());
    }

    protected function scope(?ProjectTurnContext $project = null): ChatRunScope
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(self::CHAT_ID);
        $chat->method('getUserId')->willReturn(self::USER_ID);

        return new ChatRunScope($this->user, $chat, $project);
    }

    protected function model(ScriptedChatRunModel $model): ScriptedChatRunModel
    {
        $this->step = new ChatRunStep($this->world, $model, $this->budget, $this->tools, $this->events);
        $records = $this->createStub(ChatRunRecords::class);
        $records->method('sync')->willReturnCallback(function (ChatRunState $state): void {
            $this->synced[] = $state->status->value;
        });
        $this->delivery = $this->createStub(ChatRunDelivery::class);
        $this->handler = new ChatRunUnitHandler($this->step, $records, $this->delivery, new ChatRunApprovals($this->approvalRows(), $this->clock), new NullLogger());

        return $model;
    }

    /** The approval rows the run's handler sees; a test that needs some overrides this. */
    protected function approvalRows(): ApprovalRepository
    {
        return $this->createStub(ApprovalRepository::class);
    }

    protected function open(string $question = 'What changed in the release?'): ChatRunState
    {
        $state = new ChatRunState(
            RunIds::newId($this->clock),
            self::USER_ID,
            self::CHAT_ID,
            5,
            'test',
            'test-model',
            'de',
            'You are a helpful assistant.',
            [['role' => 'user', 'content' => $question]],
            $this->clock->now()->getTimestamp(),
        );
        $this->store->runs()->open($state);
        $this->bus->dispatch($state->runId, ChatRunStep::UNIT, 1, 0);

        return $state;
    }

    /** Runs every unit the queue holds, in order, until it is empty. */
    protected function drain(int $max = 20): void
    {
        $unit = new RunUnit($this->store->runs(), $this->handler, $this->bus);
        while ([] !== $this->bus->queued && $max-- > 0) {
            $next = array_shift($this->bus->queued);
            $unit->handle($next['runId'], $next['unit'], $next['seq']);
        }
    }

    protected function stored(string $runId): ChatRunState
    {
        $state = $this->store->load($runId);
        self::assertInstanceOf(ChatRunState::class, $state);

        return $state;
    }

    /** @return list<string> */
    protected function eventNames(string $runId): array
    {
        return array_map(static fn (array $record): string => $record['event'], $this->events->replay($runId, 0));
    }
}
