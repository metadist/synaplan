<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * Everything the fixture remembers, in one folder: the state (profile,
 * instance credential, switches, read scopes, linked people), each person's
 * files, what Synaplan wrote, and the notifications that came in.
 */
final class Store
{
    public const SWITCHES = ['hold_inbound', 'mute_events', 'allow_self_write'];
    public const PROFILES = ['keyed', 'sso'];

    private const LOG_LINES = 200;

    public function __construct(private readonly string $root)
    {
        foreach (['', '/files'] as $dir) {
            if (!is_dir($this->root.$dir)) {
                mkdir($this->root.$dir, 0775, true);
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $root = getenv('FIXTURE_DATA');

        return new self(rtrim(is_string($root) && '' !== $root ? $root : __DIR__.'/../var', '/'));
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $raw = is_file($this->root.'/state.json') ? (string) file_get_contents($this->root.'/state.json') : '';
        $saved = json_decode('' !== $raw ? $raw : '{}', true);
        $state = self::defaults();
        foreach (is_array($saved) ? $saved : [] as $key => $value) {
            $state[$key] = is_array($value) && is_array($state[$key] ?? null) && !array_is_list($state[$key])
                ? array_replace($state[$key], $value)
                : $value;
        }

        return $state;
    }

    /**
     * Read, change and write the state; readers see the old or the new
     * file, never half of one.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    public function update(callable $change): array
    {
        return $this->locked('state', function () use ($change): array {
            $state = $change($this->state());
            $this->publish('state.json', $state);

            return $state;
        });
    }

    /**
     * Run one read-change-write under a named lock: the built-in server runs
     * several workers.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function locked(string $name, callable $work): mixed
    {
        $lock = fopen($this->root.'/'.preg_replace('/[^a-z0-9_-]/i', '_', $name).'.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('The fixture cannot lock '.$name.' in '.$this->root.'.');
        }
        flock($lock, LOCK_EX);
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function reset(): void
    {
        $this->removeTree($this->root.'/files');
        mkdir($this->root.'/files', 0775, true);
        foreach (['state.json', 'written.json', 'log.jsonl'] as $file) {
            if (is_file($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }
    }

    public function put(string $person, string $path, string $bytes): void
    {
        $absolute = $this->absolute($person, $path);
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }
        file_put_contents($absolute, $bytes);
    }

    public function read(string $person, string $path): ?string
    {
        $absolute = $this->absolute($person, $path);

        return is_file($absolute) ? (string) file_get_contents($absolute) : null;
    }

    public function exists(string $person, string $path): bool
    {
        return is_file($this->absolute($person, $path));
    }

    public function folderExists(string $person, string $folder): bool
    {
        return is_dir($this->absolute($person, $folder));
    }

    public function delete(string $person, string $path): bool
    {
        $absolute = $this->absolute($person, $path);

        return is_file($absolute) && unlink($absolute);
    }

    /**
     * Files under a folder, as paths relative to the person's root.
     *
     * @return list<array{path: string, size: int, modified: int}>
     */
    public function files(string $person, string $folder = '', bool $recursive = true): array
    {
        $base = $this->absolute($person, $folder);
        if (!is_dir($base)) {
            return [];
        }
        $root = $this->absolute($person, '');
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS))
            : new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS);
        $out = [];
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $out[] = [
                    'path' => ltrim(substr($entry->getPathname(), strlen($root)), '/'),
                    'size' => (int) $entry->getSize(),
                    'modified' => (int) $entry->getMTime(),
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * Every folder of a person, as paths relative to the person's root (the
     * root itself is '').
     *
     * @return list<string>
     */
    public function folders(string $person): array
    {
        $root = $this->absolute($person, '');
        if (!is_dir($root)) {
            return [''];
        }
        $out = [''];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir()) {
                $out[] = ltrim(substr($entry->getPathname(), strlen($root)), '/');
            }
        }
        sort($out);

        return $out;
    }

    /**
     * The folder a remote folder id names, or null when the person has none.
     */
    public function folderById(string $person, string $profile, string $id): ?string
    {
        foreach ($this->folders($person) as $folder) {
            if (Profile::remoteId($profile, $person, $folder) === $id) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function recordWritten(array $entry): void
    {
        $this->locked('written', function () use ($entry): void {
            $written = $this->written();
            $written[] = $entry + ['at' => time()];
            $this->publish('written.json', $written);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function written(): array
    {
        $raw = is_file($this->root.'/written.json') ? (string) file_get_contents($this->root.'/written.json') : '[]';
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    public function wroteThis(string $person, string $path): bool
    {
        foreach ($this->written() as $entry) {
            if (($entry['person'] ?? null) === $person && ($entry['path'] ?? null) === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function log(array $entry): void
    {
        file_put_contents($this->root.'/log.jsonl', json_encode(['at' => time()] + $entry, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function logEntries(int $limit = 50): array
    {
        $lines = is_file($this->root.'/log.jsonl') ? file($this->root.'/log.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $lines = array_slice(false === $lines ? [] : $lines, -min($limit, self::LOG_LINES));

        return array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), $lines), 'is_array'));
    }

    /**
     * A person's id as a folder name. Platform user ids are short and plain;
     * anything else is refused rather than escaped.
     */
    public static function person(string $externalId): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $externalId) || str_starts_with($externalId, '.')) {
            throw new FixtureException(400, 'bad_person', 'A person id has 1 to 64 letters, digits or . _ @ - and does not start with a dot.');
        }

        return $externalId;
    }

    /**
     * A path inside a person's files: no parent steps, no empty segments.
     */
    public static function cleanPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            if ('..' === $part) {
                throw new FixtureException(400, 'bad_path', 'A path may not leave the person\'s files.');
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'profile' => 'keyed',
            'client' => 'nextcloud',
            'synaplan_url' => self::env('SYNAPLAN_URL', 'http://backend'),
            'public_url' => self::env('SYNAPLAN_PUBLIC_URL', 'http://localhost:5173'),
            'fixture_url' => self::env('FIXTURE_PUBLIC_URL', 'http://localhost:8099'),
            'inbound_url' => self::env('FIXTURE_INBOUND_URL', 'http://cloud-fixture:8099/inbound'),
            'instance' => ['id' => '', 'secret' => ''],
            'notify_keys' => [],
            'switches' => array_fill_keys(self::SWITCHES, false),
            'scopes' => [],
            'links' => [],
            'pending' => [],
        ];
    }

    /**
     * Write a whole file next to its final name, then rename it into place.
     *
     * @param array<mixed> $data
     */
    private function publish(string $file, array $data): void
    {
        $temp = $this->root.'/'.$file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($temp, $this->root.'/'.$file);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? rtrim($value, '/') : $default;
    }

    private function absolute(string $person, string $path): string
    {
        $clean = self::cleanPath($path);

        return $this->root.'/files/'.self::person($person).('' !== $clean ? '/'.$clean : '');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($dir);
    }
}
