<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Run;
use App\Repository\ConfigRepository;
use App\Repository\RunRepository;
use App\Service\Chat\Loop\ChatLoopConfig;
use App\Service\Chat\Loop\ChatRunApprovals;
use App\Service\Chat\Loop\ChatRunDelivery;
use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunReader;
use App\Service\Chat\Loop\ChatRunRecords;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Run\RunNotFound;
use App\Tests\Unit\Service\Run\MemoryRunKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ChatRunPiecesTest extends TestCase
{
    private const RUN_ID = '01JD6Q3Z8Y9X7W6V5T4S3R2Q1P';

    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function switches(): iterable
    {
        yield 'no row, no env' => ['', null, false];
        yield 'row on' => ['', '1', true];
        yield 'row off' => ['', '0', false];
        yield 'env pins on over the row' => ['1', '0', true];
        yield 'env pins off over the row' => ['0', '1', false];
        yield 'env garbage follows the row' => ['maybe', '1', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('switches')]
    public function testTheLoopIsOffUnlessSwitchedOn(string $env, ?string $row, bool $on): void
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn($row);

        self::assertSame($on, (new ChatLoopConfig($config, $env))->isEnabled());
    }

    public function testOnlyAChatRunPrefixWithAValidIdNamesARun(): void
    {
        self::assertSame(self::RUN_ID, ChatRunApprovals::runOf(ChatRunApprovals::requestedBy(self::RUN_ID)));
        self::assertNull(ChatRunApprovals::runOf('chat:12'));
        self::assertNull(ChatRunApprovals::runOf('chat_run:12'));
        self::assertNull(ChatRunApprovals::runOf('chat_run:'.self::RUN_ID.':x'));
    }

    public function testEveryEndWithoutAnAnswerHasASentenceInEveryLocale(): void
    {
        foreach (ChatRunEnd::cases() as $end) {
            if (\in_array($end, [ChatRunEnd::Answered, ChatRunEnd::Finished], true)) {
                self::assertSame('', $end->sentence('en'), 'An answered run shows its answer, no sentence.');
                continue;
            }
            foreach (['en', 'de', 'es', 'fr', 'tr'] as $locale) {
                self::assertNotSame('', $end->sentence($locale), $end->value.' '.$locale);
            }
        }
        self::assertSame(ChatRunEnd::BudgetUsed->sentence('en'), ChatRunEnd::BudgetUsed->sentence('xx'));
    }

    public function testTheDeliveredTextIsTheAnswerOrTheSentenceForWhyTheRunEnded(): void
    {
        $answered = new ChatRunState(self::RUN_ID, 7, 11, null, null, null, 'de', '', [], 1);
        $answered->finish(ChatRunEnd::Answered, 'Die Antwort.');
        $stopped = new ChatRunState(self::RUN_ID, 7, 11, null, null, null, 'de', '', [], 1);
        $stopped->finish(ChatRunEnd::BudgetUsed);

        self::assertSame('Die Antwort.', ChatRunDelivery::text($answered));
        self::assertSame(ChatRunEnd::BudgetUsed->sentence('de'), ChatRunDelivery::text($stopped));
    }

    public function testTheEventsOfARunAreReadOnlyByWhoStartedIt(): void
    {
        $clock = new MockClock('2026-11-20 09:00:00');
        $run = new Run(self::RUN_ID, Run::KIND_CHAT, 7, ChatRunRecords::ORIGIN, 'job', 1);
        $run->setStartedBy(7);
        $runs = $this->createStub(RunRepository::class);
        $runs->method('findOneByUuid')->willReturnCallback(static fn (string $id): ?Run => self::RUN_ID === $id ? $run : null);
        $reader = new ChatRunReader(new ChatRunRecords($runs, $clock), new ChatRunEvents(new MemoryRunKeys($clock)));

        $reader->assertCaller(self::RUN_ID, 7);
        foreach ([[self::RUN_ID, 8], [self::RUN_ID, 0], ['01JD6Q3Z8Y9X7W6V5T4S3R2Q1Q', 7], ['../run', 7]] as [$id, $caller]) {
            try {
                $reader->events($id, $caller);
                self::fail('Another caller must not read the run.');
            } catch (RunNotFound) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAResearchRowIsNotAChatRun(): void
    {
        $run = new Run(self::RUN_ID, Run::KIND_RESEARCH, 7, 'web_chat', 'job', 1);
        $runs = $this->createStub(RunRepository::class);
        $runs->method('findOneByUuid')->willReturn($run);

        self::assertNull((new ChatRunRecords($runs, new MockClock()))->forCaller(self::RUN_ID, 7));
    }
}
