<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\AI\Service\AiFacade;
use App\Service\Research\Eval\AnswerFile;
use App\Service\Research\Eval\CitationExtractor;
use App\Service\Research\Eval\CitationPassages;
use App\Service\Research\Eval\CitationReport;
use App\Service\Research\Eval\CitationRunner;
use App\Service\Research\Eval\JudgeClient;
use App\Service\Research\Spike\PageBody;
use App\Service\Research\Spike\PassageSplitter;
use App\Service\Research\Spike\SpikeSources;
use PHPUnit\Framework\TestCase;

final class CitationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/citation-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir.'/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        rmdir($this->dir);
    }

    public function testEvidenceMarkersAfterTheFullStopBelongToTheSentence(): void
    {
        $statements = (new CitationExtractor())->statements(
            "## Answer\n\nThe AI Act is Regulation (EU) 2024/1689. [e1][e4]\nIt has no citation at all here.\n- The office opened in 2024 [e2, e3]."
        );

        $this->assertSame([
            ['statement' => 'The AI Act is Regulation (EU) 2024/1689.', 'refs' => ['e1', 'e4']],
            ['statement' => 'The office opened in 2024.', 'refs' => ['e2', 'e3']],
        ], $statements);
    }

    public function testAnAbbreviationOrOrdinalDoesNotEndTheSentence(): void
    {
        $statements = (new CitationExtractor())->statements(
            'Das Recht folgt aus Art. 17 Abs. 1 Buchst. a DSGVO, z. B. bei Widerruf [e1]. Sie gilt seit dem 25. Mai 2018 [e2]. It opened in 2024. Then it grew [e3].'
        );

        $this->assertSame([
            'Das Recht folgt aus Art. 17 Abs. 1 Buchst. a DSGVO, z. B. bei Widerruf.',
            'Sie gilt seit dem 25. Mai 2018.',
            'Then it grew.',
        ], array_column($statements, 'statement'));
    }

    public function testLinksBareUrlsAndNumberedReferencesBecomeUrls(): void
    {
        $statements = (new CitationExtractor())->statements(implode("\n", [
            'Paris has 2.1 million people ([INSEE](https://insee.fr/pop)).',
            'The river is the Seine, see https://example.org/seine.',
            'The tower is 330 metres tall [1].',
            '',
            '## Sources',
            '1. Tower facts https://example.org/tower',
        ]));

        $this->assertSame(['https://insee.fr/pop'], $statements[0]['refs']);
        $this->assertSame('Paris has 2.1 million people (INSEE).', $statements[0]['statement']);
        $this->assertSame(['https://example.org/seine'], $statements[1]['refs']);
        $this->assertSame(['https://example.org/tower'], $statements[2]['refs']);
        $this->assertCount(3, $statements);
    }

    public function testAnEvidenceIdUsesTheKeptPassageAndAMissingIdIsUnknown(): void
    {
        $passages = new CitationPassages($this->createStub(SpikeSources::class), new PassageSplitter());
        $evidence = ['e1' => ['url' => 'https://a.example', 'passage' => 'The Act is 2024/1689.']];

        $this->assertSame(['status' => 'ok', 'passage' => 'The Act is 2024/1689.'], $passages->forStatement('x', ['e1', 'e9'], $evidence));
        $this->assertSame('unknown_ref', $passages->forStatement('x', ['e9'], $evidence)['status']);
    }

    public function testAUrlIsReadOnceAndTheMatchingPassageIsPicked(): void
    {
        $sources = $this->createMock(SpikeSources::class);
        $sources->expects($this->once())->method('read')->with('https://t.example')
            ->willReturn(new PageBody("The weather was fine.\n\nThe tower is 330 metres tall.\n\nTickets cost money.", []));
        $passages = new CitationPassages($sources, new PassageSplitter());

        $first = $passages->forStatement('The tower is 330 metres tall.', ['https://t.example'], []);
        $passages->forStatement('Tower height', ['https://t.example'], []);

        $this->assertSame(['status' => 'ok', 'passage' => 'The tower is 330 metres tall.'], $first);
    }

    public function testABlockedPageIsUnreadable(): void
    {
        $sources = $this->createStub(SpikeSources::class);
        $sources->method('read')->willReturn(new PageBody('', [], 'robots'));

        $found = (new CitationPassages($sources, new PassageSplitter()))->forStatement('x y z', ['https://t.example'], []);

        $this->assertSame('unreadable', $found['status']);
    }

    public function testTheCitationPromptNamesStatementAndPassageOnly(): void
    {
        $ai = $this->createMock(AiFacade::class);
        $ai->expects($this->once())->method('chat')->with(
            $this->logicalAnd($this->stringContains('The statement.'), $this->stringContains('The passage.'), $this->logicalNot($this->stringContains('tools'))),
            4,
            ['provider' => 'xai', 'model' => 'grok-4.7'],
        )->willReturn(['content' => 'Supports.', 'usage' => ['total_tokens' => 42]]);

        $verdict = (new JudgeClient($ai, dirname(__DIR__, 5)))->judgeCitation('The statement.', 'The passage.', 'xai:grok-4.7', 4);

        $this->assertSame(['judge' => 'xai:grok-4.7', 'verdict' => 'supports', 'tokens' => 42], $verdict);
    }

    public function testASplitGoesToTheThirdJudgeAndTheTableCountsIt(): void
    {
        (new AnswerFile())->write($this->dir.'/tools.json', [[
            'id' => 'fact-01',
            'arm' => 'tools',
            'run' => 1,
            'text' => "The Act is 2024/1689 [e1].\nThe office opened in 2025 [e2].\nIt applies to all firms [e7].",
            'evidence' => [
                'e1' => ['url' => 'https://a.example', 'passage' => 'Regulation (EU) 2024/1689'],
                'e2' => ['url' => 'https://a.example', 'passage' => 'The office opened in 2024'],
            ],
        ]]);
        $judges = $this->createStub(JudgeClient::class);
        $judges->method('judgeCitation')->willReturnCallback(static function (string $statement, string $quote, string $key): array {
            $verdict = match (true) {
                str_contains($statement, '2024/1689') => 'supports',
                'c:third' === $key => 'does_not_support',
                'a:one' === $key => 'supports',
                default => 'does_not_support',
            };

            return ['judge' => $key, 'verdict' => $verdict, 'tokens' => 10];
        });
        $runner = new CitationRunner(new AnswerFile(), new CitationExtractor(), new CitationPassages($this->createStub(SpikeSources::class), new PassageSplitter()), $judges, new CitationReport());

        $report = $runner->run([$this->dir.'/tools.json'], ['a:one', 'b:two', 'c:third'], 4, 1, 40, null, $this->dir.'/out');

        $this->assertStringContainsString('| tools | 1 | 3 | 2 | 1 | 1 | 1 | 0 | 0 | 0 | 33.3 % | 50.0 % |', $report);
        $this->assertStringContainsString('(unknown evidence id)', $report);
        $sample = json_decode((string) file_get_contents($this->dir.'/out/sample.json'), true);
        $this->assertCount(2, $sample);
        $this->assertArrayNotHasKey('arm', $sample[0]);
    }

    public function testFewerThanTwoJudgesSendNothing(): void
    {
        $runner = new CitationRunner(new AnswerFile(), new CitationExtractor(), new CitationPassages($this->createStub(SpikeSources::class), new PassageSplitter()), $this->createStub(JudgeClient::class), new CitationReport());

        $this->expectExceptionMessage('Pass two judges');
        $runner->run([], ['a:one'], 4, 1, 40, null, $this->dir.'/out');
    }
}
