<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\ThinControllerOffenders;
use PHPUnit\Framework\TestCase;

/**
 * Code cleanup V4 (S9) — controllers are thin, as a gate.
 *
 * Three rules for every class under src/Controller/ (AGENTS.md, "Architecture
 * Patterns"): a method has at most 50 lines; no constructor or action takes
 * Doctrine's EntityManagerInterface (queries belong in repositories, writes
 * in services); none takes the AiFacade (an AI call belongs behind its
 * domain service). Every offender that existed when the gate was introduced
 * is frozen in thinControllerBaseline.json. A listed entry may shrink or
 * disappear, never grow; an unlisted offender fails.
 *
 * Moved the work out? Delete its ledger entry in the same PR (the
 * stale-entry test tells you which). UPDATE_THIN_CONTROLLER=1 regenerates
 * the ledger; review the diff: removals and smaller numbers only.
 *
 * Layers stay deptrac's job: AiFacade is application code the entry layer
 * may use, so these two names are an exact reflection check here instead
 * of a layer of their own.
 */
final class ThinControllerTest extends TestCase
{
    private const LEDGER = __DIR__.'/thinControllerBaseline.json';

    /** Guard against a vacuous pass if the scan breaks (131 controllers on 2026-10-17). */
    private const MIN_EXPECTED_FILES = 100;

    public function testEveryOffenderIsOnTheLedger(): void
    {
        $measured = $this->measure();
        $ledger = $this->ledger();

        $new = array_map(
            static fn (string $key): string => sprintf('%s = %d lines', $key, $measured['methodLines'][$key]),
            array_keys(array_diff_key($measured['methodLines'], $ledger['methodLines'])),
        );
        foreach (['entityManager', 'aiFacade'] as $kind) {
            foreach (array_diff($measured[$kind], $ledger[$kind]) as $controller) {
                $new[] = sprintf('%s takes %s', $controller, ThinControllerOffenders::FORBIDDEN[$kind]);
            }
        }

        $this->assertSame([], $new, sprintf(
            "New thin-controller offender(s) — at most %d lines per method, no entity manager and no AI facade in a controller:\n  %s\n\nMove the work into a service (and its queries into a repository); the controller validates, delegates and answers. The ledger only freezes what existed before the gate; do not add to it.",
            ThinControllerOffenders::MAX_METHOD_LINES,
            implode("\n  ", $new),
        ));
    }

    public function testListedMethodsOnlyShrink(): void
    {
        $measured = $this->measure();
        $grew = [];
        foreach ($this->ledger()['methodLines'] as $key => $frozen) {
            $current = $measured['methodLines'][$key] ?? null;
            if (null !== $current && $current > $frozen) {
                $grew[] = sprintf('%s: %d → %d', $key, $frozen, $current);
            }
        }

        $this->assertSame([], $grew, sprintf(
            "Frozen controller method(s) grew:\n  %s\n\nPut the new work into a service instead of the action that is already too long.",
            implode("\n  ", $grew),
        ));
    }

    public function testLedgerHasNoStaleEntries(): void
    {
        $measured = $this->measure();
        $ledger = $this->ledger();

        $stale = array_map(
            static fn (string $key): string => sprintf('methodLines  %s (frozen at %d)', $key, $ledger['methodLines'][$key]),
            array_keys(array_diff_key($ledger['methodLines'], $measured['methodLines'])),
        );
        foreach (['entityManager', 'aiFacade'] as $kind) {
            foreach (array_diff($ledger[$kind], $measured[$kind]) as $controller) {
                $stale[] = sprintf('%s  %s', $kind, $controller);
            }
        }

        $this->assertSame([], $stale, sprintf(
            "Ledger entries that are no longer offenders:\n  %s\n\nDelete them from thinControllerBaseline.json in the same PR — the ledger only shrinks. UPDATE_THIN_CONTROLLER=1 does it for you; review the diff.",
            implode("\n  ", $stale),
        ));
    }

    public function testTheGateCatchesALongActionAnEntityManagerAndTheAiFacade(): void
    {
        $directory = sys_get_temp_dir().'/thin-controller-'.bin2hex(random_bytes(4));
        $namespace = 'App\\Tests\\Architecture\\Scratch'.bin2hex(random_bytes(4));
        mkdir($directory.'/Controller', 0o777, true);
        $file = $directory.'/Controller/ScratchController.php';
        file_put_contents($file, $this->scratchController($namespace));

        try {
            require_once $file;
            $measured = ThinControllerOffenders::measure($directory, 'Controller', $namespace);
        } finally {
            unlink($file);
            rmdir($directory.'/Controller');
            rmdir($directory);
        }

        $this->assertSame(['Controller/ScratchController.php::ScratchController::tooLong' => 51], $measured['methodLines'], 'A 51-line method fails, a 50-line one does not.');
        $this->assertSame(['Controller/ScratchController.php'], $measured['entityManager'], 'The entity manager in the constructor.');
        $this->assertSame(['Controller/ScratchController.php'], $measured['aiFacade'], 'The AI facade as an action argument.');
    }

    private function scratchController(string $namespace): string
    {
        $body = static fn (int $statements): string => str_repeat("        \$x = 1;\n", $statements);

        return "<?php\n\nnamespace {$namespace};\n\nuse App\\AI\\Service\\AiFacade;\nuse Doctrine\\ORM\\EntityManagerInterface;\n\n"
            ."final class ScratchController\n{\n"
            ."    public function __construct(private ?EntityManagerInterface \$em = null)\n    {\n    }\n\n"
            ."    public function tooLong(): void\n    {\n".$body(48)."    }\n\n"
            ."    public function justShortEnough(): void\n    {\n".$body(47)."    }\n\n"
            ."    public function ask(AiFacade \$ai): void\n    {\n    }\n}\n";
    }

    /**
     * @return array{methodLines: array<string, int>, entityManager: list<string>, aiFacade: list<string>}
     */
    private function measure(): array
    {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }

        $measured = ThinControllerOffenders::measure(dirname(__DIR__, 2));
        $this->assertGreaterThanOrEqual(self::MIN_EXPECTED_FILES, $measured['scanned'], 'The src/Controller scan found suspiciously few files.');
        $cache = ['methodLines' => $measured['methodLines'], 'entityManager' => $measured['entityManager'], 'aiFacade' => $measured['aiFacade']];

        if ('1' === getenv('UPDATE_THIN_CONTROLLER')) {
            $this->writeLedger($cache);
        }

        return $cache;
    }

    /**
     * @return array{methodLines: array<string, int>, entityManager: list<string>, aiFacade: list<string>}
     */
    private function ledger(): array
    {
        $this->assertFileExists(self::LEDGER, 'Missing thin-controller ledger. Record it once with UPDATE_THIN_CONTROLLER=1 and commit '.basename(self::LEDGER));
        $decoded = json_decode((string) file_get_contents(self::LEDGER), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return [
            'methodLines' => $decoded['methodLines'] ?? [],
            'entityManager' => $decoded['entityManager'] ?? [],
            'aiFacade' => $decoded['aiFacade'] ?? [],
        ];
    }

    /**
     * @param array{methodLines: array<string, int>, entityManager: list<string>, aiFacade: list<string>} $measured
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
            '_comment' => 'Thin-controller ledger (tests/Architecture/ThinControllerTest.php). Frozen offenders only; entries may shrink or disappear, never grow or be added. Regenerate with UPDATE_THIN_CONTROLLER=1 and review the diff.',
            'recorded' => $recorded,
            'updated' => date('Y-m-d'),
            'limits' => ['methodLines' => ThinControllerOffenders::MAX_METHOD_LINES],
            'counts' => [
                'methodLines' => count($measured['methodLines']),
                'entityManager' => count($measured['entityManager']),
                'aiFacade' => count($measured['aiFacade']),
            ],
            'methodLines' => $measured['methodLines'],
            'entityManager' => $measured['entityManager'],
            'aiFacade' => $measured['aiFacade'],
        ];

        file_put_contents(self::LEDGER, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
