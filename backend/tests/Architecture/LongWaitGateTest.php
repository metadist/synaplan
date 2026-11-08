<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A web request must not grow a new sleep, usleep, or set_time_limit.
 * Handlers and commands already run off the request. Every other site is
 * frozen in longWaitBaseline.json and may only disappear.
 */
final class LongWaitGateTest extends TestCase
{
    private const LEDGER = __DIR__.'/longWaitBaseline.json';

    public function testNoNewWaitOutsideHandlersAndCommands(): void
    {
        $found = $this->scan();
        $baseline = $this->baseline();
        $added = array_values(array_diff($found, $baseline));
        $gone = array_values(array_diff($baseline, $found));

        self::assertSame(
            [],
            $added,
            "New wait outside MessageHandler and Command. Move it into a job:\n  ".implode("\n  ", $added),
        );
        self::assertSame(
            [],
            $gone,
            "These waits are gone. Delete them from longWaitBaseline.json:\n  ".implode("\n  ", $gone),
        );
    }

    /**
     * @return list<string>
     */
    private function baseline(): array
    {
        $decoded = json_decode((string) file_get_contents(self::LEDGER), true);
        self::assertIsArray($decoded);

        /* @var list<string> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function scan(): array
    {
        $root = dirname(__DIR__, 2).'/src';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $relative = str_replace('\\', '/', $relative);
            if (str_starts_with($relative, 'MessageHandler/') || str_starts_with($relative, 'Command/')) {
                continue;
            }
            $lines = file($file->getPathname());
            if (false === $lines) {
                continue;
            }
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (1 !== preg_match('/\b(sleep|usleep|set_time_limit)\s*\(/', $trimmed)) {
                    continue;
                }
                $found[] = $relative.':'.$trimmed;
            }
        }
        sort($found);

        return $found;
    }
}
