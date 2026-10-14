<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Code cleanup V1 — every class under src/ belongs to exactly one deptrac layer.
 *
 * deptrac only checks classes that a layer collector matches, and its
 * `--fail-on-uncovered` flag cannot stand in for this: it fails on every
 * dependency into a class outside all layers, which on this codebase is the
 * ~18k references to Symfony, Doctrine and the other vendor libraries, so the
 * gate would be permanently red. A new `App\` namespace missing from
 * deptrac.yaml would instead pass silently — no layer owns it, so none of its
 * dependencies is a violation.
 *
 * This test reads the collectors from deptrac.yaml (the one source of truth)
 * and fails when a class matches none of them. Add the namespace to a layer in
 * the same PR; do not exclude the class.
 */
final class LayerCoverageTest extends TestCase
{
    /** Guard against a vacuous pass if scanning src/ breaks (1,615 files on 2026-10-14). */
    private const MIN_EXPECTED_CLASSES = 1000;

    public function testEverySrcClassBelongsToALayer(): void
    {
        $config = $this->deptracConfig();
        $patterns = $this->layerPatterns($config);
        $this->assertNotEmpty($patterns, 'deptrac.yaml defines no layer collectors — the coverage check would pass vacuously.');

        $classes = $this->srcClassNames($config['exclude_files'] ?? []);
        $this->assertGreaterThanOrEqual(self::MIN_EXPECTED_CLASSES, count($classes), 'Class scanning found suspiciously few classes under src/.');

        $unclassified = array_values(array_filter(
            $classes,
            static function (string $class) use ($patterns): bool {
                foreach ($patterns as $pattern) {
                    if (1 === preg_match('#'.$pattern.'#', $class)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $this->assertSame([], $unclassified, sprintf(
            "Class(es) under src/ match no layer in deptrac.yaml:\n  %s\n\nA namespace in no layer is checked by nothing, so its dependencies pass the architecture gate silently. Add it to a layer collector in deptrac.yaml in the same change.",
            implode("\n  ", $unclassified),
        ));
    }

    /**
     * @return array{layers: list<array{collectors: list<array{type: string, value: string}>}>, exclude_files?: list<string>}
     */
    private function deptracConfig(): array
    {
        $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/deptrac.yaml');
        $this->assertIsArray($parsed);

        /** @var array{layers: list<array{collectors: list<array{type: string, value: string}>}>, exclude_files?: list<string>} $config */
        $config = $parsed['deptrac'];

        return $config;
    }

    /**
     * @param array{layers: list<array{collectors: list<array{type: string, value: string}>}>} $config
     *
     * @return list<string>
     */
    private function layerPatterns(array $config): array
    {
        $patterns = [];
        foreach ($config['layers'] as $layer) {
            foreach ($layer['collectors'] as $collector) {
                if ('classLike' === $collector['type']) {
                    $patterns[] = $collector['value'];
                }
            }
        }

        return $patterns;
    }

    /**
     * @param list<string> $excludePatterns regexes matched against the path relative to backend/ (e.g. src/Kernel.php)
     *
     * @return list<string> FQCNs, sorted
     */
    private function srcClassNames(array $excludePatterns): array
    {
        $root = dirname(__DIR__, 2).'/src';
        $names = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = 'src/'.substr($file->getPathname(), strlen($root) + 1);
            foreach ($excludePatterns as $pattern) {
                if (1 === preg_match($pattern, $relative)) {
                    continue 2;
                }
            }

            $code = file_get_contents($file->getPathname());
            if (false === $code || 1 !== preg_match('/^namespace\s+([^;]+);/m', $code, $namespace)) {
                continue;
            }

            // Leading whitespace is allowed because the test bootstrap's
            // BypassFinals stream wrapper rewrites `final`/`readonly` away and
            // leaves the declaration indented.
            preg_match_all('/^[ \t]*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $classes);
            foreach ($classes[1] as $class) {
                $names[] = $namespace[1].'\\'.$class;
            }
        }

        sort($names);

        return $names;
    }
}
