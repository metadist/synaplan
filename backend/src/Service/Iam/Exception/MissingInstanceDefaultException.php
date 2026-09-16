<?php

declare(strict_types=1);

namespace App\Service\Iam\Exception;

/**
 * Locking a policy key that has no global BCONFIG row would pin the empty string.
 */
final class MissingInstanceDefaultException extends \InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf(
            'Cannot lock "%s": there is no instance default to lock. Set the instance value first.',
            $key,
        ));
    }
}
