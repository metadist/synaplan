<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OAuth\Server\ClientAuthenticationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answers Synaplan's OAuth server endpoints share: nothing a client is
 * sent may be cached, and a caller that did not authenticate as the client
 * is told so in the way its kind of request expects.
 */
trait OAuthEndpointResponses
{
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    /**
     * @param bool $bearer whether the assertion was expected as bearer token (RFC 6750) and not in the form
     */
    private function clientAuthenticationFailed(ClientAuthenticationException $e, bool $bearer = false): JsonResponse
    {
        if ($e->isThrottled()) {
            return $this->noStore(new JsonResponse(
                ['error' => 'too_many_requests', 'error_description' => $e->getMessage()],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $e->retryAfter],
            ));
        }
        if ($bearer) {
            return $this->noStore(new JsonResponse(
                ['error' => 'invalid_token', 'error_description' => 'A client assertion of this client is required.'],
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer error="invalid_token"'],
            ));
        }

        return $this->noStore(new JsonResponse(
            ['error' => 'invalid_client', 'error_description' => $e->getMessage()],
            Response::HTTP_UNAUTHORIZED,
        ));
    }
}
