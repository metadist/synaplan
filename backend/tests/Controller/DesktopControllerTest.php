<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\DesktopDevice;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Security\ApiKeyScope;
use App\Service\Desktop\DesktopAgentConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional coverage of the desktop pairing + job REST surface: flag-gating
 * (404 when off, C8), ownership isolation (another user's device is 404), and
 * the pair → device → revoke lifecycle.
 */
final class DesktopControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    private function enableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, DesktopAgentConfig::CONFIG_GROUP, DesktopAgentConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    public function testCreatePairingCodeIs404WhenFlagOff(): void
    {
        $user = $this->createUser('desktop-rest-off@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->client->request('POST', '/api/v1/desktop/pairing-codes');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testEnqueueJobIs404WhenFlagOff(): void
    {
        $user = $this->createUser('desktop-rest-enq-off@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/desktop/jobs', ['type' => 'skill.run', 'input' => ['skill' => 'pptx']]);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testPairExchangeMintsScopedKeyAndDevice(): void
    {
        $this->enableFlag();
        $user = $this->createUser('desktop-rest-pair@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->client->request('POST', '/api/v1/desktop/pairing-codes');
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $code = $this->json()['code'];
        self::assertNotEmpty($code);

        // The /pair exchange is unauthenticated (a fresh client has no cookie).
        $this->client->getCookieJar()->clear();
        $this->postJson('/api/v1/desktop/pair', ['code' => $code, 'deviceName' => 'CI laptop', 'capabilities' => ['skill.run']]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertStringStartsWith('sk_', $body['key']);
        self::assertGreaterThan(0, $body['deviceId']);

        $device = $this->em->getRepository(DesktopDevice::class)->find($body['deviceId']);
        self::assertInstanceOf(DesktopDevice::class, $device);
        self::assertSame((int) $user->getId(), $device->getOwnerId());

        $apiKey = $this->em->getRepository(ApiKey::class)->findOneBy(['key' => $body['key']]);
        self::assertInstanceOf(ApiKey::class, $apiKey);
        self::assertSame(ApiKeyScope::pairingScopes(), $apiKey->getScopes());
        self::assertTrue(ApiKeyScope::isRestricted($apiKey->getScopes()));
    }

    public function testPairWithInvalidCodeReturns400(): void
    {
        $this->enableFlag();

        $this->postJson('/api/v1/desktop/pair', ['code' => 'ZZZZZZZZ']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testEnqueueForOwnDeviceSucceeds(): void
    {
        $this->enableFlag();
        $user = $this->createUser('desktop-rest-own@synaplan.internal');
        $device = $this->createDevice($user);
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/desktop/jobs', [
            'deviceId' => $device->getId(),
            'type' => 'skill.run',
            'input' => ['skill' => 'pptx', 'prompt' => 'Make slides'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertTrue($body['success']);
        self::assertSame('queued', $body['status']);
    }

    public function testEnqueueForOtherUsersDeviceReturns404(): void
    {
        $this->enableFlag();
        $owner = $this->createUser('desktop-rest-owner@synaplan.internal');
        $device = $this->createDevice($owner);

        $attacker = $this->createUser('desktop-rest-attacker@synaplan.internal');
        $this->authenticateClient($this->client, $attacker);

        $this->postJson('/api/v1/desktop/jobs', [
            'deviceId' => $device->getId(),
            'type' => 'skill.run',
            'input' => ['skill' => 'pptx'],
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testEnqueueRejectsInvalidSkillName(): void
    {
        $this->enableFlag();
        $user = $this->createUser('desktop-rest-badskill@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/desktop/jobs', ['type' => 'skill.run', 'input' => ['skill' => 'Not A Skill!']]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testEnqueueRejectsUnknownType(): void
    {
        $this->enableFlag();
        $user = $this->createUser('desktop-rest-badtype@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/desktop/jobs', ['type' => 'shell.exec', 'input' => ['skill' => 'pptx']]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testListAndRevokeDevice(): void
    {
        $this->enableFlag();
        $user = $this->createUser('desktop-rest-revoke@synaplan.internal');
        $device = $this->createDevice($user);
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', '/api/v1/desktop/devices');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $devices = $this->json()['devices'];
        self::assertCount(1, $devices);
        self::assertSame((int) $device->getId(), $devices[0]['id']);

        $this->client->request('DELETE', '/api/v1/desktop/devices/'.$device->getId());
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(DesktopDevice::class)->find($device->getId());
        self::assertInstanceOf(DesktopDevice::class, $reloaded);
        self::assertSame(DesktopDevice::STATUS_REVOKED, $reloaded->getStatus());
        // The backing key is gone, so the laptop's next request 401s.
        self::assertNull($this->em->getRepository(ApiKey::class)->find($device->getApiKeyId()));
    }

    public function testRevokeOtherUsersDeviceReturns404(): void
    {
        $this->enableFlag();
        $owner = $this->createUser('desktop-rest-rev-owner@synaplan.internal');
        $device = $this->createDevice($owner);

        $attacker = $this->createUser('desktop-rest-rev-attacker@synaplan.internal');
        $this->authenticateClient($this->client, $attacker);

        $this->client->request('DELETE', '/api/v1/desktop/devices/'.$device->getId());

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    private function createUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('desktop-rest-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createDevice(User $owner): DesktopDevice
    {
        $apiKey = (new ApiKey())
            ->setOwner($owner)
            ->setKey('sk_'.bin2hex(random_bytes(20)))
            ->setStatus('active')
            ->setName('Desktop test key')
            ->setScopes(ApiKeyScope::pairingScopes());
        $this->em->persist($apiKey);
        $this->em->flush();

        $device = (new DesktopDevice())
            ->setOwnerId((int) $owner->getId())
            ->setName('Owned laptop')
            ->setApiKeyId((int) $apiKey->getId())
            ->setStatus(DesktopDevice::STATUS_ACTIVE)
            ->setCapabilities(['skill.run']);
        $this->em->persist($device);
        $this->em->flush();

        return $device;
    }
}
