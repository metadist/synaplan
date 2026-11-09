<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform_instance firewall: every call a connected cloud makes as
 * itself is authenticated by PlatformInstanceAuthenticator, not by the
 * controller behind it.
 */
final class PlatformInstanceAuthTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const SECRET = 'correct-horse-battery-staple';
    private const AUTH_ERRORS = ['unauthorized', 'unknown_instance', 'instance_pending', 'too_many_attempts'];

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private string $ip;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
        $this->setFlag(true);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instanceRoutes(): iterable
    {
        yield 'self check' => ['GET', '/api/v1/platform-links/instances/self'];
        yield 'self update' => ['PATCH', '/api/v1/platform-links/instances/self'];
        yield 'delivery claim' => ['POST', '/api/v1/platform-links/deliveries/1/claim'];
        yield 'delivery file' => ['GET', '/api/v1/platform-links/deliveries/1/file'];
        yield 'delivery result' => ['POST', '/api/v1/platform-links/deliveries/1/result'];
        yield 'pickup claim' => ['POST', '/api/v1/platform-links/pickups/1/claim'];
        yield 'pickup listing' => ['POST', '/api/v1/platform-links/pickups/1/listing'];
        yield 'pickup files' => ['POST', '/api/v1/platform-links/pickups/1/files'];
        yield 'pickup result' => ['POST', '/api/v1/platform-links/pickups/1/result'];
        yield 'read scope' => ['PUT', '/api/v1/platform-links/read-scope'];
        yield 'source events' => ['POST', '/api/v1/platform-links/sources/events'];
        yield 'watch index' => ['GET', '/api/v1/platform-links/watches'];
        yield 'lifecycle' => ['POST', '/api/v1/platform-links/lifecycle'];
    }

    #[DataProvider('instanceRoutes')]
    public function testAMissingCredentialIsRefused(string $method, string $path): void
    {
        $this->call($method, $path, []);

        $this->assertRefused(Response::HTTP_UNAUTHORIZED, 'unauthorized');
    }

    #[DataProvider('instanceRoutes')]
    public function testAWrongSecretIsRefused(string $method, string $path): void
    {
        $instance = $this->instance(PlatformInstance::STATUS_ACTIVE);

        $this->call($method, $path, $this->headers($instance, 'not-the-secret'));

        $this->assertRefused(Response::HTTP_UNAUTHORIZED, 'unauthorized');
    }

    #[DataProvider('instanceRoutes')]
    public function testAnUnknownInstanceIsRefused(string $method, string $path): void
    {
        $this->call($method, $path, ['HTTP_X_INSTANCE_ID' => 'pi_'.bin2hex(random_bytes(6)), 'HTTP_X_INSTANCE_SECRET' => self::SECRET]);

        $this->assertRefused(Response::HTTP_NOT_FOUND, 'unknown_instance');
    }

    #[DataProvider('instanceRoutes')]
    public function testAnApprovedInstanceReachesTheRoute(string $method, string $path): void
    {
        $instance = $this->instance(PlatformInstance::STATUS_ACTIVE);

        $this->call($method, $path, $this->headers($instance));

        $this->assertPastAuthentication();
    }

    public function testAPendingInstanceReachesOnlyItsSelfCheck(): void
    {
        $instance = $this->instance(PlatformInstance::STATUS_PENDING);

        $this->call('GET', '/api/v1/platform-links/instances/self', $this->headers($instance));
        self::assertResponseIsSuccessful();
        self::assertSame('pending', $this->json()['status']);

        $this->call('PATCH', '/api/v1/platform-links/instances/self', $this->headers($instance));
        $this->assertPastAuthentication();

        $this->call('POST', '/api/v1/platform-links/deliveries/1/claim', $this->headers($instance));
        $this->assertRefused(Response::HTTP_FORBIDDEN, 'instance_pending');

        $this->call('POST', '/api/v1/platform-links/lifecycle', $this->headers($instance));
        $this->assertRefused(Response::HTTP_FORBIDDEN, 'instance_pending');
    }

    public function testAPersonsSessionNeverStandsInForTheInstance(): void
    {
        $admin = $this->person();
        $admin->setUserLevel('ADMIN');
        $this->em->flush();
        $this->authenticateClient($this->client, $admin);

        $this->call('POST', '/api/v1/platform-links/deliveries/1/claim', []);
        $this->assertRefused(Response::HTTP_UNAUTHORIZED, 'unauthorized');

        $this->call('GET', '/api/v1/platform-links/watches', ['HTTP_AUTHORIZATION' => 'Bearer sk_'.bin2hex(random_bytes(16))]);
        $this->assertRefused(Response::HTTP_UNAUTHORIZED, 'unauthorized');

        $instance = $this->instance(PlatformInstance::STATUS_ACTIVE);
        $this->call('GET', '/api/v1/platform-links/instances/self', $this->headers($instance));
        self::assertResponseIsSuccessful();
        self::assertSame($instance->getHost(), $this->json()['host']);
    }

    public function testRepeatedFailuresFromOneAddressAreThrottledBeforeTheSecretIsChecked(): void
    {
        $instance = $this->instance(PlatformInstance::STATUS_ACTIVE);

        for ($i = 0; $i < 30; ++$i) {
            $this->call('GET', '/api/v1/platform-links/instances/self', $this->headers($instance, 'wrong-'.$i));
            $this->assertRefused(Response::HTTP_UNAUTHORIZED, 'unauthorized');
        }

        $this->call('GET', '/api/v1/platform-links/instances/self', $this->headers($instance));
        $this->assertRefused(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts');

        $this->ip = '10.255.255.'.random_int(1, 254);
        $this->call('GET', '/api/v1/platform-links/instances/self', $this->headers($instance));
        self::assertResponseIsSuccessful();
    }

    public function testTheRoutesDoNotExistWhenPlatformLinksAreOff(): void
    {
        $instance = $this->instance(PlatformInstance::STATUS_ACTIVE);
        $this->setFlag(false);

        foreach ([[], $this->headers($instance, 'not-the-secret'), $this->headers($instance)] as $headers) {
            $this->call('POST', '/api/v1/platform-links/deliveries/1/claim', $headers);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $path, array $headers): void
    {
        $this->client->request($method, $path, server: $headers + [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip,
        ], content: '{}');
    }

    /**
     * @return array<string, string>
     */
    private function headers(PlatformInstance $instance, string $secret = self::SECRET): array
    {
        return [
            'HTTP_X_INSTANCE_ID' => $instance->getInstanceId(),
            'HTTP_X_INSTANCE_SECRET' => $secret,
        ];
    }

    private function assertRefused(int $status, string $error): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertSame($error, $this->json()['error'] ?? null);
    }

    /**
     * Whatever the route answers next is its own business; it is not an
     * authentication refusal.
     */
    private function assertPastAuthentication(): void
    {
        $response = $this->client->getResponse();
        $body = json_decode((string) $response->getContent(), true);
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        self::assertNotContains($response->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN, Response::HTTP_TOO_MANY_REQUESTS], (string) $response->getContent());
        self::assertNotContains($error, self::AUTH_ERRORS, (string) $response->getContent());
    }

    private function instance(string $status): PlatformInstance
    {
        $host = 'auth-'.bin2hex(random_bytes(3)).'.example.com';
        $instance = new PlatformInstance('nextcloud', 'pi_'.bin2hex(random_bytes(6)), $host, ['https://'.$host.'/callback'], $status);
        $instance->setSecretHash(password_hash(self::SECRET, \PASSWORD_DEFAULT));
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }

    private function person(): User
    {
        $user = (new User())
            ->setMail('instance-auth-'.bin2hex(random_bytes(3)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function setFlag(bool $enabled): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, $enabled ? '1' : '0');
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
