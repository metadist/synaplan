<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Service\Chat\Loop\ChatRunApprovals;
use App\Service\Chat\Loop\ChatRunCheck;
use App\Service\Chat\Loop\ChatRunCheckModel;
use App\Service\Chat\Loop\ChatRunDelivery;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunLoopLimits;
use App\Service\Chat\Loop\ChatRunModel;
use App\Service\Chat\Loop\ChatRunRates;
use App\Service\Chat\Loop\ChatRunRecords;
use App\Service\Chat\Loop\ChatRunScope;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStep;
use App\Service\Chat\Loop\ChatRunStore;
use App\Service\Chat\Loop\ChatRunUnitHandler;
use App\Service\Project\ProjectTurnContext;
use App\Service\Research\Run\Lead\ScriptedModelCaller;
use App\Service\Research\Run\ResearchModelChoice;
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

    /** The model's price a new run carries; null is a model without one. */
    protected ?ChatRunRates $rates = null;

    /** The model's context window a new run carries, in tokens. */
    protected ?int $window = null;

    /** @var list<int> pictures a new run has already looked at */
    protected array $analyzed = [];

    /** @var list<array{role: string, content: string}> the chat history a new run starts from, before the question */
    protected array $history = [];
    protected User $user;

    /** The checking model's answers; it is asked only while {@see $checkModel} is set. */
    protected ScriptedModelCaller $checker;
    protected ?ResearchModelChoice $checkModel = null;

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
        $this->checker = new ScriptedModelCaller();
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
        $this->build($model);

        return $model;
    }

    /** A model whose compaction calls answer from their own script. */
    protected function compacting(CompactingChatRunModel $model): CompactingChatRunModel
    {
        $this->build($model);

        return $model;
    }

    private function build(ChatRunModel $model): void
    {
        $this->step = new ChatRunStep($this->world, $model, $this->budget, $this->tools, $this->events, new ChatRunLoopLimits());
        $records = $this->createStub(ChatRunRecords::class);
        $records->method('sync')->willReturnCallback(function (ChatRunState $state): void {
            $this->synced[] = $state->status->value;
        });
        $this->delivery = $this->createStub(ChatRunDelivery::class);
        $picker = new class($this) implements ChatRunCheckModel {
            public function __construct(private readonly ChatLoopCase $case)
            {
            }

            public function pick(ChatRunState $state): ?ResearchModelChoice
            {
                return $this->case->checkModel();
            }
        };
        $check = new ChatRunCheck($this->world, $this->budget, $picker, $this->checker, $this->events);
        $this->handler = new ChatRunUnitHandler($this->step, $check, $records, $this->delivery, new ChatRunApprovals($this->approvalRows(), $this->clock), new NullLogger());
    }

    public function checkModel(): ?ResearchModelChoice
    {
        return $this->checkModel;
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
            [...$this->history, ['role' => 'user', 'content' => $question]],
            $this->clock->now()->getTimestamp(),
        );
        $state->rates = $this->rates;
        $state->window = $this->window;
        $state->head = \count($state->messages);
        $state->analyzed = $this->analyzed;
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
