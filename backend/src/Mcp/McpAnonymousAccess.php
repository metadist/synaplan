<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\HttpFoundation\Request;

/**
 * Decides which unauthenticated MCP requests may reach the server.
 *
 * Registries such as Glama need `initialize` and `tools/list` without a key.
 * Every other method, including `tools/call`, stays closed: the controller
 * answers 401 with the RFC 9728 challenge.
 */
final class McpAnonymousAccess
{
    /** @var list<string> */
    private const METHODS = [
        'initialize',
        'notifications/initialized',
        'ping',
        'tools/list',
    ];

    public function allows(Request $request): bool
    {
        if ($request->isMethod('DELETE')) {
            return true;
        }

        if (!$request->isMethod('POST')) {
            return false;
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return $this->payloadAllowed($payload);
    }

    private function payloadAllowed(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        if (array_is_list($payload)) {
            if ([] === $payload) {
                return false;
            }

            foreach ($payload as $message) {
                if (!$this->messageAllowed($message)) {
                    return false;
                }
            }

            return true;
        }

        return $this->messageAllowed($payload);
    }

    private function messageAllowed(mixed $message): bool
    {
        if (!is_array($message)) {
            return false;
        }

        $method = $message['method'] ?? null;

        return is_string($method) && in_array($method, self::METHODS, true);
    }
}
