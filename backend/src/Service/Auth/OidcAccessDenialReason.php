<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Server-side reason an OIDC identity was refused. Logged only — the browser
 * always gets the same generic answer so the policy cannot be probed.
 */
enum OidcAccessDenialReason: string
{
    case OrgClaimMissing = 'org_claim_missing';
    case OrgMismatch = 'org_mismatch';
    case RoleMissing = 'role_missing';
    case PermissionMissing = 'permission_missing';
    case OpaqueToken = 'opaque_token';
    case ProvisioningDisabled = 'provisioning_disabled';
}
