<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two conversions league needs: a Symfony request as PSR-7, and its
 * PSR-7 answer back.
 */
final class Psr7
{
    /**
     * @param array<string, mixed>|null $body replaces the parsed body
     */
    public static function request(Request $request, ?array $body = null): ServerRequestInterface
    {
        $psr = new ServerRequest($request->getMethod(), $request->getUri(), $request->headers->all(), $request->getContent());

        return $psr
            ->withQueryParams($request->query->all())
            ->withParsedBody($body ?? $request->request->all());
    }

    public static function response(): ResponseInterface
    {
        return new PsrResponse();
    }

    public static function toSymfony(ResponseInterface $response): Response
    {
        $out = new Response((string) $response->getBody(), $response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            $out->headers->set($name, $values);
        }

        return $out;
    }
}
