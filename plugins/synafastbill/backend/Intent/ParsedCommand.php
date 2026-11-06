<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Intent;

/**
 * A structured, validated command derived from a user's prompt.
 */
final readonly class ParsedCommand
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public CommandType $type,
        public array $params = [],
        public string $rawText = '',
    ) {
    }

    public function stringParam(string $key, string $default = ''): string
    {
        $value = $this->params[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }
}
