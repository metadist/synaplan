<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Auth\OidcAccessPolicy;
use App\Service\Auth\OidcClaimResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class OidcAccessPolicyFixture
{
    public static function open(): OidcAccessPolicy
    {
        return self::with();
    }

    public static function with(
        string $orgCode = '',
        string $requiredRoles = '',
        string $requiredPermissions = '',
        bool $allowUserProvisioning = true,
        string $roleClaims = 'realm_access.roles,resource_access.{client_id}.roles,groups',
        string $orgClaim = 'org_code',
        string $permissionsClaim = 'permissions',
        ?LoggerInterface $logger = null,
    ): OidcAccessPolicy {
        return new OidcAccessPolicy(
            new OidcClaimResolver(),
            $logger ?? new NullLogger(),
            $orgCode,
            $orgClaim,
            $requiredRoles,
            $roleClaims,
            $requiredPermissions,
            $permissionsClaim,
            'test-client-id',
            $allowUserProvisioning ? 'true' : 'false',
        );
    }
}
