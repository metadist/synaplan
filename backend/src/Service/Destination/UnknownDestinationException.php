<?php

declare(strict_types=1);

namespace App\Service\Destination;

final class UnknownDestinationException extends \RuntimeException
{
    public function __construct(string $destinationId)
    {
        parent::__construct(sprintf('Unknown destination "%s"', $destinationId));
    }
}
