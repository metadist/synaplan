<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\OidcAccessDenialReason;
use App\Service\Auth\OidcAccessDeniedException;
use App\Service\Auth\OidcAccessPolicy;
use App\Service\Auth\OidcClaimResolver;
use App\Tests\Support\OidcAccessPolicyFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class OidcAccessPolicyTest extends TestCase
{
    public function testWithoutRestrictionsEveryAuthenticatedIdentityIsAdmitted(): void
    {
        $policy = OidcAccessPolicyFixture::open();

        self::assertFalse($policy->hasRestrictions());
        self::assertNull($policy->orgCode());
        $policy->assertAllowed(['sub' => 'anyone'], 'login');
        $policy->assertOpaqueTokenAllowed('login');
        $policy->assertProvisioningAllowed(['sub' => 'anyone'], 'provisioning');
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function matchingOrgClaims(): iterable
    {
        yield 'Kinde string' => ['org_acme'];
        yield 'list' => [['org_other', 'org_acme']];
        yield 'Keycloak map' => [['org_acme' => ['id' => 'x']]];
    }

    #[DataProvider('matchingOrgClaims')]
    public function testOrganizationClaimShapesThatMatch(mixed $org): void
    {
        $policy = OidcAccessPolicyFixture::with(orgCode: 'org_acme');

        $policy->assertAllowed(['sub' => 's', 'org_code' => $org], 'login');

        self::assertSame('org_acme', $policy->orgCode());
    }

    public function testWrongOrganizationIsDenied(): void
    {
        $this->assertDenied(
            OidcAccessPolicyFixture::with(orgCode: 'org_acme'),
            ['sub' => 's', 'org_code' => 'org_other'],
            OidcAccessDenialReason::OrgMismatch,
        );
    }

    public function testMissingOrganizationClaimIsDenied(): void
    {
        $this->assertDenied(
            OidcAccessPolicyFixture::with(orgCode: 'org_acme'),
            ['sub' => 's'],
            OidcAccessDenialReason::OrgClaimMissing,
        );
    }

    public function testCustomOrganizationClaimPath(): void
    {
        $policy = OidcAccessPolicyFixture::with(orgCode: 'acme', orgClaim: 'organization');

        $policy->assertAllowed(['sub' => 's', 'organization' => ['acme' => []]], 'login');
        $this->addToAssertionCount(1);
    }

    public function testAnyOneRequiredRoleAdmitsCaseInsensitively(): void
    {
        $policy = OidcAccessPolicyFixture::with(requiredRoles: 'Admin, editor');

        $policy->assertAllowed(['sub' => 's', 'realm_access' => ['roles' => ['EDITOR']]], 'login');
        $this->addToAssertionCount(1);
    }

    public function testKindeRoleObjectsAreRead(): void
    {
        $policy = OidcAccessPolicyFixture::with(requiredRoles: 'admin', roleClaims: 'roles');

        $policy->assertAllowed(['sub' => 's', 'roles' => [['id' => '1', 'key' => 'admin', 'name' => 'Admin']]], 'login');
        $this->addToAssertionCount(1);
    }

    public function testMissingRoleIsDenied(): void
    {
        $this->assertDenied(
            OidcAccessPolicyFixture::with(requiredRoles: 'admin'),
            ['sub' => 's', 'realm_access' => ['roles' => ['user']]],
            OidcAccessDenialReason::RoleMissing,
        );
    }

    public function testNoRoleClaimAtAllIsDenied(): void
    {
        $this->assertDenied(
            OidcAccessPolicyFixture::with(requiredRoles: 'admin'),
            ['sub' => 's'],
            OidcAccessDenialReason::RoleMissing,
        );
    }

    public function testAllRequiredPermissionsAdmit(): void
    {
        $policy = OidcAccessPolicyFixture::with(requiredPermissions: 'read:docs,use:synaplan');

        $policy->assertAllowed(['sub' => 's', 'permissions' => ['use:synaplan', 'read:docs', 'other']], 'login');
        $this->addToAssertionCount(1);
    }

    public function testOneMissingPermissionIsDenied(): void
    {
        $this->assertDenied(
            OidcAccessPolicyFixture::with(requiredPermissions: 'read:docs,use:synaplan'),
            ['sub' => 's', 'permissions' => ['read:docs']],
            OidcAccessDenialReason::PermissionMissing,
        );
    }

    public function testMissingPermissionsClaimIsDenied(): void
    {
        $this->assertDenied(
            OidcAccessPolicyFixture::with(requiredPermissions: 'use:synaplan'),
            ['sub' => 's'],
            OidcAccessDenialReason::PermissionMissing,
        );
    }

    public function testOpaqueTokenIsDeniedOnlyWhenRestricted(): void
    {
        OidcAccessPolicyFixture::with(allowUserProvisioning: false)->assertOpaqueTokenAllowed('login');

        $this->expectException(OidcAccessDeniedException::class);
        $this->expectExceptionMessage(OidcAccessDenialReason::OpaqueToken->value);
        OidcAccessPolicyFixture::with(requiredPermissions: 'x')->assertOpaqueTokenAllowed('login');
    }

    public function testProvisioningCanBeTurnedOffIndependently(): void
    {
        $policy = OidcAccessPolicyFixture::with(allowUserProvisioning: false);

        self::assertFalse($policy->hasRestrictions());
        self::assertFalse($policy->allowsUserProvisioning());
        $this->expectException(OidcAccessDeniedException::class);
        $policy->assertProvisioningAllowed(['sub' => 's'], 'provisioning');
    }

    public function testDenialLogsReasonAndSubjectButNoClaimValues(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('OIDC access denied by instance policy', [
                'reason' => 'org_mismatch',
                'context' => 'bearer',
                'sub' => 'subject-1',
            ]);

        try {
            OidcAccessPolicyFixture::with(orgCode: 'org_acme', logger: $logger)
                ->assertAllowed(['sub' => 'subject-1', 'org_code' => 'org_secret_other', 'email' => 'a@b.c'], 'bearer');
            self::fail('Expected a denial');
        } catch (OidcAccessDeniedException $e) {
            self::assertStringNotContainsString('org_secret_other', $e->getMessage());
        }
    }

    public function testNumericOrganizationClaimMatches(): void
    {
        OidcAccessPolicyFixture::with(orgCode: '4711')->assertAllowed(['sub' => 's', 'org_code' => 4711], 'login');
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provisioningValues(): iterable
    {
        yield 'unset or blank keeps the default' => ['', true];
        yield 'true' => ['true', true];
        yield 'one' => ['1', true];
        yield 'false' => ['false', false];
        yield 'off' => ['off', false];
        yield 'typo fails closed' => ['flase', false];
    }

    #[DataProvider('provisioningValues')]
    public function testProvisioningSwitchParsing(string $raw, bool $expected): void
    {
        $policy = new OidcAccessPolicy(
            new OidcClaimResolver(),
            new NullLogger(),
            '',
            'org_code',
            '',
            'realm_access.roles',
            '',
            'permissions',
            'client',
            $raw,
        );

        self::assertSame($expected, $policy->allowsUserProvisioning());
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertDenied(OidcAccessPolicy $policy, array $claims, OidcAccessDenialReason $reason): void
    {
        try {
            $policy->assertAllowed($claims, 'login');
            self::fail(sprintf('Expected denial with reason %s', $reason->value));
        } catch (OidcAccessDeniedException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
