<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\SizeOffenders;
use App\Tests\Architecture\Support\SourceMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Code cleanup V2 (G1) — the size ratchet.
 *
 * Three limits for everything under src/: a file has at most 800 lines, a
 * constructor at most 10 parameters, a method body at most 100 lines.
 * Every offender that existed when the gate was introduced is frozen at its
 * value in sizeRatchetBaseline.json. A listed entry may shrink or
 * disappear, never grow; an unlisted offender fails. So the numbers can only
 * go down, and "not finished yet" is safe.
 *
 * Shrunk a class below the limit, or split it? Delete its ledger entry in
 * the same PR (the stale-entry test tells you which). Regenerate the ledger
 * with UPDATE_SIZE_RATCHET=1 and review the diff: removals and smaller
 * numbers only.
 *
 * `make -C backend metrics` prints the same scan, with the largest offenders.
 *
 * Plan: _devextras/planning/20261014-code-cleanup-V2/ (master plan §0 #1, §1).
 */
final class SizeRatchetTest extends TestCase
{
    public const MAX_FILE_LINES = SizeOffenders::MAX_FILE_LINES;
    public const MAX_CONSTRUCTOR_PARAMS = SizeOffenders::MAX_CONSTRUCTOR_PARAMS;
    public const MAX_METHOD_LINES = SizeOffenders::MAX_METHOD_LINES;

    private const LEDGER = __DIR__.'/sizeRatchetBaseline.json';

    /** Guard against a vacuous pass if the scan breaks (1,640 files on 2026-10-14). */
    private const MIN_EXPECTED_FILES = 1000;

    public function testEveryOffenderIsOnTheLedger(): void
    {
        $measured = $this->measure();
        $ledger = $this->ledger();

        $new = [];
        foreach (['fileLines', 'constructorParams', 'methodLines'] as $kind) {
            foreach ($measured[$kind] as $key => $value) {
                if (!array_key_exists($key, $ledger[$kind])) {
                    $new[] = sprintf('%s  %s = %d', $kind, $key, $value);
                }
            }
        }

        $this->assertSame([], $new, sprintf(
            "New size offender(s) — the limits are %d lines per file, %d constructor parameters, %d lines per method:\n  %s\n\nSplit it, or move the new code into a class of its own. The ledger only freezes what existed before the gate; do not add to it.",
            self::MAX_FILE_LINES,
            self::MAX_CONSTRUCTOR_PARAMS,
            self::MAX_METHOD_LINES,
            implode("\n  ", $new),
        ));
    }

    public function testListedOffendersOnlyShrink(): void
    {
        $measured = $this->measure();
        $ledger = $this->ledger();

        $grew = [];
        foreach (['fileLines', 'constructorParams', 'methodLines'] as $kind) {
            foreach ($ledger[$kind] as $key => $frozen) {
                $current = $measured[$kind][$key] ?? null;
                if (null !== $current && $current > $frozen) {
                    $grew[] = sprintf('%s  %s: %d → %d', $kind, $key, $frozen, $current);
                }
            }
        }

        $this->assertSame([], $grew, sprintf(
            "Frozen size offender(s) grew:\n  %s\n\nThe ratchet only turns one way. Put the new code into a class of its own instead of into the one that is already too big.",
            implode("\n  ", $grew),
        ));
    }

    public function testLedgerHasNoStaleEntries(): void
    {
        $measured = $this->measure();
        $ledger = $this->ledger();

        $stale = [];
        foreach (['fileLines', 'constructorParams', 'methodLines'] as $kind) {
            foreach ($ledger[$kind] as $key => $frozen) {
                if (!array_key_exists($key, $measured[$kind])) {
                    $stale[] = sprintf('%s  %s (frozen at %d)', $kind, $key, $frozen);
                }
            }
        }

        $this->assertSame([], $stale, sprintf(
            "Ledger entries that are no longer offenders (shrunk under the limit, split, or gone):\n  %s\n\nDelete them from sizeRatchetBaseline.json in the same PR — the ledger only shrinks. UPDATE_SIZE_RATCHET=1 does it for you; review the diff.",
            implode("\n  ", $stale),
        ));
    }

    public function testCountsConstructorParametersThroughNestedDefaultsAndAttributes(): void
    {
        $metrics = SourceMetrics::ofCode(<<<'PHP'
            <?php
            namespace App\Service;

            final class Sample
            {
                public function __construct(
                    private readonly Foo $foo,
                    #[Autowire(service: 'bar')] private Bar $bar,
                    private array $options = ['a' => 1, 'b' => [2, 3]],
                    private ?string $label = null,
                ) {
                }

                public static function make(): self
                {
                    $f = function (int $a, int $b) {
                        return $a + $b;
                    };
                    return new self(new Foo(), new Bar(), [], $f(1, 2) ? 'x' : null);
                }
            }
            PHP);

        $this->assertSame(['Sample' => 4], $metrics->constructorParams);
        $this->assertSame(['Sample::__construct' => 7, 'Sample::make' => 7], $metrics->methodLines, 'The closure inside make() is not a method.');
    }

    public function testIgnoresClassKeywordInConstantsAndAnonymousClasses(): void
    {
        $metrics = SourceMetrics::ofCode(<<<'PHP'
            <?php
            namespace App\Service;

            interface Shape
            {
                public function area(): float;
            }

            final class Square implements Shape
            {
                public function __construct() {}

                public function area(): float
                {
                    $name = self::class;
                    $anon = new class() {
                        public function __construct(int $x = 0) {}
                        public function noop(): void {}
                    };
                    return 1.0;
                }
            }
            PHP);

        $this->assertSame(['Square' => 0, SourceMetrics::ANONYMOUS => 1], $metrics->constructorParams);
        $this->assertArrayNotHasKey('Shape::area', $metrics->methodLines, 'An interface method has no body and is not measured.');
        $this->assertSame(1, $metrics->methodLines['Square::__construct']);
        $this->assertSame(9, $metrics->methodLines['Square::area']);
        $this->assertSame(1, $metrics->methodLines[SourceMetrics::ANONYMOUS.'::noop']);
    }

    /**
     * @return array{fileLines: array<string, int>, constructorParams: array<string, int>, methodLines: array<string, int>}
     */
    private function measure(): array
    {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }

        $measured = SizeOffenders::measure(dirname(__DIR__, 2));
        $this->assertGreaterThanOrEqual(self::MIN_EXPECTED_FILES, $measured['scanned'], 'The src/ scan found suspiciously few files.');

        $cache = ['fileLines' => $measured['fileLines'], 'constructorParams' => $measured['constructorParams'], 'methodLines' => $measured['methodLines']];

        if ('1' === getenv('UPDATE_SIZE_RATCHET')) {
            $this->writeLedger($cache);
        }

        return $cache;
    }

    /**
     * @return array{fileLines: array<string, int>, constructorParams: array<string, int>, methodLines: array<string, int>}
     */
    private function ledger(): array
    {
        $this->assertFileExists(self::LEDGER, 'Missing size ledger. Record it once with UPDATE_SIZE_RATCHET=1 and commit '.basename(self::LEDGER));
        $json = file_get_contents(self::LEDGER);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /* @var array{fileLines: array<string, int>, constructorParams: array<string, int>, methodLines: array<string, int>} $decoded */
        return [
            'fileLines' => $decoded['fileLines'] ?? [],
            'constructorParams' => $decoded['constructorParams'] ?? [],
            'methodLines' => $decoded['methodLines'] ?? [],
        ];
    }

    /**
     * @param array{fileLines: array<string, int>, constructorParams: array<string, int>, methodLines: array<string, int>} $measured
     */
    private function writeLedger(array $measured): void
    {
        $recorded = date('Y-m-d');
        if (is_file(self::LEDGER)) {
            $previous = json_decode((string) file_get_contents(self::LEDGER), true);
            if (is_array($previous) && is_string($previous['recorded'] ?? null)) {
                $recorded = $previous['recorded'];
            }
        }

        $payload = [
            '_comment' => 'Size ratchet ledger (tests/Architecture/SizeRatchetTest.php). Frozen offenders only; entries may shrink or disappear, never grow or be added. Regenerate with UPDATE_SIZE_RATCHET=1 and review the diff.',
            'recorded' => $recorded,
            'updated' => date('Y-m-d'),
            'limits' => [
                'fileLines' => self::MAX_FILE_LINES,
                'constructorParams' => self::MAX_CONSTRUCTOR_PARAMS,
                'methodLines' => self::MAX_METHOD_LINES,
            ],
            'counts' => [
                'fileLines' => count($measured['fileLines']),
                'constructorParams' => count($measured['constructorParams']),
                'methodLines' => count($measured['methodLines']),
            ],
            'fileLines' => $measured['fileLines'],
            'constructorParams' => $measured['constructorParams'],
            'methodLines' => $measured['methodLines'],
        ];

        file_put_contents(self::LEDGER, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
