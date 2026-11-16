<?php

declare(strict_types=1);

namespace App\Tests\Unit\Eval\ChatLoop;

use App\Tests\Eval\ChatLoop\ChatLoopCorpus;
use App\Tests\Eval\ChatLoop\CorpusTurn;
use App\Tests\Eval\ChatLoop\ScriptedChats;
use PHPUnit\Framework\TestCase;

/**
 * The live run is not part of the gate, but a broken corpus must fail CI:
 * gates G1–G7 of roadmap row 46 are measured on it.
 */
final class ChatLoopCorpusTest extends TestCase
{
    private const MIN_TURNS_PER_GROUP = 6;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
    }

    public function testTheCorpusHasFortyTurnsInFiveGroups(): void
    {
        $turns = (new ChatLoopCorpus())->load();
        self::assertCount(40, $turns);

        $perGroup = array_count_values(array_map(static fn (CorpusTurn $turn): string => $turn->group, $turns));
        self::assertSame(ChatLoopCorpus::GROUPS, array_keys($perGroup));
        foreach ($perGroup as $group => $count) {
            self::assertGreaterThanOrEqual(self::MIN_TURNS_PER_GROUP, $count, $group);
        }
    }

    public function testEveryLocaleOfTheProductIsAsked(): void
    {
        $languages = array_unique(array_map(static fn (CorpusTurn $turn): string => $turn->language, (new ChatLoopCorpus())->load()));
        sort($languages);

        self::assertSame(['de', 'en', 'es', 'fr', 'tr'], $languages);
    }

    public function testTurnsThatNeedFilesAttachFixturesAndSayWhatTheAnswerHolds(): void
    {
        foreach ((new ChatLoopCorpus())->load() as $turn) {
            if ('files' !== $turn->group) {
                continue;
            }
            self::assertNotSame([], $turn->files, $turn->id);
            self::assertNotSame([], $turn->expect, $turn->id);
        }
    }

    public function testResearchTurnsTakeQuestionAndGoldFactsFromTheResearchCorpus(): void
    {
        $research = array_values(array_filter((new ChatLoopCorpus())->load(), static fn (CorpusTurn $turn): bool => 'research' === $turn->group));
        $hard = json_decode((string) file_get_contents(__DIR__.'/../../../Eval/research/corpus-hard.json'), true);
        self::assertIsArray($hard);
        $questions = array_column($hard, 'question');

        self::assertNotSame([], $research);
        foreach ($research as $turn) {
            self::assertContains($turn->text, $questions, $turn->id);
            self::assertNotSame([], $turn->expect, $turn->id);
        }
    }

    public function testATurnWithAnUnknownGroupIsRefused(): void
    {
        $path = $this->temporaryCorpus(['turns' => [['id' => 'x-01', 'group' => 'chatty', 'language' => 'en', 'text' => 'Hi']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown group "chatty"');
        (new ChatLoopCorpus())->load($path);
    }

    public function testADuplicateIdIsRefused(): void
    {
        $turn = ['id' => 'x-01', 'group' => 'simple', 'language' => 'en', 'text' => 'Hi'];
        $path = $this->temporaryCorpus(['turns' => [$turn, $turn]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('used twice');
        (new ChatLoopCorpus())->load($path);
    }

    public function testAFixtureOutsideTheFixtureDirectoryIsRefused(): void
    {
        $path = $this->temporaryCorpus(['turns' => [['id' => 'x-01', 'group' => 'files', 'language' => 'en', 'text' => 'Read it', 'files' => ['../corpus.json']]]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        (new ChatLoopCorpus())->load($path);
    }

    public function testTheLongChatHas150MessagesWithThePictureAndTheFactWhereThePlanPutsThem(): void
    {
        $turns = ScriptedChats::conversation(ScriptedChats::DIR.'/long-chat-150.json');

        self::assertCount(152, $turns);
        self::assertStringContainsString('picture', $turns[4]->text);
        self::assertStringContainsString('78 cm', $turns[9]->text);
        self::assertSame('probe-picture', $turns[150]->id);
        self::assertSame([['78']], $turns[151]->expect);
    }

    public function testTheTwoStubsDescribeACircleThatMustEndInAWrapUp(): void
    {
        $same = ScriptedChats::toolStub(ScriptedChats::DIR.'/stub-search-same.json');
        $fails = ScriptedChats::toolStub(ScriptedChats::DIR.'/stub-tool-fails.json');

        self::assertSame(['web_search', 'same_result', 'circling', 1], [$same['tool'], $same['behaviour'], $same['wrapup'], $same['nudges']]);
        self::assertSame(['web_fetch', 'always_fails', 'circling', 1], [$fails['tool'], $fails['behaviour'], $fails['wrapup'], $fails['nudges']]);
    }

    /**
     * @param array<string, mixed> $corpus
     */
    private function temporaryCorpus(array $corpus): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-loop-corpus');
        file_put_contents($path, json_encode($corpus, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
