<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Shrink-only inventory of outbound HTTP stacks (`03_contracts.md` §3.5, S2 AU14).
 *
 * A new `curl_init`, a raw HTTP read, `HttpClient::create()`, a Guzzle or SDK
 * client built without the shared HTTP client, or a discovery call fails this
 * test. `UPDATE_EGRESS_INVENTORY=1` rewrites the baseline only when every
 * remaining site was already listed.
 *
 * A site id is `path#kind#function#call`. The call text is the line, so a new
 * call cannot reuse the id of a call that was removed. Plugins listed in
 * `.gitignore` are local checkouts and stay out of this gate.
 */
final class EgressInventoryTest extends TestCase
{
    private const BASELINE = __DIR__.'/egressInventoryBaseline.json';

    /**
     * Top-level plugin directories in the repository `.gitignore`. A clean
     * checkout does not contain them, so they cannot sit in the baseline.
     *
     * @var list<string>
     */
    private const IGNORED_PLUGINS = [
        'brogent',
        'synaform',
        'synasort',
        'synaads',
        'synafastbill',
        'synamail',
    ];

    private const SCRATCH = <<<'PHP'
<?php

use GuzzleHttp\Client;
use GuzzleHttp\Client as GuzzleAlias;

/**
 * \OpenAI::client('sk-comment') must not be inventoried.
 */
function egressScratchForbidden(): void
{
    // \OpenAI::client('sk-comment');
    $ch = curl_init('https://example.test/raw');
    curl_close($ch);
    file_get_contents('https://example.test/file');
    fopen('https://example.test/stream', 'r');
    \Symfony\Component\HttpClient\HttpClient::create();
    new \GuzzleHttp\Client();
    new Client();
    new GuzzleAlias();
    \Http\Discovery\Psr18ClientDiscovery::find();
    \OpenAI::factory()->withApiKey('sk-test')->withBaseUri('https://example.test')->make();
    \OpenAI::client('sk-test');
    \ArdaGnsrn\Ollama\Ollama::client('http://ollama:11434');
    \Stripe\Stripe::setApiKey('sk_test');
    new \Google\Client();
    new \ReCaptcha\ReCaptcha('secret');
    new \AppStoreServerLibrary\SignedDataVerifier([], false, null, 'bundle', null);
}

function egressScratchAllowedBecauseTheClientIsInjected(): void
{
    \OpenAI::factory()->withApiKey('sk-test')->withHttpClient(new \stdClass())->make();
}

function egressScratchFactoryLookaheadDoesNotBorrowTheNextClient(): void
{
    $raw = \OpenAI::factory();
    $shared = \OpenAI::factory()->withHttpClient(new \stdClass())->make();
    $raw->make();
}

function egressScratchUnrelatedClient(): void
{
    new \Other\Client();
}
PHP;

    public function testSrcAndPluginsMatchTheFrozenInventory(): void
    {
        $found = self::scanTree(self::sourceRoots());
        $baseline = self::readBaseline();

        if ('1' === getenv('UPDATE_EGRESS_INVENTORY')) {
            $added = array_values(array_diff($found, $baseline));
            self::assertSame([], $added, "Refusing to add inventory sites:\n  ".implode("\n  ", $added));
            $removed = array_values(array_diff($baseline, $found));
            file_put_contents(self::BASELINE, json_encode($found, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
            self::assertNotSame([], $removed, 'UPDATE_EGRESS_INVENTORY requires at least one removal.');

            return;
        }

        $added = array_values(array_diff($found, $baseline));
        $removed = array_values(array_diff($baseline, $found));
        self::assertSame([], $added, "New outbound HTTP stack(s). Use the shared client instead:\n  ".implode("\n  ", $added));
        self::assertSame([], $removed, "Inventory baseline lists a site that is gone. Regenerate with removals only:\n  ".implode("\n  ", $removed));
    }

    public function testScannerCatchesEveryForbiddenPatternInTheScratchFixture(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'egress-scratch-');
        self::assertIsString($path);
        $php = $path.'.php';
        rename($path, $php);
        file_put_contents($php, self::SCRATCH);
        try {
            $sites = self::scanFile($php, 'tests/Architecture/fixtures/egress-scratch.php');
        } finally {
            unlink($php);
        }
        $kinds = [];
        foreach ($sites as $site) {
            $parts = explode('#', $site, 3);
            $kinds[$parts[1]] = ($kinds[$parts[1]] ?? 0) + 1;
        }

        self::assertSame(1, $kinds['curl_init'] ?? 0);
        self::assertSame(1, $kinds['http_file_get_contents'] ?? 0);
        self::assertSame(1, $kinds['http_fopen'] ?? 0);
        self::assertSame(1, $kinds['http_client_create'] ?? 0);
        self::assertSame(3, $kinds['guzzle_client'] ?? 0);
        self::assertSame(1, $kinds['psr18_discovery'] ?? 0);
        self::assertSame(3, $kinds['openai_sdk'] ?? 0);
        self::assertSame(1, $kinds['ollama_sdk'] ?? 0);
        self::assertSame(1, $kinds['stripe_default_http'] ?? 0);
        self::assertSame(1, $kinds['google_api_client'] ?? 0);
        self::assertSame(1, $kinds['recaptcha'] ?? 0);
        self::assertSame(1, $kinds['apple_store_sdk'] ?? 0);

        $lookahead = array_values(array_filter(
            $sites,
            static fn (string $site): bool => str_contains($site, '#egressScratchFactoryLookaheadDoesNotBorrowTheNextClient#'),
        ));
        self::assertCount(1, $lookahead, 'A later factory injecting an HTTP client must not hide the earlier factory.');
        self::assertStringContainsString('$raw = \\OpenAI::factory()', $lookahead[0]);

        $injected = array_values(array_filter(
            $sites,
            static fn (string $site): bool => str_contains($site, '#egressScratchAllowedBecauseTheClientIsInjected#'),
        ));
        self::assertSame([], $injected, 'An OpenAI factory that injects an HTTP client must not be inventoried.');
    }

    public function testIgnoredPluginListMatchesGitignore(): void
    {
        $gitignore = \dirname(__DIR__, 3).'/.gitignore';
        if (!is_file($gitignore)) {
            self::markTestSkipped('.gitignore is not mounted in this runtime.');
        }
        $ignored = [];
        $lines = file($gitignore, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        foreach ($lines as $line) {
            if (1 === preg_match('#^plugins/([A-Za-z0-9_-]+)/$#', trim($line), $matches)) {
                $ignored[] = $matches[1];
            }
        }
        $expected = self::IGNORED_PLUGINS;
        sort($expected);
        sort($ignored);
        self::assertSame($expected, $ignored);
    }

    /**
     * @param list<string> $roots absolute directories
     *
     * @return list<string> stable site ids, `path#kind#function#call`
     */
    public static function scanTree(array $roots): array
    {
        $sites = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }
                if (self::isIgnoredPlugin($file->getPathname())) {
                    continue;
                }
                $relative = self::relativePath($file->getPathname());
                foreach (self::scanFile($file->getPathname(), $relative) as $site) {
                    $sites[] = $site;
                }
            }
        }
        sort($sites);

        return $sites;
    }

    /**
     * @return list<string>
     */
    public static function scanFile(string $absolutePath, string $relativePath): array
    {
        $lines = file($absolutePath, \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return [];
        }

        $guzzleAliases = self::guzzleClientAliases($lines);
        $sites = [];
        $record = static function (string $kind, int $index) use (&$sites, $lines, $relativePath): void {
            $code = self::codePortion($lines[$index]);
            $call = preg_replace('/\s+/', ' ', trim($code));
            if (!\is_string($call) || '' === $call) {
                return;
            }
            $sites[] = $relativePath.'#'.$kind.'#'.self::enclosingSymbol($lines, $index).'#'.$call;
        };

        $count = \count($lines);
        for ($i = 0; $i < $count; ++$i) {
            $code = self::codePortion($lines[$i]);
            if ('' === $code) {
                continue;
            }
            if (str_contains($code, 'curl_init(')) {
                $record('curl_init', $i);
            }
            if (1 === preg_match('/file_get_contents\(\s*[\'"]https?:/', $code)
                || 1 === preg_match('/file_get_contents\(\s*\$(url|imageUrl|maskUrl)\b/', $code)
            ) {
                $record('http_file_get_contents', $i);
            }
            if (1 === preg_match('/fopen\(\s*[\'"]https?:/', $code)) {
                $record('http_fopen', $i);
            }
            if (str_contains($code, 'HttpClient::create(')) {
                $record('http_client_create', $i);
            }
            if (self::lineBuildsGuzzleClient($code, $guzzleAliases)) {
                $record('guzzle_client', $i);
            }
            if (str_contains($code, 'Psr18ClientDiscovery')) {
                $record('psr18_discovery', $i);
            }
            if (str_contains($code, 'OpenAI::factory(') && !self::factoryInjectsHttpClient($lines, $i)) {
                $record('openai_sdk', $i);
            }
            if (str_contains($code, 'OpenAI::client(')) {
                $record('openai_sdk', $i);
            }
            if (str_contains($code, 'Ollama::client(')) {
                $record('ollama_sdk', $i);
            }
            if (str_contains($code, 'Stripe::setApiKey(')) {
                $record('stripe_default_http', $i);
            }
            if (1 === preg_match('/new\s+(\\\\?Google\\\\Client|GoogleClient)\s*\(/', $code)) {
                $record('google_api_client', $i);
            }
            if (str_contains($code, 'new ReCaptcha(') || str_contains($code, 'new \\ReCaptcha\\ReCaptcha(')) {
                $record('recaptcha', $i);
            }
            if (str_contains($code, 'SignedDataVerifier(')) {
                $record('apple_store_sdk', $i);
            }
        }

        return self::disambiguate($sites);
    }

    /**
     * withHttpClient() counts only on the factory expression being checked.
     * The expression ends at its semicolon, at make(), or at the next statement.
     *
     * @param list<string> $lines
     */
    private static function factoryInjectsHttpClient(array $lines, int $start): bool
    {
        $statement = '';
        $end = min(\count($lines), $start + 16);
        for ($i = $start; $i < $end; ++$i) {
            $piece = self::codePortion($lines[$i]);
            if ('' === $piece) {
                continue;
            }
            if ($i === $start) {
                $pos = strpos($piece, 'OpenAI::factory(');
                if (false === $pos) {
                    return false;
                }
                $piece = substr($piece, $pos);
            } elseif (!str_starts_with(ltrim($piece), '->') && !str_starts_with(ltrim($piece), '?->')) {
                break;
            }
            $statement .= $piece."\n";
            if (str_contains($piece, ';') || str_contains($piece, '->make(')) {
                break;
            }
        }

        $cut = \strlen($statement);
        $semi = strpos($statement, ';');
        $make = strpos($statement, '->make(');
        if (false !== $semi) {
            $cut = min($cut, $semi);
        }
        if (false !== $make) {
            $cut = min($cut, $make);
        }

        return str_contains(substr($statement, 0, $cut), 'withHttpClient(');
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function guzzleClientAliases(array $lines): array
    {
        $aliases = [];
        $group = '';
        foreach ($lines as $line) {
            $code = trim(self::codePortion($line));
            if ('' === $code && '' === $group) {
                continue;
            }
            if ('' !== $group) {
                $group .= $code;
                if (!str_contains($code, '};') && !str_contains($group, '};')) {
                    continue;
                }
                self::collectGuzzleGroupAliases($group, $aliases);
                $group = '';
                continue;
            }
            if (1 === preg_match('/^use\s+GuzzleHttp\\\\\{/', $code)) {
                $group = $code;
                if (str_contains($code, '};')) {
                    self::collectGuzzleGroupAliases($group, $aliases);
                    $group = '';
                }
                continue;
            }
            if (1 === preg_match('/^use\s+GuzzleHttp\\\\Client\s*;/', $code)) {
                $aliases[] = 'Client';
            } elseif (1 === preg_match('/^use\s+GuzzleHttp\\\\Client\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/', $code, $matches)) {
                $aliases[] = $matches[1];
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param list<string> $aliases
     */
    private static function collectGuzzleGroupAliases(string $group, array &$aliases): void
    {
        if (1 !== preg_match('/^use\s+GuzzleHttp\\\\\{([^}]*)\}/', $group, $matches)) {
            return;
        }
        foreach (explode(',', $matches[1]) as $part) {
            $part = trim($part);
            if ('Client' === $part) {
                $aliases[] = 'Client';
            } elseif (1 === preg_match('/^Client\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/', $part, $alias)) {
                $aliases[] = $alias[1];
            }
        }
    }

    /**
     * @param list<string> $aliases
     */
    private static function lineBuildsGuzzleClient(string $code, array $aliases): bool
    {
        if (1 === preg_match('/new\s+\\\\?GuzzleHttp\\\\Client\s*\(/', $code)) {
            return true;
        }
        foreach ($aliases as $alias) {
            if (1 === preg_match('/new\s+'.preg_quote($alias, '/').'\s*\(/', $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     */
    private static function enclosingSymbol(array $lines, int $index): string
    {
        for ($i = $index; $i >= 0; --$i) {
            if (1 === preg_match('/function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', self::codePortion($lines[$i]), $matches)) {
                return $matches[1];
            }
        }

        return 'file';
    }

    private static function codePortion(string $line): string
    {
        $trim = ltrim($line);
        if ('' === $trim || str_starts_with($trim, '*') || str_starts_with($trim, '/*') || str_starts_with($trim, '//') || str_starts_with($trim, '#')) {
            return '';
        }
        $cut = \strlen($line);
        if (1 === preg_match('/(?<!:)\/\//', $line, $matches, \PREG_OFFSET_CAPTURE)) {
            $cut = $matches[0][1];
        }
        $code = rtrim(substr($line, 0, $cut));

        return '' === trim($code) ? '' : $code;
    }

    private static function isIgnoredPlugin(string $absolutePath): bool
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        foreach (self::IGNORED_PLUGINS as $name) {
            if (str_contains($normalized, '/plugins/'.$name.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $sites
     *
     * @return list<string>
     */
    private static function disambiguate(array $sites): array
    {
        /** @var array<string, int> $seen */
        $seen = [];
        $out = [];
        foreach ($sites as $site) {
            $seen[$site] = ($seen[$site] ?? 0) + 1;
            $out[] = $seen[$site] > 1 ? $site.'#'.$seen[$site] : $site;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function sourceRoots(): array
    {
        $backend = \dirname(__DIR__, 2);
        $roots = [$backend.'/src'];
        $plugins = \dirname($backend).'/plugins';
        if (!is_dir($plugins) && is_dir('/plugins')) {
            $plugins = '/plugins';
        }
        if (is_dir($plugins)) {
            $roots[] = $plugins;
        }

        return $roots;
    }

    private static function relativePath(string $absolute): string
    {
        $backend = \dirname(__DIR__, 2);
        $repo = \dirname($backend);
        if (str_starts_with($absolute, $repo.'/') && '/var/www' !== $repo) {
            return substr($absolute, \strlen($repo) + 1);
        }
        if (str_starts_with($absolute, '/plugins/')) {
            return 'plugins/'.substr($absolute, \strlen('/plugins/'));
        }
        if (str_starts_with($absolute, $backend.'/')) {
            return 'backend/'.substr($absolute, \strlen($backend) + 1);
        }

        return $absolute;
    }

    /**
     * @return list<string>
     */
    private static function readBaseline(): array
    {
        if (!is_file(self::BASELINE)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents(self::BASELINE), true);
        if (!\is_array($decoded)) {
            self::fail('egressInventoryBaseline.json is not a JSON list.');
        }
        $sites = [];
        foreach ($decoded as $site) {
            self::assertIsString($site);
            $sites[] = $site;
        }
        sort($sites);

        return $sites;
    }
}
