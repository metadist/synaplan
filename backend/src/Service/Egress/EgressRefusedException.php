<?php

declare(strict_types=1);

namespace App\Service\Egress;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown in `enforce` mode before any socket opens. Callers that already
 * catch a transport failure map it to their "service not reachable" sentence.
 * The message names the host and port only.
 */
final class EgressRefusedException extends \RuntimeException implements TransportExceptionInterface
{
    public function __construct(public readonly EgressDestination $destination)
    {
        parent::__construct('Outbound traffic to '.$destination->label().' is not allowed.');
    }
}
