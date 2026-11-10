<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Credential;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\TurnCredentialResolver;
use App\AI\Credential\UserProviderKeyResolver;
use PHPUnit\Framework\TestCase;

final class TurnCredentialResolverTest extends TestCase
{
    public function testTheGatewayPrefersThePersonsKey(): void
    {
        $users = $this->createMock(UserProviderKeyResolver::class);
        $users->method('isLocalHost')->willReturn(false);
        $users->method('resolve')->with('openai', 3, true)->willReturn(['key' => 'sk-user', 'source' => 'user']);

        $decision = (new TurnCredentialResolver($users))->forGateway('openai', 3, true);

        self::assertTrue($decision->present);
        self::assertSame('sk-user', $decision->key);
        self::assertSame('user', $decision->keySource);
        self::assertFalse($decision->operatorFallback);
    }

    public function testTheAppChatUsesTheOperatorKey(): void
    {
        $users = $this->createMock(UserProviderKeyResolver::class);
        $users->method('isLocalHost')->willReturn(false);
        $store = $this->createMock(ProviderKeyStore::class);
        $store->method('getKey')->with('openai')->willReturn('sk-operator');

        $decision = (new TurnCredentialResolver($users, $store))->forAppChat('openai');

        self::assertSame('sk-operator', $decision->key);
        self::assertSame('operator', $decision->keySource);
        self::assertTrue($decision->operatorFallback);
    }

    public function testALocalHostNeedsNoStoreKey(): void
    {
        $users = $this->createMock(UserProviderKeyResolver::class);
        $users->method('isLocalHost')->willReturn(true);

        $decision = (new TurnCredentialResolver($users))->forGateway('ollama', 3, false);

        self::assertSame('local', $decision->key);
        self::assertSame('operator', $decision->keySource);
    }
}
