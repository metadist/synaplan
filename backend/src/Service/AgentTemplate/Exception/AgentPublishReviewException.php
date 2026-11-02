<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate\Exception;

final class AgentPublishReviewException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';
    public const SHARING_OFF = 'sharing_off';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'That review request is not here.');
    }

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN, 'Someone else has to decide this. You cannot approve your own assistant.');
    }

    public static function sharingOff(): self
    {
        return new self(self::SHARING_OFF, 'Sharing with everyone is turned off, so this assistant was not shared.');
    }
}
