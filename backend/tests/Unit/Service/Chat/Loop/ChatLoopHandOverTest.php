<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallParser;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\Run;
use App\Repository\ConfigRepository;
use App\Repository\RunRepository;
use App\Repository\UserRepository;
use App\Service\Chat\Loop\ChatLoopConfig;
use App\Service\Chat\Loop\ChatLoopGate;
use App\Service\Chat\Loop\ChatLoopPrompt;
use App\Service\Chat\Loop\ChatLoopTurn;
use App\Service\Chat\Loop\ChatRunDelivery;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunLoopLimits;
use App\Service\Chat\Loop\ChatRunRecords;
use App\Service\Chat\Loop\ChatRunStartBudget;
use App\Service\Chat\Loop\ChatRunStarter;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunStatus;
use App\Service\Chat\Loop\ChatRunTail;
use App\Service\Chat\Loop\MessengerChatRunUnitBus;
use App\Service\Exception\StreamCancelledException;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Media\MediaCancellationStore;
use App\Service\Message\Chat\ChatKnowledge;
use App\Service\Message\Chat\ChatModelChoice;
use App\Service\Message\Chat\ChatPersonalContext;
use App\Service\Message\Chat\ChatReply;
use App\Service\Message\Chat\ChatTurn;
use App\Service\ModelConfigService;
use App\Service\Project\ProjectChatScope;
use App\Service\Project\ProjectTurnContext;
use App\Service\Prompt\ChatPromptBlocks;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The hand-over of a streamed turn to a run: the first call stays in the
 * request, a tool call opens a run that goes on from that very answer, and
 * the stream follows the run to its end.
 */
final class ChatLoopHandOverTest extends ChatLoopCase
{
    private const REPLY_ID = 901;
    private const TRACK_ID = '1763629200123';

    private ?Run $row = null;

    /** @var list<Message> */
    private array $persisted = [];

    /** @var list<Message> */
    private array $removed = [];

    /** @var list<string> */
    private array $streamed = [];

    /** @var list<array{status: string, metadata: array<string, mixed>}> */
    private array $statuses = [];

    /** @var list<string> */
    private array $delivered = [];

    private bool $cancelled = false;

    public function testATurnOffTheLoopKeepsItsCallAsItIs(): void
    {
        $this->model(new ScriptedChatRunModel());

        self::assertNull($this->turn('0')->prepare($this->chatTurn(), $this->choice(), $this->messages(), ['previous_response_id' => 'resp_1'], $this->options()));
    }

    public function testTheFirstCallCarriesTheToolsAndTheLoopRules(): void
    {
        $this->model(new ScriptedChatRunModel());

        $call = $this->turn()->prepare($this->chatTurn(), $this->choice(), $this->messages(), ['previous_response_id' => 'resp_1', 'max_tokens' => 900], $this->options());

        self::assertNotNull($call);
        self::assertStringStartsWith('You help.', (string) $call->messages[0]['content']);
        self::assertStringEndsWith(ChatLoopPrompt::rules(), (string) $call->messages[0]['content']);
        self::assertSame(['web_search', 'custom:order', 'update_plan'], array_map(static fn (array $tool): string => $tool['function']['name'], $call->aiOptions['tools']));
        self::assertSame('auto', $call->aiOptions['tool_choice']);
        self::assertSame(900, $call->aiOptions['max_tokens']);
        self::assertArrayNotHasKey('previous_response_id', $call->aiOptions, 'the run sends the whole transcript');
        self::assertSame([['role' => 'user', 'content' => 'What changed?']], $call->transcript);
        self::assertSame('You help.', $call->basePrompt);
    }

    public function testABudgetThatCannotPayForALongAnswerAnswersWithoutToolsAndSaysWhy(): void
    {
        // One call of this model costs about $0.033; what is left covers that, not the reserve too.
        $this->budget->runCapMicro = 1_000_000;
        $this->budget->leftMicro = 50_000;

        $call = $this->turn()->prepare($this->chatTurn(), $this->choice($this->priced()), $this->messages(), ['previous_response_id' => 'resp_1'], $this->options());

        self::assertNotNull($call);
        self::assertArrayNotHasKey('tools', $call->aiOptions);
        self::assertSame('resp_1', $call->aiOptions['previous_response_id']);
        self::assertStringEndsWith(ChatLoopPrompt::LOW_BUDGET, $call->messages[0]['content']);
        self::assertStringNotContainsString(ChatLoopPrompt::rules(), $call->messages[0]['content']);
    }

    public function testAShareTooSmallForTheModelSaysSoNotThatTheBudgetIsUsedUp(): void
    {
        // Plenty left this month, but the chosen share cannot pay for the first call and the reserve.
        $this->budget->runCapMicro = 50_000;
        $this->budget->leftMicro = 9_000_000;

        $call = $this->turn()->prepare($this->chatTurn(), $this->choice($this->priced()), $this->messages(), [], $this->options());

        self::assertNotNull($call);
        self::assertArrayNotHasKey('tools', $call->aiOptions);
        self::assertStringEndsWith(ChatLoopPrompt::LOW_LIMIT, $call->messages[0]['content']);
    }

    public function testABudgetThatPaysForTheFirstCallAndTheReserveStartsTheLoop(): void
    {
        $this->budget->runCapMicro = 1_000_000;
        $this->budget->leftMicro = 80_000;

        $call = $this->turn()->prepare($this->chatTurn(), $this->choice($this->priced()), $this->messages(), [], $this->options());

        self::assertNotNull($call);
        self::assertArrayHasKey('tools', $call->aiOptions);
    }

    public function testAModelWithoutAPriceIsNeverHeldBackAtTheStart(): void
    {
        $this->budget->runCapMicro = 1_000;
        $this->budget->leftMicro = 1_000;

        $call = $this->turn()->prepare($this->chatTurn(), $this->choice(), $this->messages(), [], $this->options());

        self::assertNotNull($call);
        self::assertArrayHasKey('tools', $call->aiOptions);
    }

    public function testTheRunPromptLeavesOutWhatOnlyTheProjectGives(): void
    {
        $this->model(new ScriptedChatRunModel());
        $project = new ProjectTurnContext('grp_p1', 'Kitchen', 'Answer in metric units.');
        $block = ChatPromptBlocks::projectInstructions('Kitchen', 'Answer in metric units.');
        $knowledge = "\n\n[Sources]\nThe hob is 60 cm wide.";
        $turn = $this->chatTurn(new ChatKnowledge($knowledge));
        $messages = [['role' => 'system', 'content' => 'You help.'.$knowledge.$block], ['role' => 'user', 'content' => 'How wide?']];

        $call = $this->turn('1', $project)->prepare($turn, $this->choice(), $messages, [], $this->options(['rag_group_key' => 'grp_p1', 'project_name' => 'Kitchen', 'project_instructions' => 'Answer in metric units.']));

        self::assertNotNull($call);
        self::assertSame('You help.', $call->basePrompt, 'a unit adds the project only while the chat is still in it');
        self::assertStringContainsString('metric', (string) $call->messages[0]['content'], 'the request itself still has the project');
        self::assertContains('knowledge_search', array_map(static fn (array $tool): string => $tool['function']['name'], $call->aiOptions['tools']));
    }

    public function testAToolCallHandsOverAndTheRunGoesOnFromThatAnswer(): void
    {
        $model = $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::says('The release fixed the login.')));
        $turn = $this->turn();
        $chatTurn = $this->chatTurn();
        $call = $turn->prepare($chatTurn, $this->choice(), $this->messages(), [], $this->options());
        self::assertNotNull($call);

        $meta = $turn->handOver($call, $chatTurn, $this->choice(), $this->firstAnswer('Let me look.', 'web_search'), $this->stream(...), $this->progress(...));

        self::assertCount(1, $model->calls, 'the first answer is not paid for again');
        self::assertSame(['web_search'], array_column($this->tools->runs, 'name'));
        self::assertSame("\n\nThe release fixed the login.", implode('', $this->streamed), 'the answer reaches the stream once, as it is written');
        self::assertSame("Let me look.\n\nThe release fixed the login.", ChatRunDelivery::text($this->stored((string) $meta[ChatRunDelivery::META])), 'the stored text is what the person saw');
        self::assertCount(1, $this->persisted, 'one message for the answer');
        self::assertSame('processing', $this->persisted[0]->getStatus());
        self::assertSame('Let me look.', $this->persisted[0]->getText());
        self::assertSame(self::REPLY_ID, $meta[ChatLoopTurn::REPLY_ID]);
        $runId = (string) $meta[ChatRunDelivery::META];
        $state = $this->stored($runId);
        self::assertSame(ChatRunStatus::Completed, $state->status);
        self::assertSame(self::REPLY_ID, $state->replyMessageId);
        self::assertSame('The release fixed the login.', $state->answer);
        self::assertContains($runId, $this->delivered);
        self::assertSame(ChatRunEvents::STARTED, $this->statuses[0]['metadata']['event']);
        self::assertCount(1, array_filter($this->statuses, static fn (array $s): bool => ChatRunEvents::STARTED === $s['metadata']['event']), 'the run is announced once');
        self::assertContains(ChatRunEvents::TOOL_CALL, array_map(static fn (array $s): string => (string) $s['metadata']['event'], $this->statuses));
        self::assertNotContains(ChatRunEvents::TEXT, array_map(static fn (array $s): string => (string) $s['metadata']['event'], $this->statuses), 'text goes to the answer, not to the statuses');
        self::assertContains(ChatRunEvents::ENDED, $this->eventNames($runId));
        self::assertSame($runId, $this->row?->getUuid());
    }

    public function testAWriteInTheFirstAnswerWaitsForItsOwnerAndTheStreamStops(): void
    {
        $this->model(new ScriptedChatRunModel());
        $turn = $this->turn();
        $chatTurn = $this->chatTurn();
        $call = $turn->prepare($chatTurn, $this->choice(), $this->messages(), [], $this->options());
        self::assertNotNull($call);

        $meta = $turn->handOver($call, $chatTurn, $this->choice(), $this->firstAnswer('', 'custom:order', ['item' => 'hob']), $this->stream(...), $this->progress(...));

        $state = $this->stored((string) $meta[ChatRunDelivery::META]);
        self::assertSame(ChatRunStatus::Waiting, $state->status);
        self::assertSame(FakeChatRunToolbox::APPROVAL_ID, $state->awaitedApproval());
        self::assertSame([null], array_column($this->tools->runs, 'approvalId'), 'never approved on its own');
        self::assertSame([], $this->streamed);
        self::assertSame([], $this->delivered);
    }

    public function testStopInTheChatStopsTheRun(): void
    {
        $this->model(new ScriptedChatRunModel(ScriptedChatRunModel::says('late')));
        $this->cancelled = true;
        $turn = $this->turn();
        $chatTurn = $this->chatTurn();
        $call = $turn->prepare($chatTurn, $this->choice(), $this->messages(), [], $this->options());
        self::assertNotNull($call);

        try {
            $turn->handOver($call, $chatTurn, $this->choice(), $this->firstAnswer('', 'web_search'), $this->stream(...), $this->progress(...));
            self::fail('Stop must end the stream.');
        } catch (StreamCancelledException) {
        }

        self::assertNotNull($this->row);
        self::assertTrue($this->store->runs()->cancelRequested($this->row->getUuid()));
        self::assertSame([], $this->streamed);
    }

    public function testAStartThatFailsLeavesNoMessageBehind(): void
    {
        $this->model(new ScriptedChatRunModel());
        $turn = $this->turn(queueDown: true);
        $chatTurn = $this->chatTurn();
        $call = $turn->prepare($chatTurn, $this->choice(), $this->messages(), [], $this->options());
        self::assertNotNull($call);

        try {
            $turn->handOver($call, $chatTurn, $this->choice(), $this->firstAnswer('', 'web_search'), $this->stream(...), null);
            self::fail('The start must fail with the queue.');
        } catch (\RuntimeException) {
        }

        self::assertSame($this->persisted, $this->removed);
    }

    private function turn(string $env = '1', ?ProjectTurnContext $project = null, bool $queueDown = false): ChatLoopTurn
    {
        $config = new ChatLoopConfig($this->createStub(ConfigRepository::class), $env);
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(3);
        $models->method('getProviderForModel')->willReturn('test');
        $capability = $this->createStub(ToolCallingCapability::class);
        $capability->method('supports')->willReturn(true);
        $projects = $this->createStub(ProjectChatScope::class);
        $projects->method('forChat')->willReturn($project);

        $runs = $this->createStub(RunRepository::class);
        $runs->method('save')->willReturnCallback(function (Run $run): void {
            $this->row = $run;
        });
        $runs->method('findOneByUuid')->willReturnCallback(fn (): ?Run => $this->row);
        $bus = $this->createStub(MessageBusInterface::class);
        if ($queueDown) {
            $bus->method('dispatch')->willThrowException(new \RuntimeException('queue down'));
        } else {
            $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
                $this->bus->dispatch($message->runId(), $message->unit(), $message->seq(), 0);

                return new Envelope($message);
            });
        }
        $this->keys->whileAwaiting = function (): void {
            $this->drain();
        };
        $starter = new ChatRunStarter($this->store, new MessengerChatRunUnitBus($bus), new ChatRunRecords($runs, $this->clock), $this->events, $config, $this->clock, $this->keys);
        $cancellations = $this->createStub(MediaCancellationStore::class);
        $cancellations->method('isCancelled')->willReturnCallback(fn (string $trackId): bool => $this->cancelled && self::TRACK_ID === $trackId);
        $delivery = $this->createStub(ChatRunDelivery::class);
        $delivery->method('deliver')->willReturnCallback(function (ChatRunState $state): bool {
            $this->delivered[] = $state->runId;

            return true;
        });

        return new ChatLoopTurn(
            new ChatLoopGate($config, $models, $capability, new ChatRunStartBudget($this->budget, $this->rates(), new ChatRunLoopLimits())),
            $capability,
            new ToolCallParser(),
            $this->createStub(UserRepository::class),
            $projects,
            $this->tools,
            $starter,
            new ChatRunTail($this->store, $this->events, $starter, $cancellations, $this->clock),
            $delivery,
            $this->em(),
        );
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Message) {
                (new \ReflectionProperty(Message::class, 'id'))->setValue($entity, self::REPLY_ID);
                $this->persisted[] = $entity;
            }
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Message) {
                $this->removed[] = $entity;
            }
        });

        return $em;
    }

    private function chatTurn(ChatKnowledge $knowledge = new ChatKnowledge()): ChatTurn
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(self::CHAT_ID);
        $chat->method('getUserId')->willReturn(self::USER_ID);
        $message = (new Message())
            ->setUserId(self::USER_ID)
            ->setChat($chat)
            ->setTrackingId((int) self::TRACK_ID)
            ->setProviderIndex('WEB')
            ->setText('What changed?')
            ->setDirection('IN');
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, 5);

        return new ChatTurn($message, [], ['topic' => 'general', 'language' => 'de'], ['track_id' => self::TRACK_ID], null, null, new PerfTimer(), $knowledge, new ChatPersonalContext($this->user));
    }

    private function choice(?Model $model = null): ChatModelChoice
    {
        return new ChatModelChoice(3, 'test', 'test-model', self::USER_ID, false, false, false, $model);
    }

    private function rates(): RateLimitService
    {
        $rates = $this->createStub(RateLimitService::class);
        $rates->method('getMarkupPercent')->willReturn(10.0);

        return $rates;
    }

    /** A priced model: $3 in and $15 out per million tokens. */
    private function priced(): Model
    {
        return (new Model())->setPriceIn(3.0)->setInUnit('per1M')->setPriceOut(15.0)->setOutUnit('per1M');
    }

    /** @return list<array<string, mixed>> */
    private function messages(): array
    {
        return [['role' => 'system', 'content' => 'You help.'], ['role' => 'user', 'content' => 'What changed?']];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function options(array $extra = []): array
    {
        return [ChatLoopGate::OPTION => true, 'track_id' => self::TRACK_ID, ...$extra];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function firstAnswer(string $text, string $tool, array $args = ['query' => 'release']): ChatReply
    {
        return new ChatReply($text, ['provider' => 'test', 'model' => 'test-model'], [
            ['id' => 'call_9', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($args)]],
        ]);
    }

    private function stream(string $chunk): void
    {
        $this->streamed[] = $chunk;
    }

    /**
     * @param array{status: string, message: string, metadata?: array<string, mixed>} $update
     */
    private function progress(array $update): void
    {
        $this->statuses[] = ['status' => $update['status'], 'metadata' => $update['metadata'] ?? []];
    }
}
