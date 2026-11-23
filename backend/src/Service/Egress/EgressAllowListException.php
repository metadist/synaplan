<?php

declare(strict_types=1);

namespace App\Service\Egress;

final class EgressAllowListException extends \RuntimeException
{
    public const INVALID_RULE = 'invalid_rule';
    public const NOT_SAVED = 'not_saved';

    private function __construct(string $code)
    {
        parent::__construct($code);
    }

    public static function invalidRule(): self
    {
        return new self(self::INVALID_RULE);
    }

    public static function notSaved(): self
    {
        return new self(self::NOT_SAVED);
    }

    public function isInvalidRule(): bool
    {
        return self::INVALID_RULE === $this->getMessage();
    }
}
