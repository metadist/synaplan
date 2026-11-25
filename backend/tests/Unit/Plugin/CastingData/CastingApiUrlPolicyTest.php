<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plugin\CastingData;

use App\Service\Security\HostResolver;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\CastingData\Service\CastingApiUrlPolicy;

final class CastingApiUrlPolicyTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    /** @return array<string, array{0: string}> */
    public static function refusedUrls(): array
    {
        return [
            'IPv4-mapped private address' => ['http://[::ffff:172.18.0.11]:6333/collections'],
            'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'loopback' => ['http://127.0.0.1:8000'],
            'private range' => ['https://10.0.0.5'],
            'cloud metadata' => ['http://169.254.169.254'],
            'decimal loopback' => ['http://2130706433'],
            'localhost' => ['http://localhost'],
            'internal service name' => ['http://qdrant:6333'],
            'name resolving to a private address' => ['https://intranet.example.test'],
            'name without an address' => ['https://nowhere.example.test'],
            'fragment that swallows the path' => ['https://casting.example.test/collections#'],
            'bare fragment mark' => ['https://casting.example.test#'],
            'query string' => ['https://casting.example.test/?next=1'],
            'bare query mark' => ['https://casting.example.test?'],
            'credentials' => ['https://user:secret@casting.example.test'],
            'other scheme' => ['ftp://casting.example.test'],
            'no scheme' => ['casting.example.test'],
            'no host' => ['https://'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesAnAddressTheClientWouldNotCall(string $url): void
    {
        self::assertNotNull($this->policy()->problem($url));
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedUrls(): array
    {
        return [
            'public host' => ['https://casting.example.test'],
            'with a base path' => ['https://casting.example.test/backstage/'],
            'with a port' => ['https://casting.example.test:8443'],
            'plain http' => ['http://casting.example.test'],
            'public literal address' => ['https://'.self::PUBLIC_IP],
        ];
    }

    #[DataProvider('acceptedUrls')]
    public function testAcceptsAPublicBaseAddress(string $url): void
    {
        self::assertNull($this->policy()->problem($url));
    }

    private function policy(): CastingApiUrlPolicy
    {
        $resolver = new class implements HostResolver {
            public function addresses(string $host): array
            {
                return match ($host) {
                    'casting.example.test' => ['93.184.216.34'],
                    'intranet.example.test' => ['93.184.216.34', '10.1.2.3'],
                    default => [],
                };
            }
        };

        return new CastingApiUrlPolicy(new SsrfGuard($resolver));
    }
}
