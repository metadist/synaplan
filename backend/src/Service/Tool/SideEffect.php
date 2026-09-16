<?php

declare(strict_types=1);

namespace App\Service\Tool;

enum SideEffect: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';

    public static function fromHints(?bool $readOnlyHint, ?bool $destructiveHint): self
    {
        if (true === $readOnlyHint) {
            return self::Read;
        }
        if (true === $destructiveHint) {
            return self::Destructive;
        }

        return self::Write;
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
