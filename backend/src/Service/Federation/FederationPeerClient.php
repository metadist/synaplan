<?php

declare(strict_types=1);

namespace App\Service\Federation;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls another Synaplan. Redirects are not followed.
 */
final class FederationPeerClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private FederationUrlGuard $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function wellKnown(string $origin): array
    {
        return $this->get($origin.'/.well-known/synaplan-federation');
    }

    /**
     * @return array<string, mixed>
     */
    public function invite(string $origin, string $token): array
    {
        return $this->get($origin.'/api/v1/federation/invites/'.$token);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function connect(string $apiBase, array $body): array
    {
        return $this->post(rtrim($apiBase, '/').'/connect', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        $this->urls->assertOutbound($url);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 8,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (TransportExceptionInterface) {
            throw new FederationException('peer_unreachable', 'The other server did not answer. Nothing was connected.', 502);
        }

        return $this->decode($response->getStatusCode(), $response->getContent(false));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $body): array
    {
        $this->urls->assertOutbound($url);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'timeout' => 8,
                'max_redirects' => 0,
                'json' => $body,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (TransportExceptionInterface) {
            throw new FederationException('peer_unreachable', 'The other server did not answer. Nothing was connected.', 502);
        }

        return $this->decode($response->getStatusCode(), $response->getContent(false));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(int $status, string $raw): array
    {
        if ($status >= 300 && $status < 400) {
            throw new FederationException('peer_rejected', 'The other server sent a redirect, which we do not follow.', 502);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new FederationException('peer_rejected', 'The other server sent a response we could not read.', 502);
        }
        if ($status >= 400) {
            $message = $data['message'] ?? null;
            throw new FederationException('peer_rejected', is_string($message) && '' !== $message ? $message : 'The other server refused the request.', $status >= 500 ? 502 : 409);
        }

        return $data;
    }
}
