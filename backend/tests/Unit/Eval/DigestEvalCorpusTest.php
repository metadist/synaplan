<?php

declare(strict_types=1);

namespace App\Tests\Unit\Eval;

use PHPUnit\Framework\TestCase;

/**
 * CI guard for the digest retrieval eval corpus: the live eval itself needs
 * an embedding model, but a malformed corpus must fail fast in CI.
 */
final class DigestEvalCorpusTest extends TestCase
{
    private const CORPUS_PATH = __DIR__.'/../../Eval/digest_eval_corpus.json';

    /**
     * @return list<mixed>
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

    public function testEveryCaseHasIdDigestsAndQueries(): void
    {
        $ids = [];
        foreach ($this->corpus() as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['id'] ?? null);
            self::assertNotSame('', $case['id']);
            $ids[] = $case['id'];

            self::assertIsArray($case['digests'] ?? null);
            self::assertNotEmpty($case['digests']);
            self::assertIsArray($case['queries'] ?? null);
            self::assertNotEmpty($case['queries']);
        }

        self::assertSame($ids, array_unique($ids), 'case ids must be unique');
    }

    public function testEveryDigestHasKeyTitleAndAge(): void
    {
        foreach ($this->corpus() as $case) {
            self::assertIsArray($case);
            $keys = [];
            foreach ((array) $case['digests'] as $digest) {
                self::assertIsArray($digest);
                self::assertIsString($digest['key'] ?? null);
                self::assertNotSame('', $digest['key']);
                $keys[] = $digest['key'];

                self::assertIsString($digest['title'] ?? null);
                self::assertNotSame('', trim((string) $digest['title']));
                self::assertLessThanOrEqual(200, mb_strlen((string) $digest['title']), 'digest titles are capped at 200 chars in production');

                self::assertIsInt($digest['days_ago'] ?? null);
                self::assertGreaterThanOrEqual(0, $digest['days_ago']);
            }

            self::assertSame($keys, array_unique($keys), 'digest keys must be unique within a case');
        }
    }

    public function testEveryQueryExpectsAnExistingDigestKey(): void
    {
        foreach ($this->corpus() as $case) {
            self::assertIsArray($case);
            $keys = array_map(
                static fn (array $digest): string => (string) $digest['key'],
                (array) $case['digests'],
            );

            foreach ((array) $case['queries'] as $query) {
                self::assertIsArray($query);
                self::assertIsString($query['query'] ?? null);
                self::assertNotSame('', trim((string) $query['query']));
                self::assertIsString($query['expect_top1'] ?? null);
                self::assertContains($query['expect_top1'], $keys, sprintf(
                    'query "%s" expects unknown digest key "%s"',
                    (string) $query['query'],
                    (string) $query['expect_top1'],
                ));
            }
        }
    }

    public function testTheOfficeRentAcceptanceCaseIsPresent(): void
    {
        $ids = array_map(
            static fn (array $case): string => (string) ($case['id'] ?? ''),
            array_filter($this->corpus(), is_array(...)),
        );

        self::assertContains('office_rent_letter', $ids, 'the acceptance use case must stay corpus case #1');
    }
}
