<?php

declare(strict_types=1);

namespace App\Service\Iap\Exception;

/**
 * Raised when an IAP verifier is asked to validate but the deployment has not
 * configured store credentials (no Apple root certs / bundle id, no Google
 * service account). Open-source / web-only deployments hit this and the
 * controller maps it to 503 — IAP is simply unavailable there.
 */
final class IapNotConfiguredException extends IapException
{
}
