<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Effort;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\Message\Effort\AutoResearchGate;
use App\Service\Message\Effort\Effort;
use App\Service\Message\Effort\EffortReason;
use App\Service\Message\Effort\EffortVerdict;
use App\Service\Message\Effort\GateDecision;
use App\Service\Message\Effort\GateTurn;
use App\Service\RateLimitService;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchRunService;
use App\Tests\Unit\Service\Research\Run\ReplaySearch;
use App\Tests\Unit\Service\Research\Run\ResearchUnitCase;
use App\Tests\Unit\Service\Research\Run\SilentResearchReport;
use Psr\Log\NullLogger;

final class AutoResearchGateTest extends ResearchUnitCase
{
    private ReplaySearch $search;

    private bool $budgetAllowed = true;

    private string $autoRow = '1';

    public function testAResearchRatingStartsAnAutomaticRunOnce(): void
    {
        $gate = $this->gate();
        $message = $this->message();

        $first = $gate->decide($message, $this->rated('research', 'several_parts'), []);

        self::assertTrue($first->startedRun());
        self::assertSame(6, $first->minutes);
        self::assertSame('several_parts', $first->why);
        $run = $this->store->load((string) $first->runId);
        self::assertSame(ResearchDepth::Auto, $run->ref->depth);
        self::assertSame(11, $run->ref->chatId);
        self::assertSame('Compare A and B', $run->ref->question);
        self::assertNull($run->hint);

        $again = $gate->decide($message, $this->rated('research', 'several_parts'), []);

        self::assertSame($first->runId, $again->runId, 'a retried stream reuses the run of this message');
    }

    public function testAShortLookAskedForBecomesABriefHint(): void
    {
        $started = $this->gate()->decide($this->message(), $this->rated('research', 'asked_short'), []);

        self::assertSame('brief', $this->store->load((string) $started->runId)?->hint);
    }

    public function testAnOrdinaryRatingDoesNothing(): void
    {
        $gate = $this->gate();

        self::assertFalse($gate->decide($this->message(), $this->rated('answer', 'simple'), [])->startedRun());
        self::assertFalse($gate->decide($this->message(), $this->rated('lookup', 'current_fact'), [])->startedRun());
        self::assertNull($gate->decide($this->message(), $this->rated('lookup', 'current_fact'), [])->heldBackBy());
        self::assertFalse($gate->decide($this->message(), ['effort' => 'research', 'effort_why' => 'comparison', 'effort_voted' => false], [])->startedRun(), 'no vote, no run');
    }

    public function testTurnsThatNeverSpeakAboutResearchAreSilent(): void
    {
        $gate = $this->gate();
        $rated = $this->rated('research', 'comparison');

        foreach ([['incognito' => true], ['is_widget_mode' => true], ['is_again' => true], ['fixed_task_prompt' => 'x'], ['saved_task_id' => 3]] as $options) {
            $decision = $gate->decide($this->message(), $rated, $options);
            self::assertFalse($decision->startedRun(), json_encode($options) ?: '');
            self::assertNull($decision->heldBackBy());
        }
        $channel = $this->message();
        $channel->setMessageType('WA');
        self::assertFalse($gate->decide($channel, $rated, [])->startedRun());
    }

    public function testTheSwitchOffMeansNothingExists(): void
    {
        $this->autoRow = '0';

        $decision = $this->gate()->decide($this->message(), $this->rated('research', 'comparison'), []);

        self::assertFalse($decision->startedRun());
        self::assertNull($decision->heldBackBy());
    }

    public function testWithoutASourceTheAnswerNamesIt(): void
    {
        $gate = $this->gate();
        $this->search->available = false;

        self::assertSame(GateDecision::NO_SOURCES, $gate->decide($this->message(), $this->rated('research', 'comparison'), [])->heldBackBy());

        $withFolder = $this->rated('research', 'comparison') + ['rag_group_key' => 'DESKTOP:42'];
        $decision = $gate->decide($this->message(), $withFolder, []);

        self::assertTrue($decision->startedRun(), 'a folder on the chat is a source');
        self::assertSame(['DESKTOP:42'], $this->store->load((string) $decision->runId)?->knowledgeKeys);
    }

    public function testAUsedUpBudgetHoldsTheRunBack(): void
    {
        $this->budgetAllowed = false;

        self::assertSame(GateDecision::BUDGET, $this->gate()->decide($this->message(), $this->rated('research', 'comparison'), [])->heldBackBy());
    }

    public function testTooManyRunsHoldTheRunBack(): void
    {
        $gate = $this->gate(maxActive: 1);
        $first = $gate->decide($this->message(1), $this->rated('research', 'comparison'), []);
        self::assertTrue($first->startedRun());

        self::assertSame(GateDecision::TOO_MANY, $gate->decide($this->message(2), $this->rated('research', 'comparison'), [])->heldBackBy());
    }

    public function testATurnWithoutAStoredMessageGoesThroughTheSameDoor(): void
    {
        $gate = $this->gate();
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $turn = new GateTurn(
            question: 'Compare the visa rules of four countries',
            verdict: new EffortVerdict(Effort::Research, EffortReason::Comparison, true),
            folder: null,
            language: 'en',
            turns: [['role' => 'user', 'text' => 'Hello']],
            chatId: null,
            assistantId: null,
            idempotencyKey: 'gw:7:abc',
        );

        $first = $gate->decideFor($user, $turn);
        $again = $gate->decideFor($user, $turn);

        self::assertTrue($first->startedRun());
        self::assertSame($first->runId, $again->runId, 'the same key never starts a second run');
        self::assertNull($this->store->load((string) $first->runId)?->ref->chatId);

        $this->autoRow = '0';
        self::assertFalse($this->gate()->decideFor($user, $turn)->startedRun());
    }

    /**
     * @return array<string, mixed>
     */
    private function rated(string $effort, string $why): array
    {
        return ['effort' => $effort, 'effort_why' => $why, 'effort_voted' => true, 'language' => 'de'];
    }

    private function message(int $id = 1): Message
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('getUserId')->willReturn(7);
        $chat->method('getId')->willReturn(11);
        $message = new Message();
        $message->setUserId(7);
        $message->setMessageType('WEB');
        $message->setText('Compare A and B');
        $message->setDirection('IN');
        $message->setChat($chat);
        $message->setChatId(11);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function gate(int $maxActive = 5): AutoResearchGate
    {
        $this->search = new ReplaySearch();
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            fn (int $owner, string $group, string $setting): ?string => match ($setting) {
                ResearchConfig::AUTO_ENABLED => $this->autoRow,
                ResearchConfig::MAX_ACTIVE_RUNS => (string) $maxActive,
                default => null,
            },
        );
        $config = new ResearchConfig($repo, '1');
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $chat = $this->createStub(Chat::class);
        $chat->method('getUserId')->willReturn(7);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturn($chat);
        $runs = new ResearchRunService($this->store, $this->bus, $this->events, $config, $chats, $this->clock, new SilentResearchReport());
        $rateLimits = $this->createStub(RateLimitService::class);
        $rateLimits->method('checkCostBudget')->willReturnCallback(fn (): array => ['allowed' => $this->budgetAllowed]);

        return new AutoResearchGate($config, $runs, $this->search, $users, $rateLimits, new NullLogger());
    }
}
