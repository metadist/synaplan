<?php

declare(strict_types=1);

namespace App\Service\Tool;

enum SideEffect: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';

    /**
     * The class of an MCP tool from the hints its server publishes.
     *
     * Follows the MCP specification: a tool is read-only only when the server
     * says so, and a tool that is not read-only is destructive unless the
     * server says it is not. A server that publishes no hints at all therefore
     * offers destructive tools, which ask before they run.
     */
    public static function fromHints(?bool $readOnlyHint, ?bool $destructiveHint): self
    {
        if (true === $readOnlyHint) {
            return self::Read;
        }
        if (false === $destructiveHint) {
            return self::Write;
        }

        return self::Destructive;
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
