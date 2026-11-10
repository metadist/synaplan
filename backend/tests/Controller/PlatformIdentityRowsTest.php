<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical provisioned rows: an administrator provisions for a cloud named by
 * its OAuth client id (CF2.2).
 */
final class PlatformIdentityRowsTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $redis = static::getContainer()->get(RedisService::class);
        $redis->delete('platform_link:register_attempt:'.sha1('127.0.0.1'));
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    public function testProvisioningWithTheCredentialWritesTheCanonicalRowAndBindsTheKey(): void
    {
        $instance = $this->register('nextcloud');
        $admin = $this->createUser('cf22-admin-canon-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        $created = $this->provision($instance, 'nextcloud', 'p1', 'cf22-p1-'.uniqid().'@synaplan.internal');
        self::assertTrue($created['created']);
        $row = $this->oneRow('nextcloud', (string) $instance['instance_id'], 'p1');
        self::assertSame(ExternalIdentity::MODE_PROVISION, $row->getMode());
        self::assertNull($row->getApiKeyId());

        $minted = $this->mint((int) $created['user']['id'], $instance);
        $this->em->clear();
        $bound = $this->oneRow('nextcloud', (string) $instance['instance_id'], 'p1');
        self::assertSame($minted['id'], $bound->getApiKeyId());

        $again = $this->provision($instance, 'nextcloud', 'p1', 'cf22-p1-other-'.uniqid().'@synaplan.internal');
        self::assertFalse($again['created']);
        self::assertSame($created['user']['id'], $again['user']['id']);
        self::assertCount(1, $this->rowsFor((int) $created['user']['id'], 'nextcloud'));
    }

    public function testProvisioningWithoutTheCredentialKeepsTheLegacyRow(): void
    {
        $admin = $this->createUser('cf22-admin-legacy-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        $created = $this->provision(null, 'nextcloud', 'nc-srv:p2', 'cf22-p2-'.uniqid().'@synaplan.internal');
        $row = $this->oneRow('nextcloud', '', 'nc-srv:p2');
        self::assertSame(ExternalIdentity::MODE_PROVISION, $row->getMode());
        self::assertSame((int) $created['user']['id'], $row->getUserId());

        $minted = $this->mint((int) $created['user']['id'], null);
        $this->em->clear();
        self::assertNull($this->oneRow('nextcloud', '', 'nc-srv:p2')->getApiKeyId());
        self::assertGreaterThan(0, $minted['id']);
    }

    public function testTheSameBareUidOnTwoInstancesDoesNotReuseTheOtherCloudsAccount(): void
    {
        $instanceA = $this->register('nextcloud');
        $instanceB = $this->register('nextcloud');
        $admin = $this->createUser('cf22-admin-isolate-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        $first = $this->provision($instanceA, 'nextcloud', 'shared-uid', 'cf22-a-'.uniqid().'@synaplan.internal');
        $second = $this->provision($instanceB, 'nextcloud', 'shared-uid', 'cf22-b-'.uniqid().'@synaplan.internal');

        self::assertTrue($second['created']);
        self::assertNotSame($first['user']['id'], $second['user']['id']);
    }

    public function testMintingAKeyWithoutAnIdentityOnTheInstanceDoesNotLeaveAnOrphanKey(): void
    {
        $instance = $this->register('nextcloud');
        $admin = $this->createUser('cf22-admin-mint-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);
        $user = $this->createUser('cf22-no-row-'.uniqid().'@synaplan.internal');
        $before = count($this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $user->getId()]));

        $this->client->request('POST', '/api/v1/admin/users/'.$user->getId().'/api-keys', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode([
            'instance_id' => $instance['instance_id'],
            'name' => 'cf22',
            'scopes' => ['chat'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->em->clear();
        self::assertSame($before, count($this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $user->getId()])));
    }

    /**
     * @return array{instance_id: string}
     */
    private function register(string $client): array
    {
        $host = 'cf22-'.bin2hex(random_bytes(4)).'.example.com';
        $instance = new PlatformInstance($client, 'pi_'.bin2hex(random_bytes(12)), $host, ['https://'.$host.'/apps/synaplan_integration/oauth/callback'], PlatformInstance::STATUS_ACTIVE);
        $this->em->persist($instance);
        $this->em->flush();

        return ['instance_id' => $instance->getInstanceId()];
    }

    /**
     * @param array{instance_id: string}|null $instance
     *
     * @return array{created: bool, user: array{id: int}}
     */
    private function provision(?array $instance, string $source, string $externalId, string $email): array
    {
        $this->client->request('POST', '/api/v1/admin/users', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'instance_id' => $instance['instance_id'] ?? null,
            'source' => $source,
            'external_id' => $externalId,
            'email' => $email,
            'level' => 'PRO',
        ]));
        self::assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_OK, Response::HTTP_CREATED], $this->client->getResponse()->getContent());
        $body = $this->json();

        return ['created' => (bool) $body['created'], 'user' => ['id' => (int) $body['user']['id']]];
    }

    /**
     * @param array{instance_id: string}|null $instance
     *
     * @return array{id: int, key: string}
     */
    private function mint(int $userId, ?array $instance): array
    {
        $this->client->request('POST', '/api/v1/admin/users/'.$userId.'/api-keys', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'instance_id' => $instance['instance_id'] ?? null,
            'name' => 'cf22',
            'scopes' => ['chat', 'files', 'rag'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $this->client->getResponse()->getContent());
        $body = $this->json()['api_key'];

        return ['id' => (int) $body['id'], 'key' => (string) $body['key']];
    }

    private function oneRow(string $source, string $instanceId, string $externalId): ExternalIdentity
    {
        $row = $this->findRow($source, $instanceId, $externalId);
        self::assertInstanceOf(ExternalIdentity::class, $row);

        return $row;
    }

    private function findRow(string $source, string $instanceId, string $externalId): ?ExternalIdentity
    {
        $this->em->clear();
        $row = $this->em->getRepository(ExternalIdentity::class)->findOneBy([
            'source' => $source,
            'instanceId' => $instanceId,
            'externalId' => $externalId,
        ]);

        return $row instanceof ExternalIdentity ? $row : null;
    }

    /**
     * @return list<ExternalIdentity>
     */
    private function rowsFor(int $userId, string $source): array
    {
        $this->em->clear();

        /** @var list<ExternalIdentity> $rows */
        $rows = $this->em->getRepository(ExternalIdentity::class)->findBy([
            'userId' => $userId,
            'source' => $source,
        ]);

        return $rows;
    }

    private function createUser(string $email, string $level = 'PRO'): User
    {
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('external')
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
