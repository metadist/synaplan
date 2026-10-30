<?php

declare(strict_types=1);

namespace App\Service\Billing;

use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\HttpClient\CurlClient;
use Stripe\Util\CaseInsensitiveArray;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe's own cURL client, with the socket handed to the shared HTTP client.
 * Stripe still builds the URL, the body and the signature headers, including
 * the idempotency key, and this class keeps Stripe's retry loop around that
 * request. A multipart file body is refused: it is not sent on a second client.
 */
final class StripeEgressClient extends CurlClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct();
    }

    /**
     * @param array<int, mixed> $opts
     *
     * @return array{0: string, 1: int, 2: CaseInsensitiveArray}
     */
    public function executeRequestWithRetries($opts, $absUrl, $maxNetworkRetries = null)
    {
        $body = $opts[\CURLOPT_POSTFIELDS] ?? null;
        if (null !== $body && !\is_string($body)) {
            throw new UnexpectedValueException('Stripe file uploads are not sent through the shared HTTP client.');
        }

        $numRetries = 0;
        $lastError = null;
        while (true) {
            $errno = 0;
            $rcode = 0;
            $rbody = false;
            $rheaders = new CaseInsensitiveArray();
            try {
                [$rbody, $rcode, $rheaders] = $this->sendOnce($opts, $absUrl);
            } catch (TransportExceptionInterface $e) {
                $errno = \CURLE_COULDNT_CONNECT;
                $lastError = $e;
            }
            if ($this->sdk('shouldRetry', $errno, $rcode, $rheaders, $numRetries, $maxNetworkRetries)) {
                ++$numRetries;
                usleep((int) ($this->sdk('sleepTime', $numRetries) * 1_000_000));
                continue;
            }
            if (false === $rbody) {
                throw new ApiConnectionException('Could not connect to Stripe ('.$absUrl.').', 0, $lastError);
            }

            return [$rbody, $rcode, $rheaders];
        }
    }

    /**
     * @param array<int, mixed> $opts
     *
     * @return array{0: string, 1: int, 2: CaseInsensitiveArray}
     */
    private function sendOnce(array $opts, string $absUrl): array
    {
        $method = 'GET';
        if (!empty($opts[\CURLOPT_POST])) {
            $method = 'POST';
        } elseif (isset($opts[\CURLOPT_CUSTOMREQUEST]) && \is_string($opts[\CURLOPT_CUSTOMREQUEST])) {
            $method = strtoupper($opts[\CURLOPT_CUSTOMREQUEST]);
        }
        $headers = [];
        foreach ($opts[\CURLOPT_HTTPHEADER] ?? [] as $line) {
            if (!\is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            if ('Expect' === trim($name) && '' === trim($value)) {
                continue;
            }
            $headers[trim($name)] = trim($value);
        }
        $body = $opts[\CURLOPT_POSTFIELDS] ?? null;
        $timeout = isset($opts[\CURLOPT_TIMEOUT]) ? (float) $opts[\CURLOPT_TIMEOUT] : 80.0;
        $response = $this->http->request($method, $absUrl, [
            'headers' => $headers,
            'body' => \is_string($body) ? $body : '',
            'timeout' => $timeout,
            'max_duration' => $timeout,
        ]);
        $parsed = new CaseInsensitiveArray();
        $raw = $response->getInfo('response_headers');
        if (\is_array($raw)) {
            foreach ($raw as $line) {
                if (!\is_string($line) || !str_contains($line, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $line, 2);
                $parsed[trim($name)] = trim($value);
            }
        }

        return [$response->getContent(false), $response->getStatusCode(), $parsed];
    }

    private function sdk(string $method, mixed ...$args): mixed
    {
        $reflected = new \ReflectionMethod(CurlClient::class, $method);

        return $reflected->invoke($this, ...$args);
    }
}
