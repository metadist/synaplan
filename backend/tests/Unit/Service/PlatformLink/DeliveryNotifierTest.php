<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Repository\ConfigRepository;
use App\Service\Federation\FederationSigner;
use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\DeliveryOriginPolicy;
use App\Service\PlatformLink\NotificationToken;
use App\Service\PlatformLink\NotifyKeyStore;
use App\Service\PlatformLink\OidcCloud;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\PlatformLink\SignedNotificationClient;
use App\Service\Security\EncryptionService;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DeliveryNotifierTest extends TestCase
{
    public function testAHostThatIsNotThisCloudIsNeverCalled(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setDeliveryUrl('https://other.example/inbound');

        self::assertNull($this->client($http)->post($instance, 'delivery', '9'));
    }

    public function testAHostThatResolvesNowhereAcceptableIsNeverCalled(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setDeliveryUrl('https://files.example.org/inbound');

        self::assertNull($this->client($http, [])->post($instance, 'delivery', '9'));
    }

    public function testATimeoutLeavesTheCopyUnconfirmed(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willThrowException(new TransportException('timed out'));
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setDeliveryUrl('https://files.example.org/index.php/apps/synaplan_integration/api/v1/inbound');

        self::assertNull($this->client($http)->post($instance, 'delivery', '9'));
    }

    public function testTheCloudReceivesATypedTokenFromThisSynaplanForThePerson(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(202);
        $response->method('getContent')->willReturn('{}');
        $sent = null;
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(static function (string $method, string $url, array $options) use (&$sent, $response): ResponseInterface {
            $sent = $options;

            return $response;
        });
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setDeliveryUrl('https://files.example.org/inbound');

        $this->client($http)->post($instance, 'pickup', '15', 'ada');

        self::assertIsArray($sent);
        self::assertSame(['files.example.org' => '203.0.113.10'], $sent['resolve'] ?? null, 'connects to the address the check resolved');
        self::assertSame('application/jwt', $sent['headers']['Content-Type'] ?? null);
        [$header, $payload] = explode('.', (string) $sent['body']);
        $decode = static fn (string $part): array => (array) json_decode((string) base64_decode(strtr($part, '-_', '+/')), true);
        self::assertSame(NotificationToken::TYPE, $decode($header)['typ'] ?? null);
        $claims = $decode($payload);
        self::assertSame(
            ['https://synaplan.example', 'pi_abc', 'ada', 'pickup', '15'],
            [$claims['iss'] ?? null, $claims['aud'] ?? null, $claims['sub'] ?? null, $claims['type'] ?? null, $claims['id'] ?? null],
        );
    }

    public function testAFinishedCopyReturnsTheCloudsAnswer(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('{"status":"delivered","remote_path":"Synaplan/Documents/notes.txt"}');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willReturn($response);
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setDeliveryUrl('https://files.example.org/inbound');

        $answer = $this->client($http)->post($instance, 'delivery', '9');

        self::assertSame('delivered', $answer['status'] ?? null);
    }

    /**
     * @param list<string> $resolved what the stubbed DNS answers for any host
     */
    private function client(HttpClientInterface $http, array $resolved = ['203.0.113.10']): SignedNotificationClient
    {
        $ssrf = $this->createStub(SsrfGuard::class);
        $ssrf->method('pinnedIps')->willReturn($resolved);
        $ssrf->method('isBlockedHost')->willReturn([] === $resolved);
        $saved = null;
        $configs = $this->createStub(ConfigRepository::class);
        $configs->method('getValue')->willReturnCallback(static function () use (&$saved): ?string {
            return $saved;
        });
        $configs->method('insertIfAbsent')->willReturnCallback(static function (int $owner, string $group, string $setting, string $value) use (&$saved): string {
            $saved ??= $value;

            return $saved;
        });
        $redis = $this->createStub(RedisService::class);
        $redis->method('isAvailable')->willReturn(false);

        return new SignedNotificationClient(
            $http,
            new NotifyKeyStore($configs, new EncryptionService('test-secret', new NullLogger()), new FederationSigner(), $redis),
            new FederationSigner(),
            new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), $ssrf, new OidcCloud('', '', '')),
            new NullLogger(),
            'https://synaplan.example/',
        );
    }
}
