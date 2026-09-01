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

    // -------------------------------------------------------------------------
    // Outlook add-in (Synamail) keys — minted with addinScopes() since before
    // enforcement existed; the whole surface the add-in uses must stay open.
    // -------------------------------------------------------------------------

    public function testAddinScopesAreExactlyTheFourAreaScopes(): void
    {
        self::assertSame([
            'messages:*',
            'chats:*',
            'files:*',
            'rag:*',
        ], ApiKeyScope::addinScopes());
    }

    public function testAddinScopesAreRestricted(): void
    {
        self::assertTrue(ApiKeyScope::isRestricted(ApiKeyScope::addinScopes()));
    }

    /**
     * Every endpoint the Synamail client calls (see its `synaplan-client.ts`).
     */
    #[DataProvider('addinSurfacePaths')]
    public function testAddinKeyReachesItsFullSurface(string $path): void
    {
        self::assertTrue(ApiKeyScope::allows(ApiKeyScope::addinScopes(), $path));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function addinSurfacePaths(): iterable
    {
        yield 'who am I' => ['/api/v1/auth/me'];
        yield 'blocking send' => ['/api/v1/messages/send'];
        yield 'SSE stream' => ['/api/v1/messages/stream'];
        yield 'create chat' => ['/api/v1/chats'];
        yield 'chat history' => ['/api/v1/chats/42/messages'];
        yield 'file upload' => ['/api/v1/files/upload'];
        yield 'file groups' => ['/api/v1/files/groups'];
        yield 'rag search' => ['/api/v1/rag/search'];
        yield 'tts' => ['/api/v1/tts/stream'];
        yield 'model defaults' => ['/api/v1/config/models/defaults'];
        yield 'model catalog' => ['/api/v1/config/models'];
        yield 'synamail plugin profiling' => ['/api/v1/user/7/plugins/synamail/profiles/a%40b.c'];
    }

    public function testAddinKeyCannotReachAdminUsersWebhooksOrDesktop(): void
    {
        $scopes = ApiKeyScope::addinScopes();

        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/config/values'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/users'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/webhooks/email'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/desktop/jobs'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/mcp'));
        // `/api/v1/user/{id}` outside the plugin sub-tree stays closed.
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/user/7'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/user/7/settings'));
    }

    public function testDesktopKeyDoesNotGainTheAddinSurface(): void
    {
        $scopes = ApiKeyScope::pairingScopes();

        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/messages/send'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/chats'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/rag/search'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/tts/stream'));
    }

    public function testFilesPathAcceptsEitherFilesScope(): void
    {
        self::assertTrue(ApiKeyScope::allows(['desktop:files'], '/api/v1/files/upload'));
        self::assertTrue(ApiKeyScope::allows(['files:*'], '/api/v1/files/upload'));
        self::assertFalse(ApiKeyScope::allows(['messages:*'], '/api/v1/files/upload'));
    }

    public function testAuthMeIsSelfServiceForAnyRestrictedKey(): void
    {
        self::assertTrue(ApiKeyScope::allows(['desktop:jobs'], '/api/v1/auth/me'));
        self::assertTrue(ApiKeyScope::allows(['rag:*'], '/api/v1/auth/me'));
        // …but not the rest of the auth surface.
        self::assertFalse(ApiKeyScope::allows(['rag:*'], '/api/v1/auth/logout'));
    }

    public function testPrefixMatchingDoesNotBleedIntoSiblingPaths(): void
    {
        $scopes = ApiKeyScope::addinScopes();

        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/messagesX'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/chatsX'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/filesX'));
    }

    public function testSelfRevokeMatchesOnlyOwnKeyViaDelete(): void
    {
        self::assertTrue(ApiKeyScope::isSelfRevoke('DELETE', '/api/v1/apikeys/12', 12));
        self::assertTrue(ApiKeyScope::isSelfRevoke('delete', '/api/v1/apikeys/12', 12));

        self::assertFalse(ApiKeyScope::isSelfRevoke('DELETE', '/api/v1/apikeys/13', 12));
        self::assertFalse(ApiKeyScope::isSelfRevoke('GET', '/api/v1/apikeys/12', 12));
        self::assertFalse(ApiKeyScope::isSelfRevoke('PATCH', '/api/v1/apikeys/12', 12));
        self::assertFalse(ApiKeyScope::isSelfRevoke('DELETE', '/api/v1/apikeys/12/extra', 12));
    }
}
