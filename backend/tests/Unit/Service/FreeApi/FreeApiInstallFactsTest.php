<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\Service\FreeApi\FreeApiInstallFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FreeApiInstallFactsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        yield 'localhost' => ['http://localhost:8000', 'localhost'];
        yield 'loopback v4' => ['http://127.0.0.1:8000', 'localhost'];
        yield 'loopback v6' => ['http://[::1]:8000', 'localhost'];
        yield 'empty' => ['', 'localhost'];
        yield 'rfc1918' => ['http://192.168.1.20', 'private'];
        yield 'single label' => ['http://synaplan:8000', 'private'];
        yield 'lan suffix' => ['https://ai.office.lan', 'private'];
        yield 'public ip' => ['https://8.8.8.8', 'public'];
        yield 'public name' => ['https://chat.example.com', 'public'];
    }

    #[DataProvider('hosts')]
    public function testHostKind(string $appUrl, string $expected): void
    {
        $this->assertSame($expected, (new FreeApiInstallFacts($appUrl))->hostKind());
    }

    public function testHostHashIsTheHashOfTheLowerCasedHostOnly(): void
    {
        $facts = new FreeApiInstallFacts('https://Chat.Example.com:8443/path?q=1');

        $this->assertSame(hash('sha256', 'chat.example.com'), $facts->hostHash());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $facts->hostHash());
    }
}
