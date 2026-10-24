<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
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
 * Canonical provisioned rows, adoption, and organisation-login bind (CF2.2).
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

    public function testCoreCapabilitiesIncludeBindAndAdopt(): void
    {
        $instance = $this->register('nextcloud');
        $this->client->request('GET', '/api/v1/platform-links/instances/self', server: $this->instanceHeaders($instance));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $capabilities = $this->json()['capabilities'];
        self::assertContains('sso_bind', $capabilities);
        self::assertContains('identity_adopt', $capabilities);
    }

    public function testProvisioningWithTheCredentialWritesTheCanonicalRowAndBindsTheKey(): void
    {
        $instance = $this->register('nextcloud');
        $this->patch($instance, 'nc-srv', null);
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

    public function testAdoptionRewritesTheLegacyRowAndReplays(): void
    {
        $instance = $this->register('nextcloud');
        $this->patch($instance, 'nc-srv', null);
        $admin = $this->createUser('cf22-admin-adopt-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);
        $created = $this->provision(null, 'nextcloud', 'nc-srv:p2', 'cf22-adopt-'.uniqid().'@synaplan.internal');
        $minted = $this->mint((int) $created['user']['id'], null);
        $this->client->getCookieJar()->clear();

        $this->adopt($instance, 'p2', (string) $minted['key'], Response::HTTP_OK);
        $row = $this->oneRow('nextcloud', (string) $instance['instance_id'], 'p2');
        self::assertSame(ExternalIdentity::MODE_PROVISION, $row->getMode());
        self::assertSame($minted['id'], $row->getApiKeyId());
        self::assertNull($this->findRow('nextcloud', '', 'nc-srv:p2'));

        $this->adopt($instance, 'p2', (string) $minted['key'], Response::HTTP_OK);
        self::assertCount(1, $this->rowsFor((int) $created['user']['id'], 'nextcloud'));
    }

    public function testAdoptionRefusesSomeoneElsesKeyAndAWrongServerId(): void
    {
        $instance = $this->register('nextcloud');
        $this->patch($instance, 'nc-srv', null);
        $owner = $this->createUser('cf22-owner-'.uniqid().'@synaplan.internal');
        $this->identity($owner, 'nextcloud', '', 'nc-srv:p2', ExternalIdentity::MODE_PROVISION);
        $stranger = $this->createUser('cf22-stranger-'.uniqid().'@synaplan.internal');
        $strangerKey = $this->keyFor($stranger);
        $ownerKey = $this->keyFor($owner);

        $this->adopt($instance, 'p2', $strangerKey, Response::HTTP_FORBIDDEN);
        self::assertSame('not_owner', $this->json()['code']);
        self::assertNotNull($this->findRow('nextcloud', '', 'nc-srv:p2'));

        $this->patch($instance, 'other-srv', null);
        $this->adopt($instance, 'p2', $ownerKey, Response::HTTP_FORBIDDEN);
        self::assertSame('server_id_mismatch', $this->json()['code']);
        self::assertNotNull($this->findRow('nextcloud', '', 'nc-srv:p2'));
    }

    public function testAdoptionWithoutTheInstanceCredentialIsUnauthorized(): void
    {
        $user = $this->createUser('cf22-nokey-'.uniqid().'@synaplan.internal');
        $key = $this->keyFor($user);
        $this->client->request('POST', '/api/v1/platform-links/identities/adopt', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_API_KEY' => $key,
        ], content: (string) json_encode(['external_id' => 'p2']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAdoptionOntoAnExistingCanonicalRowBindsTheKeyAndDropsTheLegacyRow(): void
    {
        $instance = $this->register('nextcloud');
        $this->patch($instance, 'nc-srv', null);
        $user = $this->createUser('cf22-both-'.uniqid().'@synaplan.internal');
        $canonical = $this->identity($user, 'nextcloud', (string) $instance['instance_id'], 'p2', ExternalIdentity::MODE_LINK);
        $this->identity($user, 'nextcloud', '', 'nc-srv:p2', ExternalIdentity::MODE_PROVISION);
        $key = $this->keyFor($user);

        $this->adopt($instance, 'p2', $key, Response::HTTP_OK);
        $this->em->clear();
        $kept = $this->em->getRepository(ExternalIdentity::class)->find($canonical->getId());
        self::assertInstanceOf(ExternalIdentity::class, $kept);
        self::assertNotNull($kept->getApiKeyId());
        self::assertNull($this->findRow('nextcloud', '', 'nc-srv:p2'));
        self::assertCount(1, $this->rowsFor((int) $user->getId(), 'nextcloud'));
    }

    public function testTheSameBareUidOnTwoInstancesDoesNotReuseTheOtherCloudsAccount(): void
    {
        $instanceA = $this->register('nextcloud');
        $instanceB = $this->register('nextcloud');
        $this->patch($instanceA, 'srv-a', null);
        $this->patch($instanceB, 'srv-b', null);
        $admin = $this->createUser('cf22-admin-isolate-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);

        $first = $this->provision($instanceA, 'nextcloud', 'shared-uid', 'cf22-a-'.uniqid().'@synaplan.internal');
        $second = $this->provision($instanceB, 'nextcloud', 'shared-uid', 'cf22-b-'.uniqid().'@synaplan.internal');

        self::assertTrue($second['created']);
        self::assertNotSame($first['user']['id'], $second['user']['id']);
    }

    public function testAdoptionRejectsWhenTheCanonicalRowBelongsToSomeoneElse(): void
    {
        $instance = $this->register('nextcloud');
        $this->patch($instance, 'nc-srv', null);
        $owner = $this->createUser('cf22-legacy-owner-'.uniqid().'@synaplan.internal');
        $this->identity($owner, 'nextcloud', '', 'nc-srv:p2', ExternalIdentity::MODE_PROVISION);
        $other = $this->createUser('cf22-canonical-owner-'.uniqid().'@synaplan.internal');
        $this->identity($other, 'nextcloud', (string) $instance['instance_id'], 'p2', ExternalIdentity::MODE_LINK);
        $ownerKey = $this->keyFor($owner);

        $this->adopt($instance, 'p2', $ownerKey, Response::HTTP_FORBIDDEN);
        self::assertSame('not_owner', $this->json()['code']);
        self::assertNotNull($this->findRow('nextcloud', '', 'nc-srv:p2'));
    }

    public function testMintingAKeyWithoutAnIdentityOnTheInstanceDoesNotLeaveAnOrphanKey(): void
    {
        $instance = $this->register('nextcloud');
        $admin = $this->createUser('cf22-admin-mint-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);
        $user = $this->createUser('cf22-no-row-'.uniqid().'@synaplan.internal');
        $before = count($this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $user->getId()]));

        $this->client->request('POST', '/api/v1/admin/users/'.$user->getId().'/api-keys', server: $this->instanceHeaders($instance) + [
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode([
            'name' => 'cf22',
            'scopes' => ['chat'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->em->clear();
        self::assertSame($before, count($this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $user->getId()])));
    }

    public function testProvisioningAfterAdoptionDoesNotWriteASecondRow(): void
    {
        $instance = $this->register('nextcloud');
        $this->patch($instance, 'nc-srv', null);
        $admin = $this->createUser('cf22-admin-second-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);
        $email = 'cf22-kept-'.uniqid().'@synaplan.internal';
        $created = $this->provision(null, 'nextcloud', 'nc-srv:p2', $email);
        $minted = $this->mint((int) $created['user']['id'], null);
        $this->client->getCookieJar()->clear();
        $this->adopt($instance, 'p2', (string) $minted['key'], Response::HTTP_OK);

        $this->authenticateClient($this->client, $admin);
        $again = $this->provision(null, 'nextcloud', 'nc-srv:p2', $email);
        self::assertFalse($again['created']);
        self::assertSame($created['user']['id'], $again['user']['id']);
        self::assertCount(1, $this->rowsFor((int) $created['user']['id'], 'nextcloud'));
        self::assertNotNull($this->findRow('nextcloud', (string) $instance['instance_id'], 'p2'));
    }

    /**
     * @return array{instance_id: string, instance_secret: string}
     */
    private function register(string $client): array
    {
        $admin = $this->createUser('cf22-reg-'.uniqid().'@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);
        $host = 'cf22-'.bin2hex(random_bytes(4)).'.example.com';
        $this->client->request('POST', '/api/v1/platform-links/instances', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'client' => $client,
            'host' => 'https://'.$host,
            'redirect_uris' => ['https://'.$host.'/apps/synaplan_integration/link/callback'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        $this->client->getCookieJar()->clear();

        return ['instance_id' => (string) $body['instance_id'], 'instance_secret' => (string) $body['instance_secret']];
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     */
    private function patch(array $instance, ?string $serverId, ?string $oidcClientId): void
    {
        $body = [];
        if (null !== $serverId) {
            $body['server_id'] = $serverId;
        }
        if (null !== $oidcClientId) {
            $body['oidc_client_id'] = $oidcClientId;
        }
        $this->client->request('PATCH', '/api/v1/platform-links/instances/self', server: $this->instanceHeaders($instance) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, $this->client->getResponse()->getContent());
    }

    /**
     * @param array{instance_id: string, instance_secret: string}|null $instance
     *
     * @return array{created: bool, user: array{id: int}}
     */
    private function provision(?array $instance, string $source, string $externalId, string $email): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $instance) {
            $server += $this->instanceHeaders($instance);
        }
        $this->client->request('POST', '/api/v1/admin/users', server: $server, content: (string) json_encode([
            'source' => $source,
            'external_id' => $externalId,
            'email' => $email,
        ]));
        self::assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_OK, Response::HTTP_CREATED], $this->client->getResponse()->getContent());
        $body = $this->json();

        return ['created' => (bool) $body['created'], 'user' => ['id' => (int) $body['user']['id']]];
    }

    /**
     * @param array{instance_id: string, instance_secret: string}|null $instance
     *
     * @return array{id: int, key: string}
     */
    private function mint(int $userId, ?array $instance): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $instance) {
            $server += $this->instanceHeaders($instance);
        }
        $this->client->request('POST', '/api/v1/admin/users/'.$userId.'/api-keys', server: $server, content: (string) json_encode([
            'name' => 'cf22',
            'scopes' => ['chat', 'files', 'rag'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $this->client->getResponse()->getContent());
        $body = $this->json()['api_key'];

        return ['id' => (int) $body['id'], 'key' => (string) $body['key']];
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     */
    private function adopt(array $instance, string $externalId, string $key, int $expected): void
    {
        $this->client->request('POST', '/api/v1/platform-links/identities/adopt', server: $this->instanceHeaders($instance) + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_API_KEY' => $key,
        ], content: (string) json_encode(['external_id' => $externalId]));
        self::assertResponseStatusCodeSame($expected, $this->client->getResponse()->getContent());
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     *
     * @return array<string, string>
     */
    private function instanceHeaders(array $instance): array
    {
        return [
            'HTTP_X_INSTANCE_ID' => $instance['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $instance['instance_secret'],
        ];
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

    private function identity(User $user, string $source, string $instanceId, string $externalId, string $mode): ExternalIdentity
    {
        $row = (new ExternalIdentity())
            ->setUserId((int) $user->getId())
            ->setSource($source)
            ->setInstanceId($instanceId)
            ->setExternalId($externalId)
            ->setMode($mode);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function keyFor(User $user): string
    {
        $plain = 'sk_'.bin2hex(random_bytes(29));
        $key = (new ApiKey())
            ->setOwner($user)
            ->setKey($plain)
            ->setName('cf22')
            ->setStatus('active')
            ->setScopes(['chat', 'files', 'rag']);
        $this->em->persist($key);
        $this->em->flush();

        return $plain;
    }

    private function createUser(string $email, string $level = 'NEW'): User
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
