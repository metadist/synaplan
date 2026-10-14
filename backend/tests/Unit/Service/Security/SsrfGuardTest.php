<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    /** @return list<array{0: string}> */
    public static function blockedUrls(): array
    {
        return [
            ['http://localhost/admin'],
            ['http://LOCALHOST:8080/x'],
            ['http://sub.localhost/x'],
            ['http://127.0.0.1/secrets'],
            ['http://127.8.9.10/'],
            ['http://10.0.0.5/internal'],
            ['http://172.16.0.1/'],
            ['http://172.31.255.255/'],
            ['http://192.168.1.1/router'],
            ['http://169.254.169.254/latest/meta-data'],
            ['http://0.0.0.0/'],
            ['http://[::1]/'],
            ['http://[fd00::1]/'],
            ['http://[fe80::1]/'],
            ['ftp://example.com/file'],
            ['file:///etc/passwd'],
            ['gopher://example.com/'],
            ['not a url at all'],
            ['http:///missing-host'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testBlocksPrivateReservedAndNonHttpTargets(string $url): void
    {
        self::assertTrue($this->guard->isBlockedUrl($url), "expected blocked: {$url}");
    }

    /** @return list<array{0: string}> */
    public static function allowedIps(): array
    {
        return [
            ['http://93.184.216.34/'],   // example.com's public v4
            ['https://8.8.8.8/dns'],
            ['https://[2606:4700:4700::1111]/'],
        ];
    }

    #[DataProvider('allowedIps')]
    public function testAllowsPublicLiteralIps(string $url): void
    {
        self::assertFalse($this->guard->isBlockedUrl($url), "expected allowed: {$url}");
    }

    public function testBlockedIpClassifierCoversPrivateAndReservedRanges(): void
    {
        self::assertTrue($this->guard->isBlockedIp('10.1.2.3'));
        self::assertTrue($this->guard->isBlockedIp('192.168.0.10'));
        self::assertTrue($this->guard->isBlockedIp('172.20.1.1'));
        self::assertTrue($this->guard->isBlockedIp('127.0.0.1'));
        self::assertTrue($this->guard->isBlockedIp('169.254.10.10'));
        self::assertTrue($this->guard->isBlockedIp('0.1.2.3'));
        self::assertTrue($this->guard->isBlockedIp('::1'));
        self::assertTrue($this->guard->isBlockedIp('fd12:3456::1'));

        self::assertFalse($this->guard->isBlockedIp('93.184.216.34'));
        self::assertFalse($this->guard->isBlockedIp('2606:4700:4700::1111'));
    }

    /**
     * Ranges that are not globally routable but slip past NO_PRIV_RANGE +
     * NO_RES_RANGE alone: shared address space (CGNAT, overlay VPNs such as
     * Tailscale), 192.0.0/24, benchmarking, documentation, 6to4.
     */
    public function testBlockedIpClassifierCoversNonGlobalRanges(): void
    {
        self::assertTrue($this->guard->isBlockedIp('100.64.0.1'));
        self::assertTrue($this->guard->isBlockedIp('100.127.255.254'));
        self::assertTrue($this->guard->isBlockedIp('192.0.0.1'));
        self::assertTrue($this->guard->isBlockedIp('198.18.0.1'));
        self::assertTrue($this->guard->isBlockedIp('2001:db8::1'));
        self::assertTrue($this->guard->isBlockedIp('2002:7f00:1::1'));

        self::assertFalse($this->guard->isBlockedIp('100.63.255.255'));
        self::assertFalse($this->guard->isBlockedIp('100.128.0.1'));
        self::assertFalse($this->guard->isBlockedIp('8.8.8.8'));
    }

    public function testPinnedIpsReturnsPublicLiteralAndDropsPrivate(): void
    {
        self::assertSame(['8.8.8.8'], $this->guard->pinnedIps('8.8.8.8'));
        self::assertSame([], $this->guard->pinnedIps('10.1.2.3'));
        self::assertSame([], $this->guard->pinnedIps('localhost'));
    }

    /**
     * The operator-network mode is for addresses an administrator configures
     * (a self-hosted gateway). It keeps the operator's own ranges and nothing
     * else: link-local — where cloud metadata answers — stays blocked.
     */
    public function testOperatorNetworkModeKeepsPrivateRangesOnly(): void
    {
        foreach (['10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.0.10', '127.0.0.1', '127.8.9.10', '100.64.0.1', '100.127.255.254', '::1', 'fd12:3456::1', 'fc00::1'] as $ip) {
            self::assertTrue($this->guard->isOperatorNetworkIp($ip), "operator range: {$ip}");
            self::assertFalse($this->guard->isBlockedIp($ip, allowOperatorNetwork: true), "allowed with operator network: {$ip}");
            self::assertTrue($this->guard->isBlockedIp($ip), "still blocked by default: {$ip}");
        }

        foreach (['169.254.169.254', '169.254.10.10', 'fe80::1', '0.0.0.0', '0.1.2.3', '192.0.0.1', '192.0.2.1', '198.18.0.1', '255.255.255.255', '2001:db8::1', '2002:7f00:1::1', '::'] as $ip) {
            self::assertFalse($this->guard->isOperatorNetworkIp($ip), "not an operator range: {$ip}");
            self::assertTrue($this->guard->isBlockedIp($ip, allowOperatorNetwork: true), "blocked even with operator network: {$ip}");
        }

        foreach (['172.15.255.255', '172.32.0.0', '100.63.255.255', '100.128.0.1', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
            self::assertFalse($this->guard->isOperatorNetworkIp($ip), "public, not an operator range: {$ip}");
            self::assertFalse($this->guard->isBlockedIp($ip, allowOperatorNetwork: true), "public stays allowed: {$ip}");
        }

        self::assertFalse($this->guard->isOperatorNetworkIp('not-an-ip'));
        self::assertFalse($this->guard->isOperatorNetworkIp(''));
    }

    public function testPinnedIpsWithOperatorNetworkKeepsPrivateLiteralsAndDropsLinkLocal(): void
    {
        self::assertSame(['10.1.2.3'], $this->guard->pinnedIps('10.1.2.3', allowOperatorNetwork: true));
        self::assertSame(['127.0.0.1'], $this->guard->pinnedIps('127.0.0.1', allowOperatorNetwork: true));
        self::assertSame(['fd00::1'], $this->guard->pinnedIps('[fd00::1]', allowOperatorNetwork: true));
        self::assertSame(['8.8.8.8'], $this->guard->pinnedIps('8.8.8.8', allowOperatorNetwork: true));
        self::assertSame([], $this->guard->pinnedIps('169.254.169.254', allowOperatorNetwork: true));
        self::assertSame([], $this->guard->pinnedIps('[fe80::1]', allowOperatorNetwork: true));
    }
}
