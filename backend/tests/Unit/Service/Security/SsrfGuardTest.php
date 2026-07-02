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
}
