<?php

declare(strict_types=1);

namespace App\Service\Egress;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Guzzle client whose handler is the shared Symfony client, so Google and
 * any other library that only accepts Guzzle still passes the egress policy.
 * This is the one Guzzle construction the inventory does not count: it is
 * the bridge, not a second stack.
 */
final class EgressGuzzleClient
{
    public static function fromHttpClient(HttpClientInterface $http): ClientInterface
    {
        $handler = static function (RequestInterface $request, array $options) use ($http) {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }
            $httpOptions = [
                'headers' => $headers,
                'body' => (string) $request->getBody(),
            ];
            if (isset($options['timeout']) && is_numeric($options['timeout'])) {
                $httpOptions['timeout'] = (float) $options['timeout'];
                $httpOptions['max_duration'] = (float) $options['timeout'];
            }
            $response = $http->request($request->getMethod(), (string) $request->getUri(), $httpOptions);

            return Create::promiseFor(new Response(
                $response->getStatusCode(),
                $response->getHeaders(false),
                $response->getContent(false),
            ));
        };

        return new Client(['handler' => $handler]);
    }
}
