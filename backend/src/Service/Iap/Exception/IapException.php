<?php

declare(strict_types=1);

namespace App\Service\Iap\Exception;

/**
 * MOBILE-APP SEAM (Epic 5.4): base type for every IAP validation failure, so
 * callers can catch the whole family with one clause.
 */
abstract class IapException extends \RuntimeException
{
}
