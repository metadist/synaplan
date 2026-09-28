<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\OidcScopeResolver;
use PHPUnit\Framework\TestCase;

final class OidcScopeResolverTest extends TestCase
{
    private OidcScopeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new OidcScopeResolver();
    }

    public function testKeepsOfflineAccessWhenTheProviderAdvertisesIt(): void
    {
        $resolved = $this->resolver->resolve(
            'openid email profile offline_access',
            ['openid', 'email', 'profile', 'offline_access'],
        );

        self::assertSame('openid email profile offline_access', $resolved['scopes']);
        self::assertSame([], $resolved['replaced']);
    }

    public function testRewritesOfflineAccessToOfflineForKinde(): void
    {
        $resolved = $this->resolver->resolve(
            'openid email profile offline_access',
            ['openid', 'profile', 'email', 'offline'],
        );

        self::assertSame('openid email profile offline', $resolved['scopes']);
        self::assertSame(['offline_access'], $resolved['replaced']);
    }

    public function testKeepsConfiguredScopesWhenDiscoveryOmitsTheList(): void
    {
        $resolved = $this->resolver->resolve('openid email profile offline_access', null);

        self::assertSame('openid email profile offline_access', $resolved['scopes']);
        self::assertSame([], $resolved['replaced']);
    }

    public function testKeepsUnadvertisedScopesOtherThanTheOfflineAlias(): void
    {
        $resolved = $this->resolver->resolve(
            'openid email profile offline_access',
            ['openid', 'email'],
        );

        self::assertSame('openid email profile offline_access', $resolved['scopes']);
        self::assertSame([], $resolved['replaced']);
    }

    public function testBlankConfigurationUsesTheDefaultList(): void
    {
        $resolved = $this->resolver->resolve('   ', ['openid', 'offline']);

        self::assertSame('openid email profile offline', $resolved['scopes']);
        self::assertSame(['offline_access'], $resolved['replaced']);
    }

    public function testHonoursAnExplicitListWithoutOfflineAccess(): void
    {
        $resolved = $this->resolver->resolve(
            'openid profile email',
            ['openid', 'profile', 'email', 'offline'],
        );

        self::assertSame('openid profile email', $resolved['scopes']);
        self::assertSame([], $resolved['replaced']);
    }

    public function testRefreshScopeRecognisesBothNames(): void
    {
        self::assertTrue($this->resolver->requestsRefreshToken('openid offline'));
        self::assertTrue($this->resolver->requestsRefreshToken('openid offline_access'));
        self::assertFalse($this->resolver->requestsRefreshToken('openid email profile'));
    }

    public function testInvalidScopeNamesTheRefusedPermission(): void
    {
        $failure = $this->resolver->describeAuthorizationError(
            'invalid_scope',
            "The OAuth 2.0 Client is not allowed to request scope 'offline_access'.",
        );

        self::assertSame(OidcScopeResolver::CODE_INVALID_SCOPE, $failure['code']);
        self::assertSame('offline_access', $failure['scope']);
        self::assertStringContainsString('offline_access', $failure['message']);
        self::assertStringContainsString('Nothing was signed in.', $failure['message']);
    }

    public function testInvalidScopeWithoutANamedPermissionStaysGeneric(): void
    {
        $failure = $this->resolver->describeAuthorizationError('invalid_scope', 'malformed');

        self::assertSame(OidcScopeResolver::CODE_INVALID_SCOPE, $failure['code']);
        self::assertNull($failure['scope']);
        self::assertStringContainsString('Nothing was signed in.', $failure['message']);
    }

    public function testAccessDeniedIsACancellation(): void
    {
        $failure = $this->resolver->describeAuthorizationError('access_denied', 'User declined');

        self::assertSame(OidcScopeResolver::CODE_ACCESS_DENIED, $failure['code']);
        self::assertSame('Sign-in was cancelled. Nothing was signed in.', $failure['message']);
    }
}
