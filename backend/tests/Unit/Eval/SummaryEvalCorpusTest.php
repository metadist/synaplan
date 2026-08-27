<?php

declare(strict_types=1);

namespace App\Tests\Unit\Eval;

use PHPUnit\Framework\TestCase;

/**
 * The live eval (`app:summary:eval`) is not part of the CI gate, but a broken
 * corpus must still fail CI — otherwise the instrument silently rots.
 */
class SummaryEvalCorpusTest extends TestCase
{
    private const CORPUS_PATH = __DIR__.'/../../Eval/summary_eval_corpus.json';

    /**
     * @return list<mixed>
     */
    private function corpus(): array
    {
        self::assertFileExists(self::CORPUS_PATH);

        $corpus = json_decode((string) file_get_contents(self::CORPUS_PATH), true);
        self::assertIsArray($corpus, 'corpus must be valid JSON');
        self::assertNotEmpty($corpus);

        return array_values($corpus);
    }

    public function testEveryCaseHasTheRequiredShape(): void
    {
        $seenIds = [];
        foreach ($this->corpus() as $case) {
            self::assertIsArray($case);

            $id = $case['id'] ?? null;
            self::assertIsString($id, 'every case needs a string id');
            self::assertNotSame('', $id);
            self::assertArrayNotHasKey($id, $seenIds, "duplicate case id '{$id}'");
            $seenIds[$id] = true;

            self::assertContains($case['mode'] ?? null, ['bootstrap', 'incremental'], "case '{$id}': mode must be bootstrap or incremental");

            $messages = $case['messages'] ?? null;
            self::assertIsArray($messages, "case '{$id}': messages missing");
            self::assertNotEmpty($messages, "case '{$id}': messages empty");
            foreach ($messages as $message) {
                self::assertIsArray($message);
                self::assertContains($message['role'] ?? null, ['user', 'assistant'], "case '{$id}': message role must be user or assistant");
                self::assertIsString($message['text'] ?? null, "case '{$id}': message text missing");
            }

            if ('incremental' === $case['mode']) {
                self::assertIsString($case['previous_summary'] ?? null, "case '{$id}': incremental cases need previous_summary");
                self::assertNotSame('', trim((string) $case['previous_summary']));
            }

            $probes = $case['probes'] ?? null;
            self::assertIsArray($probes, "case '{$id}': probes missing");
            $required = $probes['required'] ?? null;
            self::assertIsArray($required, "case '{$id}': probes.required missing");
            self::assertNotEmpty($required, "case '{$id}': probes.required must not be empty — a case without retention probes measures nothing");
            self::assertIsArray($probes['forbidden'] ?? null, "case '{$id}': probes.forbidden missing (may be empty)");

            if (isset($case['expect_language'])) {
                self::assertIsString($case['expect_language']);
            }
        }
    }

    public function testEveryRegexProbeCompiles(): void
    {
        foreach ($this->corpus() as $case) {
            self::assertIsArray($case);
            $probes = (array) ($case['probes'] ?? []);
            $all = array_merge((array) ($probes['required'] ?? []), (array) ($probes['forbidden'] ?? []));
            foreach ($all as $probe) {
                self::assertIsString($probe);
                if (!str_starts_with($probe, 're:')) {
                    continue;
                }
                $pattern = '/'.str_replace('/', '\/', substr($probe, 3)).'/iu';
                self::assertNotFalse(
                    @preg_match($pattern, ''),
                    sprintf("case '%s': regex probe '%s' does not compile", (string) ($case['id'] ?? '?'), $probe),
                );
            }
        }
    }

    public function testCorpusCoversBothModesAndMultipleLanguages(): void
    {
        $modes = [];
        $languages = [];
        foreach ($this->corpus() as $case) {
            self::assertIsArray($case);
            $modes[(string) ($case['mode'] ?? '')] = true;
            if (isset($case['expect_language'])) {
                $languages[(string) $case['expect_language']] = true;
            }
        }

        self::assertArrayHasKey('bootstrap', $modes);
        self::assertArrayHasKey('incremental', $modes);
        self::assertGreaterThanOrEqual(3, count($languages), 'corpus must cover at least three languages');
    }
}
