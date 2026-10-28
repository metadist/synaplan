<?php

declare(strict_types=1);

namespace App\AI\Messages;

use Symfony\Component\HttpFoundation\Request;

/**
 * `x-synaplan-effort` on POST /v1/messages. `auto` lets the gateway rate
 * the question and start a research on the caller's behalf; anything
 * else, or no header, is `answer`: the turn runs as it always did.
 *
 * The same header name travels back on the response with the rating the
 * sorter gave (`research`, `lookup`, `answer`), so a client can tell "the
 * gateway decided to answer" from "the gateway never looked".
 */
final class GatewayEffortHeader
{
    public const HEADER = 'x-synaplan-effort';
    public const RUN_HEADER = 'x-synaplan-research-run';
    public const LIMIT_HEADER = 'x-synaplan-research-limit';

    public const AUTO = 'auto';
    public const ANSWER = 'answer';

    public static function present(Request $request): bool
    {
        return '' !== trim((string) $request->headers->get(self::HEADER, ''));
    }

    /** @return self::AUTO|self::ANSWER */
    public static function fromRequest(Request $request): string
    {
        return self::AUTO === strtolower(trim((string) $request->headers->get(self::HEADER, ''))) ? self::AUTO : self::ANSWER;
    }
}
