<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to mod_synascriber on Jitsi's Prosody (in-cluster HTTP, shared secret).
 */
final readonly class ProsodyClient
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $http,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function start(string $room, string $ref, string $language): array
    {
        return $this->call('POST', '/synascriber/start', ['room' => $room, 'ref' => $ref, 'language' => $language]);
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $room, string $ref): array
    {
        return $this->call('POST', '/synascriber/stop', ['room' => $room, 'ref' => $ref]);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(string $room): array
    {
        return $this->call('GET', '/synascriber/state', null, ['room' => $room]);
    }

    public function healthy(): bool
    {
        try {
            $body = $this->call('GET', '/synascriber/health');

            return true === ($body['ok'] ?? false);
        } catch (SessionException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $query
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $base = $this->settings->prosodyUrl();
        if ('' === $base) {
            throw new SessionException('not_configured', 'The Jitsi connection (Prosody URL) is not set.', 503);
        }

        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['Authorization' => 'Bearer '.$this->settings->prosodySecret()],
            'query' => $query,
        ];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, $base.$path, $options);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('synascriber: Prosody not reachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new SessionException('jitsi_unreachable', 'Jitsi did not answer. Notes were not started.', 502);
        }

        if (401 === $status) {
            throw new SessionException('jitsi_refused', 'Jitsi refused the plugin: the shared secret does not match.', 502);
        }
        if ($status >= 400) {
            $code = is_string($body['error'] ?? null) ? $body['error'] : 'jitsi_error';
            throw new SessionException($code, sprintf('Jitsi answered %d (%s).', $status, $code), 404 === $status ? 404 : 409, $body);
        }

        return $body;
    }
}
