<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testGetRolesReturnsBaseUserRole(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
    }

    public function testGetRolesReturnsAdminRoles(): void
    {
        $user = new User();
        $user->setUserLevel('ADMIN');

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_PRO', $roles);
        $this->assertContains('ROLE_BUSINESS', $roles);
    }

    public function testGetRolesReturnsProRole(): void
    {
        $user = new User();
        $user->setUserLevel('PRO');

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_PRO', $roles);
        $this->assertNotContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesReturnsBusinessRoles(): void
    {
        $user = new User();
        $user->setUserLevel('BUSINESS');

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_PRO', $roles);
        $this->assertContains('ROLE_BUSINESS', $roles);
        $this->assertNotContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesMapsOidcAdminRole(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setUserDetails([
            'oidc_roles' => ['admin'],
        ]);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesMapsKeycloakRealmAdminRole(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setUserDetails([
            'oidc_roles' => ['realm-admin'],
        ]);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesMapsMultipleOidcRoles(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setUserDetails([
            'oidc_roles' => ['admin', 'pro-user', 'business-user'],
        ]);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_PRO', $roles);
        $this->assertContains('ROLE_BUSINESS', $roles);
    }

    public function testGetRolesIgnoresUnmappedOidcRoles(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setUserDetails([
            'oidc_roles' => ['unknown-role', 'custom-role'],
        ]);

        $roles = $user->getRoles();

        // Should only have base ROLE_USER
        $this->assertContains('ROLE_USER', $roles);
        $this->assertCount(1, $roles);
    }

    public function testGetRolesCombinesInternalAndOidcRoles(): void
    {
        $user = new User();
        $user->setUserLevel('PRO'); // Internal PRO
        $user->setUserDetails([
            'oidc_roles' => ['business-user'], // OIDC BUSINESS
        ]);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_PRO', $roles);
        $this->assertContains('ROLE_BUSINESS', $roles);
        $this->assertNotContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesDoesNotDuplicateRoles(): void
    {
        $user = new User();
        $user->setUserLevel('ADMIN'); // Internal ADMIN
        $user->setUserDetails([
            'oidc_roles' => ['admin'], // OIDC admin (same role)
        ]);

        $roles = $user->getRoles();

        // array_unique should prevent duplicates
        $roleCount = count($roles);
        $uniqueRoleCount = count(array_unique($roles));

        $this->assertEquals($roleCount, $uniqueRoleCount, 'Roles should not contain duplicates');
    }

    public function testGetRolesHandlesEmptyOidcRoles(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setUserDetails([
            'oidc_roles' => [],
        ]);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertCount(1, $roles);
    }

    public function testGetRolesHandlesMissingOidcRoles(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setUserDetails([
            'other_data' => 'value',
            // No oidc_roles key
        ]);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertCount(1, $roles);
    }

    public function testGetRolesIsCaseInsensitive(): void
    {
        $user = new User();
        $user->setUserLevel('NEW');
        $user->setUserDetails([
            'oidc_roles' => ['Admin', 'REALM-ADMIN', 'Pro-User'], // Mixed case
        ]);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_PRO', $roles);
    }
}
