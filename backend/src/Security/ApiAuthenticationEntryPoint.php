<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authentication entry point for the stateless JSON API firewalls
 * (`api` and `openai_compat`).
 *
 * Without a custom entry point, Symfony's security ExceptionListener turns
 * an anonymous request to a protected route into an uncaught
 * `HttpException` ("Full authentication is required…"), which Symfony's
 * HttpKernel ErrorListener then logs at ERROR level on the `request`
 * channel. For a JSON API every anonymous/expired-token request would
 * generate a noisy stack trace that isn't actually an application error.
 *
 * By returning a response here directly, the security listener stops
 * escalating the event into an exception, no stack trace is emitted, and
 * the client gets a deterministic JSON 401 payload.
 *
 * The payload shape follows the API the caller is talking to. A missing or
 * misspelled key is the first thing a Claude Code or OpenAI SDK user hits, and
 * both parse the error envelope of their own protocol — Synaplan's native
 * `{error, code}` shape would surface as an unhelpful parse failure instead of
 * "your key is wrong".
 */
class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    private const MESSAGE = 'Authentication required. Send a Synaplan API key as `x-api-key: sk_…` or `Authorization: Bearer sk_…`.';

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/v1/messages')) {
            return new JsonResponse([
                'type' => 'error',
                'error' => [
                    'type' => 'authentication_error',
                    'message' => self::MESSAGE,
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (str_starts_with($path, '/v1/')) {
            return new JsonResponse([
                'error' => [
                    'message' => self::MESSAGE,
                    'type' => 'authentication_error',
                    'param' => null,
                    'code' => 'invalid_api_key',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(
            [
                'error' => 'Authentication required',
                'code' => 'UNAUTHENTICATED',
            ],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
