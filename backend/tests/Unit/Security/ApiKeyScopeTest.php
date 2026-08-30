<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiKeyScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiKeyScopeTest extends TestCase
{
    public function testEmptyScopesAreNotRestricted(): void
    {
        self::assertFalse(ApiKeyScope::isRestricted([]));
    }

    public function testWildcardIsNotRestricted(): void
    {
        self::assertFalse(ApiKeyScope::isRestricted(['*']));
        self::assertFalse(ApiKeyScope::isRestricted(['desktop:messages', '*']));
    }

    /**
     * @param list<string> $scopes
     */
    #[DataProvider('legacyWebhookLists')]
    public function testLegacyWebhookOnlyListsAreNotRestricted(array $scopes): void
    {
        self::assertFalse(ApiKeyScope::isRestricted($scopes));
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function legacyWebhookLists(): iterable
    {
        yield 'webhooks:*' => [['webhooks:*']];
        yield 'webhooks:email' => [['webhooks:email']];
        yield 'webhooks:whatsapp' => [['webhooks:whatsapp']];
        yield 'both webhook scopes' => [['webhooks:email', 'webhooks:whatsapp']];
    }

    public function testDesktopScopesAreRestricted(): void
    {
        self::assertTrue(ApiKeyScope::isRestricted(['desktop:messages']));
        self::assertTrue(ApiKeyScope::isRestricted(ApiKeyScope::pairingScopes()));
    }

    public function testLegacyPlusDesktopIsRestricted(): void
    {
        self::assertTrue(ApiKeyScope::isRestricted(['webhooks:email', 'desktop:messages']));
    }

    public function testBlankStringsAreIgnored(): void
    {
        self::assertFalse(ApiKeyScope::isRestricted(['', '  ']));
        self::assertFalse(ApiKeyScope::isRestricted([' * ']));
    }

    public function testPairingScopesAreExactlyTheFourDesktopScopes(): void
    {
        self::assertSame([
            'desktop:messages',
            'desktop:mcp',
            'desktop:files',
            'desktop:jobs',
        ], ApiKeyScope::pairingScopes());
    }

    public function testWildcardAllowsEverything(): void
    {
        self::assertTrue(ApiKeyScope::allows(['*'], '/api/v1/admin/config/values'));
        self::assertTrue(ApiKeyScope::allows(['*'], '/mcp'));
    }

    public function testDesktopMessagesReachesV1ButNotAdminOrMcp(): void
    {
        $scopes = ['desktop:messages'];

        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/models'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/mcp'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/config/values'));
    }

    public function testPairingKeyReachesItsFourSurfaces(): void
    {
        $scopes = ApiKeyScope::pairingScopes();

        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/mcp'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/desktop/jobs'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/files/123/download'));
    }

    public function testPairingKeyCannotReachAdmin(): void
    {
        $scopes = ApiKeyScope::pairingScopes();

        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/config/values'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/users'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/webhooks/email'));
    }

    public function testDesktopUmbrellaCoversEveryDesktopSurface(): void
    {
        $scopes = ['desktop:*'];

        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/mcp'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/desktop/jobs'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/files'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/config/values'));
    }

    public function testJobsScopeDoesNotReachMessagesOrMcp(): void
    {
        $scopes = ['desktop:jobs'];

        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/desktop/jobs'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/mcp'));
    }
}
