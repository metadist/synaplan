<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Support;

/**
 * The size offenders under src/: files over 800 lines, constructors over 10
 * parameters, method bodies over 100 lines.
 *
 * One scan for the size ratchet ({@see \App\Tests\Architecture\SizeRatchetTest})
 * and for `make -C backend metrics`, so both always report the same numbers.
 */
final class SizeOffenders
{
    public const MAX_FILE_LINES = 800;
    public const MAX_CONSTRUCTOR_PARAMS = 10;
    public const MAX_METHOD_LINES = 100;

    /** Catalog data, not classes (code cleanup V2 master plan §1). */
    public const EXCLUDED_PATHS = [
        'src/Model/ModelCatalog.php',
        'src/Prompt/PromptCatalog.php',
    ];

    /**
     * @param string $root the backend directory
     *
     * @return array{scanned: int, fileLines: array<string, int>, constructorParams: array<string, int>, methodLines: array<string, int>}
     */
    public static function measure(string $root): array
    {
        $fileLines = [];
        $constructorParams = [];
        $methodLines = [];
        $scanned = 0;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (in_array($relative, self::EXCLUDED_PATHS, true)) {
                continue;
            }
            ++$scanned;

            $metrics = SourceMetrics::ofFile($file->getPathname());
            if ($metrics->lines > self::MAX_FILE_LINES) {
                $fileLines[$relative] = $metrics->lines;
            }
            foreach ($metrics->constructorParams as $class => $params) {
                if ($params > self::MAX_CONSTRUCTOR_PARAMS) {
                    $constructorParams[$relative.'::'.$class] = $params;
                }
            }
            foreach ($metrics->methodLines as $method => $lines) {
                if ($lines > self::MAX_METHOD_LINES) {
                    $methodLines[$relative.'::'.$method] = $lines;
                }
            }
        }

        ksort($fileLines);
        ksort($constructorParams);
        ksort($methodLines);

        return [
            'scanned' => $scanned,
            'fileLines' => $fileLines,
            'constructorParams' => $constructorParams,
            'methodLines' => $methodLines,
        ];
    }
}
