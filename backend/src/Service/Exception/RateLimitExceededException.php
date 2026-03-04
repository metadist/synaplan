<?php

declare(strict_types=1);

namespace App\Service\Exception;

final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(string $action, int $used, int $limit, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Rate limit exceeded for %s. Used: %d/%d', $action, $used, $limit),
            429,
            $previous,
        );
    }
}
