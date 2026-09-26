<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\ApiSessionClient;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Security\ApiKeyScope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiSessionClientTest extends TestCase
{
    public function testPairedDesktopKeyIsNotLabeledClaudeCode(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $key = (new ApiKey())->setScopes(ApiKeyScope::pairingScopes());
        $request->attributes->set('api_key', $key);

        self::assertSame(ApiSessionClient::DESKTOP, ApiSessionClient::fromRequest($request));
        self::assertSame('Synaplan Desktop', ApiSessionClient::label(ApiSessionClient::DESKTOP));
    }

    public function testWildcardKeyStaysClaudeCodeEvenWithADesktopScope(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $key = (new ApiKey())->setScopes(['desktop:messages', '*']);
        $request->attributes->set('api_key', $key);
        $request->headers->set('x-claude-code-session-id', 'sess-1');

        self::assertSame(ApiSessionClient::CLAUDE_CODE, ApiSessionClient::fromRequest($request));
    }

    public function testWildcardKeyWithDesktopUserAgentStaysClaudeCode(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $key = (new ApiKey())->setScopes(['desktop:messages', '*']);
        $request->attributes->set('api_key', $key);
        $request->headers->set('User-Agent', 'synaplan-desktop/0.4.0');

        self::assertSame(ApiSessionClient::CLAUDE_CODE, ApiSessionClient::fromRequest($request));
    }

    public function testDesktopUserAgentIsRecognizedWithoutAPairedKey(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $request->headers->set('User-Agent', 'synaplan-desktop/0.4.0');

        self::assertSame(ApiSessionClient::DESKTOP, ApiSessionClient::fromRequest($request));
    }

    public function testLocalStampUsesTheAccountTimezone(): void
    {
        $user = new User();
        $user->setUserDetails(['timezone' => 'Europe/Berlin']);
        $now = new \DateTimeImmutable('2026-09-25 15:11:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-09-25 17:11', ApiSessionClient::localStamp($user, $now));
    }

    public function testInvalidTimezoneFallsBackToUtc(): void
    {
        $user = new User();
        $user->setUserDetails(['timezone' => 'Not/AZone']);
        $now = new \DateTimeImmutable('2026-09-25 15:11:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-09-25 15:11', ApiSessionClient::localStamp($user, $now));
    }

    public function testRetitleKeepsTheOriginalStamp(): void
    {
        self::assertSame(
            'Synaplan Desktop · 2026-09-25 15:11',
            ApiSessionClient::retitleDesktop('Claude Code · 2026-09-25 15:11'),
        );
        self::assertNull(ApiSessionClient::retitleDesktop('Synaplan Desktop · 2026-09-25 15:11'));
        self::assertNull(ApiSessionClient::retitleDesktop('Project notes'));
    }
}
