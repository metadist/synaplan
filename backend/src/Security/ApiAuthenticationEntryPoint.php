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
 * the client gets a deterministic JSON 401 payload that matches the
 * {error, code} shape used by the rest of the API (e.g. the
 * SubscriptionController 401 branches).
 */
class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            [
                'error' => 'Authentication required',
                'code' => 'UNAUTHENTICATED',
            ],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
