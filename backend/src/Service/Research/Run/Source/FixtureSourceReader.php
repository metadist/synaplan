<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * Serves pages for fixture.synaplan.test from local files. Any other host is
 * refused. This class makes no network call, and it is registered only in the
 * test environment.
 */
final readonly class FixtureSourceReader implements ResearchSourceReaderInterface
{
    public const HOST = 'fixture.synaplan.test';
    public const REFUSED = 'blocked: not a fixture';

    public function __construct(
        private string $pagesDir,
    ) {
    }

    public function read(string $url): SourceRead
    {
        $parts = parse_url($url);
        $host = \is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (self::HOST !== $host) {
            return new SourceRead($url, true, '', self::REFUSED);
        }

        $base = basename((string) ($parts['path'] ?? ''));
        if ('' === $base || '.' === $base || '..' === $base) {
            return new SourceRead($url, true, '', self::REFUSED);
        }

        $path = $this->pagesDir.'/'.$base;
        if (!is_file($path)) {
            return new SourceRead($url, true, '', self::REFUSED);
        }

        return new SourceRead($url, false, (string) file_get_contents($path), '');
    }
}
