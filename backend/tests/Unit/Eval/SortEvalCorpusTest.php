<?php

declare(strict_types=1);

namespace App\Tests\Unit\Eval;

use App\Service\Message\Capability\SystemCapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * CI guard for `app:sort-eval`'s golden corpus: the live eval itself needs a
 * running AI provider, but a malformed corpus (or one that drifted from the
 * capabilities it is meant to exercise) must fail fast in CI.
 *
 * The `expect.topic` values are asserted against
 * {@see SystemCapabilityRegistry::topics()} — Phase 7's "eval-corpus
 * categories derived from the register" link. The corpus intentionally only
 * exercises the four SYSTEM topics; user-authored custom topics have no
 * fixed corpus (they are DB-driven per account, see
 * {@see \App\Repository\PromptRepository::getAllTopics()}).
 */
final class SortEvalCorpusTest extends TestCase
{
    private const CORPUS_PATH = __DIR__.'/../../Eval/sort_eval_corpus.json';

    /**
     * @return list<array<string, mixed>>
     */
    private function corpus(): array
    {
        $raw = file_get_contents(self::CORPUS_PATH);
        self::assertNotFalse($raw, 'corpus file must exist');

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'corpus must be valid JSON');
        self::assertNotEmpty($decoded);

        return array_values($decoded);
    }

    public function testEveryCaseHasIdTextAndExpectedTopic(): void
    {
        $ids = [];
        foreach ($this->corpus() as $case) {
            self::assertIsString($case['id'] ?? null);
            self::assertNotSame('', $case['id']);
            $ids[] = $case['id'];

            self::assertIsString($case['text'] ?? null);
            self::assertNotSame('', trim((string) $case['text']));

            self::assertIsArray($case['expect'] ?? null);
            self::assertIsString($case['expect']['topic'] ?? null);
        }

        self::assertSame($ids, array_unique($ids), 'case ids must be unique');
    }

    /**
     * The corpus must only exercise capabilities the registry actually
     * declares — a case naming a topic that has since been removed (or
     * misspelled) from {@see SystemCapabilityRegistry} would silently test
     * nothing meaningful.
     */
    public function testEveryExpectedTopicIsARegisteredSystemCapability(): void
    {
        $registeredTopics = (new SystemCapabilityRegistry())->topics();

        foreach ($this->corpus() as $case) {
            $topic = (string) $case['expect']['topic'];
            self::assertContains(
                $topic,
                $registeredTopics,
                sprintf(
                    "corpus case '%s' expects topic '%s', which is not declared in SystemCapabilityRegistry",
                    (string) $case['id'],
                    $topic,
                ),
            );
        }
    }

    /**
     * The inverse check: every SYSTEM capability the registry declares
     * should have at least one golden-corpus case, so a newly added
     * capability does not silently ship without eval coverage.
     */
    public function testEveryRegisteredSystemCapabilityHasAtLeastOneCorpusCase(): void
    {
        $corpusTopics = array_map(
            static fn (array $case): string => (string) $case['expect']['topic'],
            $this->corpus(),
        );

        foreach ((new SystemCapabilityRegistry())->topics() as $topic) {
            self::assertContains($topic, $corpusTopics, "capability '{$topic}' has no golden-corpus case in sort_eval_corpus.json");
        }
    }
}
