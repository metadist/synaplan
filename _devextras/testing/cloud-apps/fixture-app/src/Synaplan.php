<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * Calls Synaplan the way an app does: on the internal address, with the
 * instance credential in `X-Instance-Id` / `X-Instance-Secret`.
 */
final class Synaplan
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly string $base,
        private readonly string $instanceId,
        private readonly string $secret,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function from(array $state): self
    {
        $instance = is_array($state['instance'] ?? null) ? $state['instance'] : [];

        return new self(
            (string) ($state['synaplan_url'] ?? ''),
            (string) ($instance['id'] ?? ''),
            (string) ($instance['secret'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers extra headers, e.g. an admin key for registration
     *
     * @return array{status: int, body: array<mixed>}
     */
    public function json(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headers(['Accept: application/json', 'Content-Type: application/json'], $headers),
        ];
        if (null !== $body) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        [$status, $raw] = $this->send($path, $options);
        $decoded = json_decode($raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * The bytes behind a download path Synaplan handed out, or null.
     */
    public function download(string $path): ?string
    {
        [$status, $raw] = $this->send($path, [CURLOPT_HTTPHEADER => $this->headers([], [])]);

        return 200 === $status ? $raw : null;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: array<mixed>}
     */
    public function upload(string $path, array $fields, string $bytes, string $name, string $mime): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'fixture');
        if (false === $temp) {
            return ['status' => 0, 'body' => []];
        }
        file_put_contents($temp, $bytes);
        try {
            [$status, $raw] = $this->send($path, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $this->headers(['Accept: application/json'], []),
                CURLOPT_POSTFIELDS => $fields + ['file' => new \CURLFile($temp, $mime, $name)],
            ]);
        } finally {
            unlink($temp);
        }
        $decoded = json_decode($raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param list<string>          $base
     * @param array<string, string> $extra
     *
     * @return list<string>
     */
    private function headers(array $base, array $extra): array
    {
        $headers = $base;
        if ('' !== $this->instanceId) {
            $headers[] = 'X-Instance-Id: '.$this->instanceId;
            $headers[] = 'X-Instance-Secret: '.$this->secret;
        }
        foreach ($extra as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        return $headers;
    }

    /**
     * @param array<int, mixed> $options
     *
     * @return array{0: int, 1: string}
     */
    private function send(string $path, array $options): array
    {
        $curl = curl_init($this->base.'/'.ltrim($path, '/'));
        if (false === $curl) {
            return [0, ''];
        }
        curl_setopt_array($curl, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return [$status, is_string($raw) ? $raw : ''];
    }
}
