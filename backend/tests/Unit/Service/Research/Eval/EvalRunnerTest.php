<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\Service\Research\Eval\B0Adapter;
use App\Service\Research\Eval\B1Importer;
use App\Service\Research\Eval\B1PlusAdapter;
use App\Service\Research\Eval\B4Adapter;
use App\Service\Research\Eval\CorpusLoader;
use App\Service\Research\Eval\EvalFiles;
use App\Service\Research\Eval\EvalLiveTarget;
use App\Service\Research\Eval\EvalReportWriter;
use App\Service\Research\Eval\EvalRequest;
use App\Service\Research\Eval\EvalRunner;
use App\Service\Research\Eval\JudgeClient;
use App\Service\Research\Eval\Scorer;
use App\Service\Research\Eval\SyntheticDocumentGenerator;
use PHPUnit\Framework\TestCase;

final class EvalRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/research-runner-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/corpus.json', json_encode([[
            'id' => 'fact-01',
            'category' => 'current',
            'language' => 'en',
            'question' => 'Which regulation?',
            'depth' => 'standard',
            'sources' => ['web' => true, 'knowledge' => false],
            'gold' => [['id' => 'g1', 'assertion' => 'It is 2024/1689.', 'match' => ['2024/1689']]],
            'smoke' => true,
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($this->dir.'/import.json', json_encode([
            'items' => [['id' => 'fact-01', 'text' => 'Regulation 2024/1689 | in force.']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAnUnknownArmIsRefusedFirst(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pass --arm with b0, b1, b1plus or b4.');

        $this->runner()->run($this->request(''));
    }

    public function testFrozenIsRefusedForAnImportedArm(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--frozen replays the searches of a live b0 run only.');

        $this->runner()->run($this->request('b1', import: true, frozen: true));
    }

    public function testJudgesNeedTheEvaluationAccount(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Judges run on the evaluation account. Pass --user.');

        $this->runner()->run($this->request('b1', import: true, judges: ['openai:judge']));
    }

    public function testAFailedJudgeIsANoteAndTheReportFileMatchesTheOutput(): void
    {
        $judges = $this->createStub(JudgeClient::class);
        $judges->method('judgeFact')->willThrowException(new \RuntimeException('provider down'));

        $text = $this->runner($judges)->run($this->request('b1', import: true, judges: ['openai:judge'], userId: 3, report: true));

        $this->assertStringContainsString('| fact-01 | 1.00 | no |', $text);
        $this->assertStringContainsString('fact-01 g1: the judge could not answer (provider down).', $text);
        $this->assertSame($text, file_get_contents($this->dir.'/report.md'));
    }

    private function runner(?JudgeClient $judges = null): EvalRunner
    {
        return new EvalRunner(
            new CorpusLoader(),
            new Scorer(),
            $this->createStub(B0Adapter::class),
            new B1Importer(),
            $this->createStub(B1PlusAdapter::class),
            $this->createStub(B4Adapter::class),
            $judges ?? $this->createStub(JudgeClient::class),
            new EvalReportWriter(),
            new SyntheticDocumentGenerator(),
        );
    }

    /**
     * @param list<string> $judges
     */
    private function request(string $arm, bool $import = false, bool $frozen = false, array $judges = [], int $userId = 0, bool $report = false): EvalRequest
    {
        return new EvalRequest(
            arm: $arm,
            corpusPath: $this->dir.'/corpus.json',
            smokeOnly: false,
            userId: $userId,
            repeat: 1,
            live: new EvalLiveTarget(null, null, null, null, null, $judges),
            files: new EvalFiles(
                $import ? $this->dir.'/import.json' : null,
                $frozen ? $this->dir.'/frozen' : null,
                $report ? $this->dir.'/report.md' : null,
                null,
            ),
            commandLine: 'app:research:eval --arm='.$arm,
        );
    }
}
