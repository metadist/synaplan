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
 */
final class EgressInventoryTest extends TestCase
{
    private const BASELINE = __DIR__.'/egressInventoryBaseline.json';

    private const SCRATCH = <<<'PHP'
<?php

function egressScratchForbidden(): void
{
    $ch = curl_init('https://example.test/raw');
    curl_close($ch);
    file_get_contents('https://example.test/file');
    fopen('https://example.test/stream', 'r');
    \Symfony\Component\HttpClient\HttpClient::create();
    new \GuzzleHttp\Client();
    \Http\Discovery\Psr18ClientDiscovery::find();
    \OpenAI::factory()->withApiKey('sk-test')->withBaseUri('https://example.test')->make();
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
            $parts = explode('#', $site);
            $kinds[$parts[\count($parts) - 2]] = true;
        }

        foreach ([
            'curl_init',
            'http_file_get_contents',
            'http_fopen',
            'http_client_create',
            'guzzle_client',
            'psr18_discovery',
            'openai_sdk',
            'ollama_sdk',
            'stripe_default_http',
            'google_api_client',
            'recaptcha',
            'apple_store_sdk',
        ] as $kind) {
            self::assertArrayHasKey($kind, $kinds, 'The scratch fixture was not detected as '.$kind.'.');
        }

        $openai = array_values(array_filter(
            $sites,
            static fn (string $site): bool => str_contains($site, '#openai_sdk#'),
        ));
        self::assertCount(1, $openai, 'An OpenAI factory that injects an HTTP client must not be inventoried.');
    }

    /**
     * @param list<string> $roots absolute directories
     *
     * @return list<string> stable site ids, `relative/path.php#kind#n`
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

        /** @var array<string, int> $counts */
        $counts = [];
        $sites = [];
        $record = static function (string $kind) use (&$counts, &$sites, $relativePath): void {
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            $sites[] = $relativePath.'#'.$kind.'#'.$counts[$kind];
        };

        $count = \count($lines);
        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];
            if (str_contains($line, 'curl_init(')) {
                $record('curl_init');
            }
            if (1 === preg_match('/file_get_contents\(\s*[\'"]https?:/', $line)
                || 1 === preg_match('/file_get_contents\(\s*\$(url|imageUrl|maskUrl)\b/', $line)
            ) {
                $record('http_file_get_contents');
            }
            if (1 === preg_match('/fopen\(\s*[\'"]https?:/', $line)) {
                $record('http_fopen');
            }
            if (str_contains($line, 'HttpClient::create(')) {
                $record('http_client_create');
            }
            if (1 === preg_match('/new\s+\\\\?GuzzleHttp\\\\Client\s*\(/', $line)) {
                $record('guzzle_client');
            }
            if (str_contains($line, 'Psr18ClientDiscovery')) {
                $record('psr18_discovery');
            }
            if (str_contains($line, 'OpenAI::factory(') && !self::factoryInjectsHttpClient($lines, $i)) {
                $record('openai_sdk');
            }
            if (str_contains($line, 'Ollama::client(')) {
                $record('ollama_sdk');
            }
            if (str_contains($line, 'Stripe::setApiKey(')) {
                $record('stripe_default_http');
            }
            if (1 === preg_match('/new\s+(\\\\?Google\\\\Client|GoogleClient)\s*\(/', $line)) {
                $record('google_api_client');
            }
            if (str_contains($line, 'new ReCaptcha(') || str_contains($line, 'new \\ReCaptcha\\ReCaptcha(')) {
                $record('recaptcha');
            }
            if (str_contains($line, 'SignedDataVerifier(')) {
                $record('apple_store_sdk');
            }
        }

        return $sites;
    }

    /**
     * @param list<string> $lines
     */
    private static function factoryInjectsHttpClient(array $lines, int $start): bool
    {
        $window = '';
        $end = min(\count($lines), $start + 16);
        for ($i = $start; $i < $end; ++$i) {
            $window .= $lines[$i]."\n";
            if (str_contains($lines[$i], '->make()') || str_contains($lines[$i], '->make();')) {
                break;
            }
        }

        return str_contains($window, 'withHttpClient(');
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
