<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every controller route that streams a response is either on the streams
 * pool's match line or on the short-stream allow-list. A new StreamedResponse
 * on a route that is neither fails this test. The match line is the contract
 * in the architecture-update plan, 03 §5.
 */
final class LongRouteListTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_PATTERNS = [
        '/api/v1/messages/stream*',
        '/api/v1/widget/*/message',
        '/v1/chat/completions',
        '/v1/messages',
        '/v1/audio/transcriptions/sessions/*/stream',
        '/api/v1/tts/stream',
        '/mcp',
    ];

    /**
     * Streams that finish as soon as they start writing. The usage export
     * sends a CSV it already built. A one-shot transcription streams one
     * event for a result it already has. Neither holds a worker on a provider.
     *
     * @var list<string>
     */
    private const SHORT_ROUTES = [
        '/api/v1/usage/export',
        '/v1/audio/transcriptions',
    ];

    public function testEveryStreamingRouteIsMatchedOrListedAsShort(): void
    {
        $snippet = $this->projectFile('_docker/backend/synaplan-php-workers.Caddyfile');
        $patterns = $this->matchPatterns($snippet);
        self::assertStringContainsString('/var/www/backend/public/index.php', $snippet);
        foreach (self::REQUIRED_PATTERNS as $required) {
            self::assertContains($required, $patterns, 'The streams pool no longer matches '.$required.'.');
        }

        $uncovered = [];
        foreach ($this->streamingRoutes() as $route) {
            if (in_array($route, self::SHORT_ROUTES, true) || $this->matchesAny($route, $patterns)) {
                continue;
            }
            $uncovered[] = $route;
        }

        self::assertSame([], $uncovered, 'A streaming route is neither on the streams pool nor on the short-stream allow-list.');
    }

    public function testTheEntrypointDefaultsTheStreamPoolToTheApiPool(): void
    {
        $entrypoint = $this->projectFile('_docker/backend/docker-entrypoint.sh');

        self::assertStringContainsString('[ -z "${FRANKENPHP_STREAM_WORKER_NUM:-}" ]', $entrypoint);
        self::assertStringContainsString('FRANKENPHP_STREAM_WORKER_NUM="$FRANKENPHP_WORKER_NUM"', $entrypoint);
    }

    public function testAFullServerAnswersTheLongRoutesWithTheBusyBodies(): void
    {
        $caddyfile = $this->projectFile('_docker/backend/Caddyfile');

        self::assertStringContainsString('{"error":"server_busy","retryAfter":5}', $caddyfile);
        self::assertStringContainsString('"code":"server_busy"', $caddyfile);
        self::assertStringContainsString('"type":"overloaded_error"', $caddyfile);
        self::assertStringContainsString('"code":-32000', $caddyfile);
        self::assertStringContainsString('Retry-After "5"', $caddyfile);
    }

    private function projectFile(string $relative): string
    {
        $name = basename($relative);
        $candidates = [dirname(__DIR__, 3).'/'.$relative];
        if ('Caddyfile' === $name) {
            $candidates[] = '/etc/caddy/Caddyfile';
        } elseif ('docker-entrypoint.sh' === $name) {
            $candidates[] = '/usr/local/bin/docker-entrypoint.sh';
        } else {
            // The live snippet at /etc/caddy is the dev stub. The template is
            // what production copies into place.
            $candidates[] = '/usr/local/etc/php/templates/'.$name;
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }
        self::fail('Could not read '.$relative.'.');
    }

    /**
     * @return list<string>
     */
    private function matchPatterns(string $snippet): array
    {
        self::assertSame(1, preg_match('/^\s*match\s+(.+)$/m', $snippet, $match), 'The streams worker has no match line.');

        return preg_split('/\s+/', trim($match[1])) ?: [];
    }

    /**
     * @return list<string>
     */
    private function streamingRoutes(): array
    {
        $routes = [];
        foreach (glob(dirname(__DIR__, 3).'/backend/src/Controller/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $classPrefix = $this->classPrefix($source);
            $methods = $this->methods($source);
            $streaming = $this->streamingMethodNames($methods);
            foreach ($methods as $method) {
                if (!isset($streaming[$method['name']]) || [] === $method['paths']) {
                    continue;
                }
                foreach ($method['paths'] as $path) {
                    $routes[] = $this->join($classPrefix, $path);
                }
            }
        }
        sort($routes);

        return array_values(array_unique($routes));
    }

    /**
     * @param list<array{name: string, body: string, paths: list<string>}> $methods
     *
     * @return array<string, true>
     */
    private function streamingMethodNames(array $methods): array
    {
        $streaming = [];
        foreach ($methods as $method) {
            if (str_contains($method['body'], 'new StreamedResponse') || str_contains($method['body'], 'return $this->reply->stream(')) {
                $streaming[$method['name']] = true;
            }
        }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($methods as $method) {
                if (isset($streaming[$method['name']])) {
                    continue;
                }
                foreach (array_keys($streaming) as $name) {
                    if (1 === preg_match('/\$this->'.preg_quote($name, '/').'\s*\(/', $method['body'])) {
                        $streaming[$method['name']] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return $streaming;
    }

    private function classPrefix(string $source): string
    {
        $classAt = strpos($source, "\nclass ");
        if (false === $classAt) {
            $classAt = strpos($source, 'class ');
        }
        $head = false === $classAt ? $source : substr($source, 0, $classAt);
        if (preg_match_all('/#\[Route\(\s*\'([^\']+)\'/', $head, $matches) < 1) {
            return '';
        }

        return (string) end($matches[1]);
    }

    /**
     * @return list<array{name: string, body: string, paths: list<string>}>
     */
    private function methods(string $source): array
    {
        preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $found, PREG_OFFSET_CAPTURE);
        $methods = [];
        $count = count($found[0]);
        for ($i = 0; $i < $count; ++$i) {
            $name = (string) $found[1][$i][0];
            $start = (int) $found[0][$i][1];
            $end = $i + 1 < $count ? (int) $found[0][$i + 1][1] : strlen($source);
            $windowStart = 0 === $i ? 0 : (int) $found[0][$i - 1][1];
            $before = substr($source, $windowStart, $start - $windowStart);
            $brace = strrpos($before, '}');
            $attrs = false === $brace ? $before : substr($before, $brace);
            preg_match_all('/#\[Route\(\s*\'([^\']+)\'/', $attrs, $paths);
            $methods[] = [
                'name' => $name,
                'body' => substr($source, $start, $end - $start),
                'paths' => $paths[1],
            ];
        }

        return $methods;
    }

    private function join(string $prefix, string $path): string
    {
        if ('' === $prefix) {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return rtrim($prefix, '/').$path;
        }

        return rtrim($prefix, '/').'/'.$path;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(string $route, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '#^'.str_replace('\\*', '.*', preg_quote($pattern, '#')).'$#';
            if (1 === preg_match($regex, $route)) {
                return true;
            }
        }

        return false;
    }
}
