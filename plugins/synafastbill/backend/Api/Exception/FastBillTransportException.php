<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Api\Exception;

/**
 * Raised on a transport-level failure (DNS, TLS, timeout) before any FastBill
 * response is received.
 */
final class FastBillTransportException extends FastBillException
{
}
