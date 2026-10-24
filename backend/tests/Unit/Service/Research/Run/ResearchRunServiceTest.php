<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\ConfigRepository;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunNotFound;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchUnitPlan;

final class ResearchRunServiceTest extends ResearchUnitCase
{
    private ResearchRunService $runs;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createStub(User::class);
        $this->user->method('getId')->willReturn(7);
        $chat = $this->createStub(Chat::class);
        $chat->method('getUserId')->willReturn(7);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturnCallback(static fn (int $id): ?Chat => 11 === $id ? $chat : null);
        $this->runs = new ResearchRunService(
            $this->store,
            $this->bus,
            $this->events,
            new ResearchConfig($this->createStub(ConfigRepository::class), '1'),
            $chats,
            $this->clock,
        );
    }

    public function testARepeatedStartReturnsTheSameRun(): void
    {
        $first = $this->runs->start($this->user, $this->body(), true, 'retry-1');
        $second = $this->runs->start($this->user, $this->body(), true, 'retry-1');

        self::assertSame($first->ref->id, $second->ref->id);
        self::assertCount(1, $this->bus->sent);
        self::assertSame(ResearchUnitPlan::BRIEF_PLAN, $this->bus->sent[0]['unit']);
        self::assertSame(0, $this->bus->sent[0]['delayMs']);
    }

    public function testTheSessionRouteNeedsTheCallersChat(): void
    {
        try {
            $this->runs->start($this->user, ['question' => 'Prices?', 'depth' => 'standard'], true, null);
            self::fail('A session start without a chat should be rejected.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(400, $rejected->status);
            self::assertSame('chat_id', $rejected->field);
        }

        try {
            $this->runs->start($this->user, ['question' => 'Prices?', 'depth' => 'standard', 'chat_id' => 11], false, null);
            self::fail('An API start must not take a chat.');
        } catch (ResearchRejected $rejected) {
            self::assertSame('chat_id', $rejected->field);
        }
    }

    public function testListAndDeleteStayOnTheCallersRuns(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);
        $listed = $this->runs->listRecent($this->user);

        self::assertSame($started->ref->id, $listed[0]['id']);
        self::assertSame('standard', $listed[0]['depth']);

        $this->runs->delete($this->user, $started->ref->id);

        $this->expectException(ResearchRunNotFound::class);
        $this->runs->state($this->user, $started->ref->id);
    }

    public function testCancelFinishesARunThatIsNotBeingWorkedOn(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);

        $stopped = $this->runs->cancel($this->user, $started->ref->id);

        self::assertSame(ResearchOutcome::STOPPED_BY_USER, $stopped->outcome?->code);
    }

    public function testAFollowUpKeepsTheParent(): void
    {
        $started = $this->runs->start($this->user, $this->body(), true, null);

        $next = $this->runs->followUp($this->user, $started->ref->id, [
            'type' => 'deeper',
            'text' => 'Look at delivery times too.',
        ]);

        self::assertSame($started->ref->id, $next->parent);
        self::assertSame(ResearchDepth::Standard, $next->ref->depth);
        self::assertSame(11, $next->ref->chatId);
    }

    public function testTooManyRunsAreRefused(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => ResearchConfig::MAX_ACTIVE_RUNS === $setting ? '1' : null,
        );
        $runs = new ResearchRunService($this->store, $this->bus, new ResearchEventLog($this->keys), new ResearchConfig($repo, '1'), $this->chats(), $this->clock);
        $runs->start($this->user, $this->body(), true, null);

        $this->expectException(ResearchRejected::class);
        $runs->start($this->user, ['question' => 'A second question about prices?', 'depth' => 'standard', 'chat_id' => 11], true, 'other-key');
    }

    /**
     * @return array{question: string, depth: string, chat_id: int}
     */
    private function body(): array
    {
        return ['question' => 'Which suppliers deliver this in Germany?', 'depth' => 'standard', 'chat_id' => 11];
    }

    private function chats(): ChatRepository
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('getUserId')->willReturn(7);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturn($chat);

        return $chats;
    }
}
