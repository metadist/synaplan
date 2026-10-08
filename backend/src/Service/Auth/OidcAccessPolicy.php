<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Log\LoggerInterface;

/**
 * Instance-level admission rules for OIDC identities (organization, role,
 * permission, provisioning), applied to claims from a locally validated JWT.
 *
 * Every rule is optional; with none configured the policy admits every
 * authenticated identity, which is the behaviour before these settings
 * existed. Roles: any one listed role admits. Permissions: all listed
 * permissions are required.
 */
final readonly class OidcAccessPolicy
{
    private string $orgCode;

    /** @var list<string> */
    private array $orgClaimPath;

    /** @var list<string> lower-cased */
    private array $requiredRoles;

    /** @var list<list<string>> */
    private array $roleClaimPaths;

    /** @var list<string> */
    private array $requiredPermissions;

    /** @var list<string> */
    private array $permissionsClaimPath;

    private bool $allowUserProvisioning;

    /**
     * @param string $allowUserProvisioning "true"/"false" (also 1/0, on/off, yes/no). Empty means the
     *                                      default (true); an unreadable value fails closed (false).
     */
    public function __construct(
        private OidcClaimResolver $claimResolver,
        private LoggerInterface $logger,
        string $orgCode,
        string $orgClaim,
        string $requiredRoles,
        string $roleClaims,
        string $requiredPermissions,
        string $permissionsClaim,
        string $clientId,
        string $allowUserProvisioning = 'true',
    ) {
        $this->allowUserProvisioning = '' === trim($allowUserProvisioning)
            || true === filter_var($allowUserProvisioning, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        $this->orgCode = trim($orgCode);
        $this->orgClaimPath = $this->singlePath($orgClaim, 'org_code', $clientId);
        $this->requiredRoles = array_map('strtolower', self::csv($requiredRoles));
        $this->roleClaimPaths = $claimResolver->paths($roleClaims, $clientId);
        $this->requiredPermissions = self::csv($requiredPermissions);
        $this->permissionsClaimPath = $this->singlePath($permissionsClaim, 'permissions', $clientId);
    }

    public function hasRestrictions(): bool
    {
        return '' !== $this->orgCode || [] !== $this->requiredRoles || [] !== $this->requiredPermissions;
    }

    public function orgCode(): ?string
    {
        return '' !== $this->orgCode ? $this->orgCode : null;
    }

    public function allowsUserProvisioning(): bool
    {
        return $this->allowUserProvisioning;
    }

    /**
     * @param array<string, mixed> $claims claims of a JWT whose signature, iss, exp and aud were verified
     *
     * @throws OidcAccessDeniedException
     */
    public function assertAllowed(array $claims, string $context): void
    {
        if ('' !== $this->orgCode) {
            $org = $this->claimResolver->resolve($claims, $this->orgClaimPath);
            if (null === $org || '' === $org || [] === $org) {
                $this->deny(OidcAccessDenialReason::OrgClaimMissing, $claims, $context);
            }
            if (!$this->orgMatches($org)) {
                $this->deny(OidcAccessDenialReason::OrgMismatch, $claims, $context);
            }
        }

        if ([] !== $this->requiredRoles) {
            $roles = array_map('strtolower', $this->claimResolver->roleValues($claims, $this->roleClaimPaths));
            if ([] === array_intersect($this->requiredRoles, $roles)) {
                $this->deny(OidcAccessDenialReason::RoleMissing, $claims, $context);
            }
        }

        if ([] !== $this->requiredPermissions) {
            $granted = $this->claimResolver->values($claims, [$this->permissionsClaimPath]);
            if ([] !== array_diff($this->requiredPermissions, $granted)) {
                $this->deny(OidcAccessDenialReason::PermissionMissing, $claims, $context);
            }
        }
    }

    /**
     * An opaque access token cannot be inspected locally, so restrictions on
     * its claims cannot be proven.
     *
     * @throws OidcAccessDeniedException when restrictions are configured
     */
    public function assertOpaqueTokenAllowed(string $context): void
    {
        if ($this->hasRestrictions()) {
            $this->deny(OidcAccessDenialReason::OpaqueToken, [], $context);
        }
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws OidcAccessDeniedException when automatic account creation is off
     */
    public function assertProvisioningAllowed(array $claims, string $context): void
    {
        if (!$this->allowUserProvisioning) {
            $this->deny(OidcAccessDenialReason::ProvisioningDisabled, $claims, $context);
        }
    }

    /**
     * Accepts a string claim, a list of org codes, or a map keyed by org code
     * (Keycloak's `organization` claim shape).
     */
    private function orgMatches(mixed $org): bool
    {
        if (is_string($org) || is_int($org)) {
            return (string) $org === $this->orgCode;
        }
        if (!is_array($org)) {
            return false;
        }
        if (array_is_list($org)) {
            return in_array($this->orgCode, $org, true);
        }

        return array_key_exists($this->orgCode, $org);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws OidcAccessDeniedException
     */
    private function deny(OidcAccessDenialReason $reason, array $claims, string $context): never
    {
        $sub = $claims['sub'] ?? null;
        $this->logger->warning('OIDC access denied by instance policy', [
            'reason' => $reason->value,
            'context' => $context,
            'sub' => is_string($sub) ? $sub : null,
        ]);

        throw new OidcAccessDeniedException($reason);
    }

    /**
     * @return list<string>
     */
    private function singlePath(string $claim, string $default, string $clientId): array
    {
        $paths = $this->claimResolver->paths('' !== trim($claim) ? $claim : $default, $clientId);

        return $paths[0] ?? [$default];
    }

    /**
     * @return list<string>
     */
    private static function csv(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => '' !== $item,
        ));
    }
}
