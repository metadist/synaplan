<?php

declare(strict_types=1);

namespace App\Service\Compute\Contract;

/**
 * POST /v1/runs request.json (protocol 1).
 */
final readonly class ComputeRunRequest
{
    private const KEYS = ['protocol', 'owner', 'workspace', 'image', 'entry', 'files', 'limits', 'egress'];

    /**
     * @param array{kind: string, id?: string}                                            $workspace
     * @param array{program: string, args: list<string>}                                  $entry
     * @param list<array{name: string, role: string}>                                     $files
     * @param array{timeoutSec: int, memoryMb: int, cpu: float, pids: int, outputMb: int} $limits
     * @param array{allow: list<array{host: string, port: int, ips: list<string>}>}       $egress
     */
    public function __construct(
        public int $protocol,
        public string $owner,
        public array $workspace,
        public string $image,
        public array $entry,
        public array $files,
        public array $limits,
        public array $egress,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = ComputeJson::decodeObject($json, self::KEYS);

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $data = ComputeJson::assertKeys($data, self::KEYS);
        $workspace = is_array($data['workspace'] ?? null) ? $data['workspace'] : [];
        ComputeJson::assertKeys($workspace, ['kind', 'id']);
        $entry = is_array($data['entry'] ?? null) ? $data['entry'] : [];
        ComputeJson::assertKeys($entry, ['program', 'args']);
        $limits = is_array($data['limits'] ?? null) ? $data['limits'] : [];
        ComputeJson::assertKeys($limits, ['timeoutSec', 'memoryMb', 'cpu', 'pids', 'outputMb']);
        $egress = is_array($data['egress'] ?? null) ? $data['egress'] : [];
        ComputeJson::assertKeys($egress, ['allow']);

        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            if (!is_array($file)) {
                continue;
            }
            ComputeJson::assertKeys($file, ['name', 'role']);
            $files[] = [
                'name' => (string) ($file['name'] ?? ''),
                'role' => (string) ($file['role'] ?? ''),
            ];
        }

        $allow = [];
        foreach (is_array($egress['allow'] ?? null) ? $egress['allow'] : [] as $host) {
            if (!is_array($host)) {
                continue;
            }
            ComputeJson::assertKeys($host, ['host', 'port', 'ips']);
            $ips = [];
            foreach (is_array($host['ips'] ?? null) ? $host['ips'] : [] as $ip) {
                $ips[] = (string) $ip;
            }
            $allow[] = [
                'host' => (string) ($host['host'] ?? ''),
                'port' => (int) ($host['port'] ?? 0),
                'ips' => $ips,
            ];
        }

        $args = [];
        foreach (is_array($entry['args'] ?? null) ? $entry['args'] : [] as $arg) {
            $args[] = (string) $arg;
        }

        return new self(
            protocol: (int) ($data['protocol'] ?? 0),
            owner: (string) ($data['owner'] ?? ''),
            workspace: [
                'kind' => (string) ($workspace['kind'] ?? ''),
                ...isset($workspace['id']) ? ['id' => (string) $workspace['id']] : [],
            ],
            image: (string) ($data['image'] ?? ''),
            entry: [
                'program' => (string) ($entry['program'] ?? ''),
                'args' => $args,
            ],
            files: $files,
            limits: [
                'timeoutSec' => (int) ($limits['timeoutSec'] ?? 0),
                'memoryMb' => (int) ($limits['memoryMb'] ?? 0),
                'cpu' => (float) ($limits['cpu'] ?? 0),
                'pids' => (int) ($limits['pids'] ?? 0),
                'outputMb' => (int) ($limits['outputMb'] ?? 0),
            ],
            egress: ['allow' => $allow],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'protocol' => $this->protocol,
            'owner' => $this->owner,
            'workspace' => $this->workspace,
            'image' => $this->image,
            'entry' => $this->entry,
            'files' => $this->files,
            'limits' => $this->limits,
            'egress' => $this->egress,
        ];
    }
}
