<?php

declare(strict_types=1);

namespace App\Service\Billing;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\CurlClient;
use Stripe\Util\CaseInsensitiveArray;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Stripe's own cURL client, with the socket handed to the shared HTTP client.
 * Stripe still builds the URL, the body and the signature headers. A file
 * upload stays on cURL: Symfony's body option is a string, and a multipart
 * file is an array.
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
            return parent::executeRequestWithRetries($opts, $absUrl, $maxNetworkRetries);
        }

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
        $timeout = isset($opts[\CURLOPT_TIMEOUT]) ? (float) $opts[\CURLOPT_TIMEOUT] : 80.0;

        try {
            $response = $this->http->request($method, $absUrl, [
                'headers' => $headers,
                'body' => \is_string($body) ? $body : '',
                'timeout' => $timeout,
                'max_duration' => $timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new ApiConnectionException('Could not connect to Stripe ('.$absUrl.').', 0, $e);
        }

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

        return [$content, $status, $parsed];
    }
}
