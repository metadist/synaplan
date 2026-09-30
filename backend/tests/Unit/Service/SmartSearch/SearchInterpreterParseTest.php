<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\Interpret\InterpretCandidate;
use App\Service\SmartSearch\Interpret\SearchInterpreter;
use PHPUnit\Framework\TestCase;

final class SearchInterpreterParseTest extends TestCase
{
    /**
     * @return list<InterpretCandidate>
     */
    private function candidates(): array
    {
        return [
            new InterpretCandidate('page:/files', 'page', 'Files'),
            new InterpretCandidate('setting:FEATURE_IAM_GROUPS_ENABLED', 'setting', 'Groups', null, 'false'),
            new InterpretCandidate('chat:7', 'chat', 'Plumber invoice'),
            new InterpretCandidate('file:9', 'file', 'invoice.pdf'),
        ];
    }

    public function testKeepsOnlySentIdsInOrderWithoutDuplicates(): void
    {
        $result = SearchInterpreter::parse(
            '{"intent":"find","targetIds":["chat:7","chat:999","chat:7","file:9","page:/files","setting:FEATURE_IAM_GROUPS_ENABLED"],"answer":"Your  chat\nabout the plumber."}',
            $this->candidates(),
        );

        self::assertSame('ok', $result->outcome);
        self::assertSame('find', $result->intent);
        self::assertSame(['chat:7', 'file:9', 'page:/files'], $result->targetIds);
        self::assertSame('Your chat about the plumber.', $result->answer);
    }

    public function testInventedIdsOnlyMeanNoMatch(): void
    {
        $result = SearchInterpreter::parse('{"intent":"navigate","targetIds":["page:/nowhere"],"answer":"Go there."}', $this->candidates());

        self::assertSame('no_match', $result->outcome);
        self::assertNull($result->intent);
        self::assertSame([], $result->targetIds);
    }

    public function testChatHandOffNeedsNoTarget(): void
    {
        $result = SearchInterpreter::parse("```json\n{\"intent\":\"answer\",\"targetIds\":[],\"answer\":\"A chat can help.\"}\n```", $this->candidates());

        self::assertSame('ok', $result->outcome);
        self::assertSame('answer', $result->intent);
        self::assertSame('A chat can help.', $result->answer);
    }

    public function testUnreadableOutputFails(): void
    {
        self::assertSame('failed', SearchInterpreter::parse('I think you want Files.', $this->candidates())->outcome);
        self::assertSame('failed', SearchInterpreter::parse('{"intent":"delete_everything","targetIds":[]}', $this->candidates())->outcome);
    }

    public function testCandidatePayloadIsCleanedAndDeduplicated(): void
    {
        $list = InterpretCandidate::listFromPayload([
            ['id' => 'chat:7', 'kind' => 'chat', 'title' => "  Plumber\n invoice ", 'subtitle' => ''],
            ['id' => 'chat:7', 'kind' => 'chat', 'title' => 'Again'],
        ]);

        self::assertCount(1, $list);
        self::assertSame('Plumber invoice', $list[0]->title);
        self::assertNull($list[0]->subtitle);
        self::assertSame('- id=chat:7 | kind=chat | title=Plumber invoice', $list[0]->toPromptLine());
    }

    public function testCandidatePayloadRejectsUnknownKindsAndOversizedLists(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InterpretCandidate::listFromPayload([['id' => 'x', 'kind' => 'ask', 'title' => 'Ask']]);
    }

    public function testCandidatePayloadRejectsTooMany(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InterpretCandidate::listFromPayload(array_fill(0, InterpretCandidate::MAX_CANDIDATES + 1, ['id' => 'a', 'kind' => 'page', 'title' => 'A']));
    }
}
