<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Repository\ConfigRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Federation\FederationSigner;
use App\Service\PlatformLink\DeliveryNotifier;
use App\Service\PlatformLink\DeliveryOriginPolicy;
use App\Service\PlatformLink\NotifyKeyStore;
use App\Service\PlatformLink\RedirectUriPolicy;
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

        self::assertNull($this->notifier($http)->delivery($instance, 9));
    }

    public function testATimeoutLeavesTheCopyUnconfirmed(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willThrowException(new TransportException('timed out'));
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setDeliveryUrl('https://files.example.org/index.php/apps/synaplan_integration/api/v1/inbound');

        self::assertNull($this->notifier($http)->delivery($instance, 9));
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

        $answer = $this->notifier($http)->delivery($instance, 9);

        self::assertSame('delivered', $answer['status'] ?? null);
    }

    private function notifier(HttpClientInterface $http): DeliveryNotifier
    {
        $saved = null;
        $configs = $this->createStub(ConfigRepository::class);
        $configs->method('getValue')->willReturnCallback(static function () use (&$saved): ?string {
            return $saved;
        });
        $configs->method('setValue')->willReturnCallback(static function (int $owner, string $group, string $setting, string $value) use (&$saved): \App\Entity\Config {
            $saved = $value;

            return new \App\Entity\Config();
        });

        return new DeliveryNotifier(
            $http,
            $this->createStub(PlatformInstanceRepository::class),
            new NotifyKeyStore($configs, new EncryptionService('test-secret', new NullLogger()), new FederationSigner()),
            new FederationSigner(),
            new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard()),
            new NullLogger(),
        );
    }
}
