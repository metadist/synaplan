<?php

declare(strict_types=1);

namespace App\Service\Desktop\Exception;

/**
 * Thrown when a pairing exchange cannot complete for a reason other than a
 * bad/expired code — e.g. the owning user was deleted after the code was minted.
 */
final class PairingException extends \RuntimeException
{
}
