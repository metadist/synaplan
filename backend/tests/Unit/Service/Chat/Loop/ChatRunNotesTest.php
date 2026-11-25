<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatLoopPrompt;
use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunEvents;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunNotes;
use App\Service\Chat\Loop\ChatRunReply;
use App\Service\Chat\Loop\ChatRunWrapUp;
use App\Service\Chat\Loop\TranscriptCompactor;

/**
 * Notes and compaction of a long run (03 §1.5): the older part of the
 * transcript becomes notes, a fact survives two compactions, the IDs the run
 * saw stay usable, notes never hold image data, and a failed compaction ends
 * in a wrap-up, not in an error.
 */
final class ChatRunNotesTest extends ChatLoopCase
{
    /** A window of 4,000 tokens: 70 % is reached at about 11,200 characters, some ten working steps. */
    private const WINDOW = 4_000;

    private const FACT = 'Message 10 says the hob costs 749 euros.';

    public function testAFactFromMessageTenIsStillThereAfterTwoCompactions(): void
    {
        $this->tools->echo = true;
        $model = $this->compacting(new CompactingChatRunModel(
            new ScriptedChatRunModel(...[...$this->working('a', 24), ScriptedChatRunModel::says('The hob costs 749 euros.')]),
            [
                $this->notes(['goal' => 'Find the price of the hob.', 'facts' => [['text' => self::FACT, 'ref' => 'message 10']]]),
                $this->notes(['goal' => 'Find the price of the hob.', 'facts' => [['text' => self::FACT, 'ref' => 'message 10'], ['text' => 'The oven is 1,200 euros.']]]),
            ],
        ));
        $state = $this->open();

        $this->drain(80);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertGreaterThanOrEqual(2, $stored->compactions);
        self::assertCount($stored->compactions, $model->compactions);
        self::assertSame($stored->compactions, \count(array_keys($this->eventNames($state->runId), ChatRunEvents::NOTES, true)));
        // The second compaction was given the first notes; the last call sees the fact in its system prompt.
        self::assertStringContainsString(self::FACT, $model->compactions[1]['messages'][1]['content']);
        self::assertSame(TranscriptCompactor::MAX_TOKENS, $model->compactions[0]['maxTokens']);
        $calls = $model->work->calls;
        self::assertStringContainsString(self::FACT, $calls[\count($calls) - 1]['messages'][0]['content']);
        // The person's question is kept word for word, the older steps are gone.
        self::assertSame(['role' => 'user', 'content' => 'What changed in the release?'], $stored->messages[0]);
        self::assertLessThan(15, \count(array_filter($stored->messages, static fn (array $m): bool => 'assistant' === $m['role'])));
        self::assertNotNull($stored->notes);
        self::assertSame(self::FACT, $stored->notes->facts[0]['text']);
    }

    public function testFoldedIdsStayInTheNotesAndPicturesStayCounted(): void
    {
        $this->tools->echo = true;
        $this->tools->results['read_document'] = "File: plan.pdf\nfile_id: 4711\nCharacters 0–10 of 10.\n\nkitchen";
        $this->tools->results['conversation_search'] = "message 812 · the person · 2026-11-20 10:00\nThe budget is 5,000.";
        $this->analyzed = [4711];
        $model = $this->compacting(new CompactingChatRunModel(
            new ScriptedChatRunModel(...[
                new ChatRunReply(str_repeat('Reading. ', 100), [['id' => 'doc', 'name' => 'read_document', 'args' => ['file_id' => 4711]]], 1_000),
                new ChatRunReply(str_repeat('Reading. ', 100), [['id' => 'msg', 'name' => 'conversation_search', 'args' => ['query' => 'budget']]], 1_000),
                ...$this->working('a', 12),
                ScriptedChatRunModel::says('Done.'),
            ]),
            // The model forgot both IDs.
            [$this->notes(['goal' => 'Plan the kitchen.'])],
        ));
        $state = $this->open();

        $this->drain(40);

        $stored = $this->stored($state->runId);
        self::assertGreaterThanOrEqual(1, $stored->compactions);
        self::assertNotNull($stored->notes);
        self::assertSame([['id' => 4711, 'name' => '', 'what' => '']], $stored->notes->files);
        self::assertSame([812], $stored->notes->messages);
        self::assertSame([4711], $stored->analyzed, 'a folded picture still counts against the limit');
        $calls = $model->work->calls;
        $system = $calls[\count($calls) - 1]['messages'][0]['content'];
        self::assertStringContainsString('file_id 4711', $system);
        self::assertStringContainsString('812', $system);
        self::assertStringContainsString(ChatLoopPrompt::NOTES_HEAD, $system);
    }

    public function testNotesNeverHoldImageData(): void
    {
        $notes = ChatRunNotes::read([
            'goal' => 'Edit the logo.',
            'files' => [['id' => '9', 'name' => 'logo.png', 'what' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB a blue logo']],
            'facts' => [['text' => "see data:image/jpeg;base64,/9j/4AAQSkZJRg==\x00 here"]],
        ]);

        self::assertNotNull($notes);
        self::assertStringNotContainsString('base64', $notes->json());
        self::assertSame(9, $notes->files[0]['id']);
        self::assertStringContainsString('[image data removed]', $notes->files[0]['what']);
    }

    public function testNotesAreCappedAndAnAnswerWithoutAGoalIsNoNotes(): void
    {
        $plan = array_fill(0, 12, ['text' => 'step', 'state' => 'weird']);
        $notes = ChatRunNotes::read(['goal' => str_repeat('g', 2_000), 'plan' => $plan, 'messages' => [3, '4', -1, 'x', 3]]);

        self::assertNotNull($notes);
        self::assertCount(ChatRunNotes::MAX_PLAN, $notes->plan);
        self::assertSame(ChatRunNotes::PLAN_OPEN, $notes->plan[0]['state']);
        self::assertLessThanOrEqual(600, mb_strlen($notes->goal));
        self::assertSame([3, 4], $notes->messages);
        self::assertNull(ChatRunNotes::read(['plan' => []]));
        self::assertNull(ChatRunNotes::read('notes'));
    }

    public function testAFailedCompactionEndsInAWrapUp(): void
    {
        $this->tools->echo = true;
        $model = $this->compacting(new CompactingChatRunModel(
            new ScriptedChatRunModel(...$this->working('a', 16)),
            [new ChatRunModelFailed('down')],
        ));
        $state = $this->open();

        $this->drain(40);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::ContextFull, $stored->end);
        self::assertStringStartsWith(ChatRunWrapUp::opening(ChatRunEnd::ContextFull, 'de')."\n\nReading more.", (string) $stored->answer);
        self::assertContains(ChatRunEvents::WRAP_UP, $this->eventNames($state->runId));
        self::assertSame(0, $stored->compactions);
        $calls = $model->work->calls;
        self::assertSame([], $calls[\count($calls) - 1]['tools']);
        self::assertSame(ChatRunWrapUp::instruction(ChatRunEnd::ContextFull), $calls[\count($calls) - 1]['messages'][\count($calls[\count($calls) - 1]['messages']) - 1]['content']);
    }

    public function testNotesThatAreNotJsonOrHaveNoGoalFailTheCompaction(): void
    {
        foreach (['Here are the notes: the hob is 749.', '{"goal": "x"', '{"plan": []}'] as $answer) {
            $this->setUp();
            $this->tools->echo = true;
            $this->compacting(new CompactingChatRunModel(
                new ScriptedChatRunModel(...$this->working('a', 16)),
                [new ChatRunReply($answer, [], 1_000)],
            ));
            $state = $this->open();

            $this->drain(40);

            self::assertSame(ChatRunEnd::ContextFull, $this->stored($state->runId)->end, $answer);
        }
    }

    public function testNotesInAFenceOrAfterASentenceAreRead(): void
    {
        foreach (["```json\n{\"goal\": \"Find the price.\"}\n```", 'Here are the notes: {"goal": "Find the price."}'] as $answer) {
            $this->setUp();
            $this->tools->echo = true;
            $this->compacting(new CompactingChatRunModel(
                new ScriptedChatRunModel(...[...$this->working('a', 14), ScriptedChatRunModel::says('Done.')]),
                [new ChatRunReply($answer, [], 1_000)],
            ));
            $state = $this->open();

            $this->drain(40);

            $stored = $this->stored($state->runId);
            self::assertSame(ChatRunEnd::Answered, $stored->end, $answer);
            self::assertSame('Find the price.', $stored->notes?->goal);
        }
    }

    public function testNotesOverTheBudgetLoseTheirOldestEntriesInsteadOfEndingTheRun(): void
    {
        $facts = [];
        for ($i = 1; $i <= ChatRunNotes::MAX_FACTS; ++$i) {
            $facts[] = ['text' => 'Fact '.$i.' '.str_repeat('x', 300)];
        }
        $this->tools->echo = true;
        $this->compacting(new CompactingChatRunModel(
            new ScriptedChatRunModel(...[...$this->working('a', 14), ScriptedChatRunModel::says('Done.')]),
            [$this->notes(['goal' => 'Find the price.', 'facts' => $facts])],
        ));
        $state = $this->open();

        $this->drain(40);

        $stored = $this->stored($state->runId);
        self::assertSame(ChatRunEnd::Answered, $stored->end);
        self::assertNotNull($stored->notes);
        self::assertLessThanOrEqual(TranscriptCompactor::NOTES_TOKENS * 4, mb_strlen($stored->notes->render()));
        self::assertStringStartsWith('Fact '.ChatRunNotes::MAX_FACTS.' ', $stored->notes->facts[\count($stored->notes->facts) - 1]['text']);
        self::assertStringNotContainsString('Fact 1 ', $stored->notes->render());
    }

    public function testIdsOfEarlierNotesSurviveWhenTheModelDropsThem(): void
    {
        $first = new ChatRunNotes('Plan the kitchen.', files: [['id' => 4711, 'name' => 'plan.pdf', 'what' => 'the floor plan']], messages: [812]);
        $second = ChatRunNotes::read(['goal' => 'Plan the kitchen.', 'files' => [['id' => 9, 'name' => 'oven.png', 'what' => '']], 'messages' => [900]]);

        self::assertNotNull($second);
        $carried = $second->carry($first);

        self::assertSame([4711, 9], array_column($carried->files, 'id'));
        self::assertSame('the floor plan', $carried->files[0]['what']);
        self::assertSame([812, 900], $carried->messages);
        self::assertSame($second, $second->carry(null));
    }

    public function testALongChatHistoryDoesNotFoldOnEveryStep(): void
    {
        $this->tools->echo = true;
        // Short steps: what lies between the history and the kept steps never reaches a tenth of the window.
        $short = [];
        for ($i = 0; $i < 6; ++$i) {
            $short[] = new ChatRunReply('Looking.', [['id' => 'call_s'.$i, 'name' => 'web_search', 'args' => ['query' => 's'.$i]]], 1_000);
        }
        $model = $this->compacting(new CompactingChatRunModel(
            new ScriptedChatRunModel(...[...$short, ScriptedChatRunModel::says('Done.')]),
            [$this->notes(['goal' => 'x'])],
        ));
        $this->history = [['role' => 'user', 'content' => str_repeat('Earlier talk. ', 900)], ['role' => 'assistant', 'content' => 'Ok.']];
        $state = $this->open();

        $this->drain(40);

        self::assertSame(ChatRunEnd::Answered, $this->stored($state->runId)->end);
        self::assertSame([], $model->compactions);
    }

    public function testTheCompactionIsPaidByTheRun(): void
    {
        $this->tools->echo = true;
        $model = $this->compacting(new CompactingChatRunModel(
            new ScriptedChatRunModel(...[...$this->working('a', 14), ScriptedChatRunModel::says('Done.')]),
            [$this->notes(['goal' => 'x'], 5_000)],
        ));
        $state = $this->open();

        $this->drain(40);

        $stored = $this->stored($state->runId);
        // Every working call and the answer at 1,000 each, and each compaction at 5,000.
        self::assertSame(\count($model->work->calls) * 1_000 + \count($model->compactions) * 5_000, $stored->costMicro);
        self::assertGreaterThanOrEqual(1, \count($model->compactions));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->window = self::WINDOW;
    }

    /**
     * Working steps whose results fill the window.
     *
     * @return list<ChatRunReply>
     */
    private function working(string $prefix, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = new ChatRunReply(str_repeat('Reading more. ', 60), [['id' => 'call_'.$prefix.$i, 'name' => 'web_search', 'args' => ['query' => $prefix.$i]]], 1_000);
        }

        return $out;
    }

    /** @param array<string, mixed> $notes */
    private function notes(array $notes, int $cost = 1_000): ChatRunReply
    {
        return new ChatRunReply(json_encode($notes, \JSON_THROW_ON_ERROR), [], $cost);
    }
}
