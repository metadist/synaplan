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

    public function testComputeRunIsAToolGrantNotAPathScope(): void
    {
        self::assertFalse(ApiKeyScope::grantsComputeRun([]));
        self::assertFalse(ApiKeyScope::grantsComputeRun(['webhooks:*']));
        self::assertFalse(ApiKeyScope::grantsComputeRun(['webhooks:email']));
        self::assertFalse(ApiKeyScope::grantsComputeRun(ApiKeyScope::pairingScopes()));
        self::assertFalse(ApiKeyScope::grantsComputeRun(ApiKeyScope::addinScopes()));
        self::assertTrue(ApiKeyScope::grantsComputeRun(['compute:run']));
        self::assertTrue(ApiKeyScope::grantsComputeRun(['*']));
        self::assertTrue(ApiKeyScope::grantsComputeRun(['desktop:messages', 'compute:run']));
        self::assertSame(
            [ApiKeyScope::DESKTOP_MESSAGES],
            ApiKeyScope::requiredScopesForPath('/v1/messages'),
        );
        self::assertFalse(\in_array(ApiKeyScope::COMPUTE_RUN, ApiKeyScope::pairingScopes(), true));
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
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/models/catalog'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/assistants'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/assistants/12'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/mcp'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/config/values'));
    }

    public function testPairingKeyReachesItsFourSurfaces(): void
    {
        $scopes = ApiKeyScope::pairingScopes();

        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/models/catalog'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/assistants'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/media/generate'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/research'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/research/rr_0123456789abcdef'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/audio/speech'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/mcp'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/desktop/jobs'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/files/123/download'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/files/upload'));
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

    public function testAudioTranscribeReachesSpeechToTextAndMeetingNotesOnly(): void
    {
        $scopes = [ApiKeyScope::AUDIO_TRANSCRIBE];

        self::assertTrue(ApiKeyScope::isRestricted($scopes));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/audio/transcriptions'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/audio/transcriptions/sessions/stt_sess_1/audio'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/opendesk/meeting-notes', 'POST'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/opendesk/meeting-notes', 'GET'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/opendesk/meeting-notes/note_abc', 'GET'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/opendesk/meeting-notes/connect', 'GET'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/messages'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/audio/speech'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/files'));
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
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/config/models'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/agents'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/agents/12'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/agents/12/versions'));
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

    public function testHealthIsSelfServiceForAnyRestrictedKey(): void
    {
        self::assertTrue(ApiKeyScope::allows(['desktop:jobs'], '/api/health'));
        self::assertTrue(ApiKeyScope::allows(['chat', 'files', 'rag'], '/api/health'));
        self::assertTrue(ApiKeyScope::allows(['messages:*'], '/api/health'));
        // Sibling paths stay closed.
        self::assertFalse(ApiKeyScope::allows(['rag:*'], '/api/healthz'));
        self::assertFalse(ApiKeyScope::allows(['rag:*'], '/api/v1/health'));
    }

    public function testPrefixMatchingDoesNotBleedIntoSiblingPaths(): void
    {
        $scopes = ApiKeyScope::addinScopes();

        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/messagesX'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/chatsX'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/filesX'));
    }

    public function testIamReadReachesGroupsButNotAdminGroups(): void
    {
        $scopes = [ApiKeyScope::IAM_READ];

        self::assertTrue(ApiKeyScope::isRestricted($scopes));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/groups/mine'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/groups'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/groups'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/groups/1/members'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/me/shared'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/iam/subjects'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/shares'));
    }

    public function testIamManageImpliesIamReadAndReachesAdminGroups(): void
    {
        $scopes = [ApiKeyScope::IAM_MANAGE];

        self::assertTrue(ApiKeyScope::isRestricted($scopes));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/admin/groups'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/admin/groups/1/members/4'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/groups/mine'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/shares'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/users'));
    }

    public function testLegacyWebhookKeyIsNotRestricted(): void
    {
        self::assertFalse(ApiKeyScope::isRestricted(['webhooks:*']));
        self::assertFalse(ApiKeyScope::isRestricted([]));
    }

    public function testAgentsScopeReachesAgentsOnly(): void
    {
        $scopes = [ApiKeyScope::AGENTS_ALL];

        self::assertTrue(ApiKeyScope::isRestricted($scopes));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/agents'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/api/v1/agents/12'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/prompts'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/users'));
    }

    public function testPlatformLinkScopesEqualProvisionedScopes(): void
    {
        self::assertSame(
            ApiKeyScope::provisionedPlatformScopes(),
            ApiKeyScope::platformLinkScopes(),
        );
        self::assertSame(['chat', 'files', 'rag'], ApiKeyScope::platformLinkScopes());
        self::assertSame(['chat', 'files', 'rag', 'memories'], ApiKeyScope::platformLinkScopes(true));
    }

    /**
     * @param list<string> $scopes
     */
    #[DataProvider('platformLinkProductPaths')]
    public function testPlatformLinkKeyReachesEachGrantedSurface(array $scopes, string $path): void
    {
        self::assertTrue(ApiKeyScope::allows($scopes, $path));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function platformLinkProductPaths(): iterable
    {
        $scopes = ApiKeyScope::platformLinkScopes();
        yield 'files list' => [$scopes, '/api/v1/files'];
        yield 'file upload' => [$scopes, '/api/v1/files/upload'];
        yield 'chats' => [$scopes, '/api/v1/chats'];
        yield 'chat history' => [$scopes, '/api/v1/chats/42/messages'];
        yield 'messages send' => [$scopes, '/api/v1/messages/send'];
        yield 'tts' => [$scopes, '/api/v1/tts/stream'];
        yield 'model catalog' => [$scopes, '/api/v1/config/models'];
        yield 'rag search' => [$scopes, '/api/v1/rag/search'];
    }

    public function testPlatformLinkKeyCannotReachAdmin(): void
    {
        $scopes = ApiKeyScope::platformLinkScopes();

        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/users'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/groups'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/config/values'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/user/memories'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/agents'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/mcp'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/messages'));
    }

    public function testPlatformLinkMemoriesScopeReachesUserMemoriesOnly(): void
    {
        $withMemories = ApiKeyScope::platformLinkScopes(true);

        self::assertTrue(ApiKeyScope::allows($withMemories, '/api/v1/user/memories'));
        self::assertFalse(ApiKeyScope::allows($withMemories, '/api/v1/user/7'));
        self::assertFalse(ApiKeyScope::allows(['memories'], '/api/v1/files'));
        self::assertFalse(ApiKeyScope::allows(['files'], '/api/v1/chats'));
        self::assertFalse(ApiKeyScope::allows(['chat'], '/api/v1/files'));
        self::assertFalse(ApiKeyScope::allows(['rag'], '/api/v1/chats'));
    }

    /**
     * @param list<string> $scopes
     */
    #[DataProvider('cloudAppRoutes')]
    public function testCloudAppRouteIsReachableWithEachOfItsScopes(string $method, string $path, array $scopes): void
    {
        foreach ($scopes as $scope) {
            self::assertTrue(ApiKeyScope::allows([$scope], $path, $method), sprintf('%s %s with %s', $method, $path, $scope));
        }
    }

    /**
     * @param list<string> $scopes
     */
    #[DataProvider('cloudAppRoutes')]
    public function testCloudAppRouteIsClosedToTheOtherPlatformScopes(string $method, string $path, array $scopes): void
    {
        $others = array_values(array_diff(ApiKeyScope::platformLinkScopes(true), $scopes));

        self::assertFalse(ApiKeyScope::allows($others, $path, $method));
    }

    #[DataProvider('cloudAppAnyKeyRoutes')]
    public function testCloudAppAnyKeyRouteIsOpenToAProvisionedKey(string $method, string $path): void
    {
        self::assertTrue(ApiKeyScope::allows(ApiKeyScope::provisionedPlatformScopes(), $path, $method));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function cloudAppRoutes(): iterable
    {
        foreach (self::cloudAppsContract()['paths'] as $route) {
            $path = strtr($route['path'], ['{id}' => '42', '{path}' => '7/report.png']);
            yield $route['method'].' '.$route['path'] => [$route['method'], $path, $route['scopes']];
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function cloudAppAnyKeyRoutes(): iterable
    {
        foreach (self::cloudAppsContract()['anyKey'] as $route) {
            yield $route['method'].' '.$route['path'] => [$route['method'], $route['path']];
        }
    }

    public function testPlatformRoutesMatchExactMethodAndPath(): void
    {
        $scopes = ApiKeyScope::platformLinkScopes(true);

        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/summary/generate', 'GET'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/summary/generate'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/media/generate-from-images', 'POST'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/media/video/start', 'POST'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/messages', 'POST'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/models', 'GET'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/embeddings', 'POST'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/images/generations', 'POST'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/api/v1/admin/users', 'GET'));
    }

    public function testCloudWatchScopeIsExactAndFromMessageIsSessionOnly(): void
    {
        self::assertSame(['files', 'rag', 'cloud:watch'], ApiKeyScope::backgroundKeyScopes());

        self::assertFalse(ApiKeyScope::allows(['files'], '/api/v1/cloud/watches', 'GET'));
        self::assertFalse(ApiKeyScope::allows(['files'], '/api/v1/cloud/watches', 'POST'));
        self::assertFalse(ApiKeyScope::allows(['files'], '/api/v1/cloud/trials', 'POST'));
        self::assertTrue(ApiKeyScope::allows(['cloud:watch'], '/api/v1/cloud/watches', 'GET'));
        self::assertTrue(ApiKeyScope::allows(['cloud:watch'], '/api/v1/cloud/watches/4/files', 'POST'));
        self::assertTrue(ApiKeyScope::allows(['cloud:watch'], '/api/v1/cloud/trials', 'POST'));
        self::assertFalse(ApiKeyScope::allows(['cloud:watch'], '/api/v1/cloud/trials', 'GET'));
        self::assertFalse(ApiKeyScope::allows(['cloud:watch'], '/api/v1/cloud/status', 'POST'));
        self::assertTrue(ApiKeyScope::allows(['files'], '/api/v1/cloud/status', 'POST'));
        self::assertFalse(ApiKeyScope::allows(['files'], '/api/v1/cloud/status', 'GET'));
        self::assertTrue(ApiKeyScope::allows(['files'], '/api/v1/cloud/files/9', 'GET'));
        self::assertFalse(ApiKeyScope::allows(['files'], '/api/v1/cloud/files/9', 'POST'));
        self::assertFalse(ApiKeyScope::allows(['chat'], '/api/v1/cloud/status', 'POST'));

        $fromMessage = '/api/v1/cloud/watches/from-message/12';
        foreach ([[], ['*'], ['files'], ['cloud:watch'], ['chat'], ['rag'], ApiKeyScope::backgroundKeyScopes(), ApiKeyScope::platformLinkScopes(true)] as $scopes) {
            self::assertFalse(ApiKeyScope::allows($scopes, $fromMessage, 'POST'));
            self::assertTrue(ApiKeyScope::isSessionOnly('POST', $fromMessage));
        }
        self::assertFalse(ApiKeyScope::isSessionOnly('POST', '/api/v1/cloud/watches/12'));

        foreach (['chat', 'files', 'rag'] as $scope) {
            self::assertTrue(ApiKeyScope::allows([$scope], '/api/v1/platform-links/identities/adopt', 'POST'));
        }
    }

    public function testPlatformRoutesKeepTheGatewayScopes(): void
    {
        self::assertTrue(ApiKeyScope::allows(['desktop:messages'], '/v1/chat/completions', 'POST'));
        self::assertTrue(ApiKeyScope::allows(['freeapi'], '/v1/chat/completions', 'POST'));
        self::assertSame(['freeapi', 'desktop:messages', 'chat'], ApiKeyScope::requiredScopesForPath('/v1/chat/completions', 'POST'));
        self::assertSame(['freeapi', 'desktop:messages'], ApiKeyScope::requiredScopesForPath('/v1/chat/completions', 'GET'));
        self::assertSame(['chat', 'memories'], ApiKeyScope::requiredScopesForPath('/api/v1/config/memory-service/check', 'GET'));
    }

    /**
     * @return array{paths: list<array{method: string, path: string, scopes: list<string>}>, anyKey: list<array{method: string, path: string}>}
     */
    private static function cloudAppsContract(): array
    {
        $json = file_get_contents(__DIR__.'/../../Fixtures/cloud-apps-contract/paths.json');
        if (false === $json) {
            throw new \RuntimeException('Fixtures/cloud-apps-contract/paths.json is missing.');
        }

        /** @var array{paths: list<array{method: string, path: string, scopes: list<string>}>, anyKey: list<array{method: string, path: string}>} $contract */
        $contract = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $contract;
    }

    public function testGrandfatherUnchanged(): void
    {
        self::assertSame([
            'messages:*',
            'chats:*',
            'files:*',
            'rag:*',
        ], ApiKeyScope::addinScopes());
        self::assertSame([
            'desktop:messages',
            'desktop:mcp',
            'desktop:files',
            'desktop:jobs',
        ], ApiKeyScope::pairingScopes());
        self::assertFalse(ApiKeyScope::allows(ApiKeyScope::platformLinkScopes(), '/api/v1/admin/users'));
        self::assertTrue(ApiKeyScope::allows(ApiKeyScope::platformLinkScopes(), '/api/v1/auth/me'));
    }

    // -------------------------------------------------------------------------
    // Synaplan Demo keys (`freeapi`) — minted by the private server plugin.
    // -------------------------------------------------------------------------

    public function testFreeApiKeyIsRestrictedAndExact(): void
    {
        self::assertTrue(ApiKeyScope::isRestricted(['freeapi']));
        self::assertTrue(ApiKeyScope::isFreeApiKey(['freeapi']));
        self::assertTrue(ApiKeyScope::isFreeApiKey([' freeapi ', 'freeapi']));
        self::assertFalse(ApiKeyScope::isFreeApiKey([]));
        self::assertFalse(ApiKeyScope::isFreeApiKey(['freeapi', 'desktop:messages']));
        self::assertFalse(ApiKeyScope::isFreeApiKey(['*']));
    }

    public function testCarriesFreeApiCoversMixedListsButNotFullAccess(): void
    {
        self::assertTrue(ApiKeyScope::carriesFreeApi(['freeapi']));
        self::assertTrue(ApiKeyScope::carriesFreeApi(['freeapi', 'chat']));
        self::assertFalse(ApiKeyScope::carriesFreeApi(['freeapi', '*']));
        self::assertFalse(ApiKeyScope::carriesFreeApi(['desktop:messages']));
        self::assertFalse(ApiKeyScope::carriesFreeApi([]));
    }

    #[DataProvider('freeApiAllowedPaths')]
    public function testFreeApiKeyReachesTheGatewayAndItsOwnRoutes(string $path): void
    {
        self::assertTrue(ApiKeyScope::allows(['freeapi'], $path));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function freeApiAllowedPaths(): iterable
    {
        yield 'chat' => ['/v1/chat/completions'];
        yield 'embeddings' => ['/v1/embeddings'];
        yield 'images' => ['/v1/images/generations'];
        yield 'models' => ['/v1/models'];
        yield 'issuance' => ['/v1/free/keys'];
        yield 'status' => ['/v1/free/status'];
        yield 'self revoke' => ['/v1/free/keys/self'];
    }

    #[DataProvider('freeApiDeniedPaths')]
    public function testFreeApiKeyIsDeniedEverywhereElse(string $path): void
    {
        self::assertFalse(ApiKeyScope::allows(['freeapi'], $path));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function freeApiDeniedPaths(): iterable
    {
        yield 'anthropic messages' => ['/v1/messages'];
        yield 'count tokens' => ['/v1/messages/count_tokens'];
        yield 'media' => ['/v1/media/generate'];
        yield 'speech' => ['/v1/audio/speech'];
        yield 'transcriptions' => ['/v1/audio/transcriptions'];
        yield 'assistants' => ['/v1/assistants'];
        yield 'model catalog' => ['/v1/models/catalog'];
        yield 'mcp' => ['/mcp'];
        yield 'api messages' => ['/api/v1/messages'];
        yield 'files' => ['/api/v1/files'];
        yield 'admin' => ['/api/v1/admin/config/values'];
        yield 'plugins' => ['/api/v1/user/1/plugins/synafreeapi_server/overview'];
        yield 'who am i' => ['/api/v1/auth/me'];
        yield 'free prefix lookalike' => ['/v1/freebies'];
    }

    public function testDesktopKeyKeepsTheGatewayButNotTheDemoRoutes(): void
    {
        $scopes = ['desktop:messages'];

        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/chat/completions'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/embeddings'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/images/generations'));
        self::assertTrue(ApiKeyScope::allows($scopes, '/v1/models'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/free/keys'));
        self::assertFalse(ApiKeyScope::allows($scopes, '/v1/free/status'));
        self::assertFalse(ApiKeyScope::allows(ApiKeyScope::pairingScopes(), '/v1/free/keys/self'));
    }

    public function testOtherScopesStillReachWhoAmI(): void
    {
        self::assertTrue(ApiKeyScope::allows(['desktop:messages'], '/api/v1/auth/me'));
        self::assertTrue(ApiKeyScope::allows(['audio:transcribe'], '/api/health'));
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
