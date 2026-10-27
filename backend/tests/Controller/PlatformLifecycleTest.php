<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Admin\AdminUserProvisioningService;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deleting, disabling and enabling a person in one cloud (CF2.4).
 */
final class PlatformLifecycleTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    public function testASoleProvisionedAccountIsDeletedAndARepeatIsHarmless(): void
    {
        $instance = $this->register();
        $user = $this->provision($instance, 'sole');

        $this->post($instance, 'sole', 'deleted');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('deleted', $this->json()['account']);
        $this->em->clear();
        self::assertNull($this->em->find(User::class, $user->getId()));

        $this->post($instance, 'sole', 'deleted');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('absent', $this->json()['account']);
    }

    public function testAnUnboundKeyDoesNotKeepASoleProvisionedAccount(): void
    {
        $instance = $this->register();
        $user = $this->provision($instance, 'unbound');
        $row = $this->identity($instance, 'unbound');
        $row->setApiKeyId(null);
        $this->em->flush();

        $this->post($instance, 'unbound', 'deleted');
        self::assertSame('deleted', $this->json()['account']);
        $this->em->clear();
        self::assertNull($this->em->find(User::class, $user->getId()));
    }

    public function testAPasswordAnAdminRoleOrAnotherIdentityKeepsTheAccount(): void
    {
        $instance = $this->register();

        $withPassword = $this->provision($instance, 'pw', 'password-1');
        $this->post($instance, 'pw', 'deleted');
        self::assertSame('kept', $this->json()['account']);
        self::assertStringContainsString('password', $this->json()['message']);
        $this->em->clear();
        self::assertInstanceOf(User::class, $this->em->find(User::class, $withPassword->getId()));
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $this->identity($instance, 'pw')->getStatus());

        $admin = $this->provision($instance, 'adm');
        $admin->setUserLevel('ADMIN');
        $this->em->flush();
        $this->post($instance, 'adm', 'deleted');
        self::assertSame('kept', $this->json()['account']);
        self::assertStringContainsString('administrator', $this->json()['message']);

        $shared = $this->provision($instance, 'two');
        $other = (new ExternalIdentity())
            ->setUserId((int) $shared->getId())
            ->setSource('oidc:https://idp.example')
            ->setInstanceId('')
            ->setExternalId('oidc-two')
            ->setMode(ExternalIdentity::MODE_SSO)
            ->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $this->em->persist($other);
        $this->em->flush();
        $this->post($instance, 'two', 'deleted');
        self::assertSame('kept', $this->json()['account']);
        self::assertStringContainsString('also used from', $this->json()['message']);
        $this->em->clear();
        self::assertInstanceOf(User::class, $this->em->find(User::class, $shared->getId()));
    }

    public function testLinkedAndOrganisationIdentitiesAreRevokedWithoutDeletingTheAccount(): void
    {
        $instance = $this->register();
        $linked = $this->localPerson('linked');
        $this->attach($linked, $instance, 'linked', ExternalIdentity::MODE_LINK);
        $this->post($instance, 'linked', 'deleted');
        self::assertSame('kept', $this->json()['account']);
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $this->identity($instance, 'linked')->getStatus());
        self::assertInstanceOf(User::class, $this->em->find(User::class, $linked->getId()));

        $sso = $this->localPerson('sso');
        $this->attach($sso, $instance, 'sso', ExternalIdentity::MODE_SSO);
        $this->post($instance, 'sso', 'disabled');
        self::assertSame(ExternalIdentity::STATUS_SUSPENDED, $this->json()['status']);
        $this->post($instance, 'sso', 'enabled');
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $this->json()['status']);
        $this->post($instance, 'sso', 'enabled');
        self::assertSame('unchanged', $this->json()['account']);
        self::assertInstanceOf(User::class, $this->em->find(User::class, $sso->getId()));
    }

    public function testAnApiKeyDeleteRefusesAKeptAccountAndASessionAdminDoesNot(): void
    {
        $instance = $this->register();
        $kept = $this->provision($instance, 'api', 'password-1');
        $admin = $this->localPerson('admin-actor');
        $admin->setUserLevel('ADMIN');
        $secret = 'sk_'.bin2hex(random_bytes(29));
        $key = new ApiKey();
        $key->setOwner($admin);
        $key->setKey($secret);
        $key->setName('admin');
        $key->setStatus('active');
        $key->setScopes([]);
        $this->em->persist($key);
        $this->em->flush();

        $this->client->request('DELETE', '/api/v1/admin/users/'.$kept->getId(), server: ['HTTP_X_API_KEY' => $secret]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, (string) $this->client->getResponse()->getContent());
        self::assertSame('account_in_use', $this->json()['code']);
        $this->em->clear();
        self::assertInstanceOf(User::class, $this->em->find(User::class, $kept->getId()));

        $sessionTarget = $this->provision($instance, 'session', 'password-1');
        $this->authenticateClient($this->client, $this->em->find(User::class, $admin->getId()) ?? $admin);
        $this->client->request('DELETE', '/api/v1/admin/users/'.$sessionTarget->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertNull($this->em->find(User::class, $sessionTarget->getId()));
    }

    public function testAPausedIdentityRejectsItsKeyUntilItIsEnabled(): void
    {
        $instance = $this->register();
        $user = $this->provision($instance, 'paused', 'password-1');
        $user->setUserLevel('PRO');
        $this->em->flush();
        $entity = $this->em->getRepository(PlatformInstance::class)->findOneBy(['instanceId' => $instance['instance_id']]);
        self::assertInstanceOf(PlatformInstance::class, $entity);
        $minted = static::getContainer()->get(AdminUserProvisioningService::class)->mintApiKeyForUser($user, 'cloud', ['files'], $entity);
        $secret = $minted['plainKey'];

        $this->post($instance, 'paused', 'disabled');
        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_X_API_KEY' => $secret]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('platform_disconnected', $this->json()['code']);

        $this->post($instance, 'paused', 'enabled');
        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_X_API_KEY' => $secret]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testALinkedIdentityCannotDeleteTheAccount(): void
    {
        $instance = $this->register();
        $user = $this->provision($instance, 'linked-mode');
        $row = $this->identity($instance, 'linked-mode');
        $row->setMode(ExternalIdentity::MODE_LINK);
        $this->em->flush();

        $this->post($instance, 'linked-mode', 'deleted');
        self::assertSame('kept', $this->json()['account']);
        self::assertStringContainsString('not created for this cloud', $this->json()['message']);
        $this->em->clear();
        self::assertInstanceOf(User::class, $this->em->find(User::class, $user->getId()));
    }

    public function testALegacyProvisionedAccountCanStillBeDeletedByAnApiKey(): void
    {
        $this->register();
        $email = 'legacy-'.bin2hex(random_bytes(3)).'@synaplan.internal';
        $created = static::getContainer()->get(AdminUserProvisioningService::class)->provision('nextcloud', 'legacy-person', $email);
        $user = $created['user'];
        $admin = $this->localPerson('legacy-admin');
        $admin->setUserLevel('ADMIN');
        $secret = 'sk_'.bin2hex(random_bytes(29));
        $key = new ApiKey();
        $key->setOwner($admin);
        $key->setKey($secret);
        $key->setName('admin');
        $key->setStatus('active');
        $key->setScopes([]);
        $this->em->persist($key);
        $this->em->flush();

        $encoded = implode('', array_map(static fn (string $digit): string => '%3'.$digit, str_split((string) $user->getId())));
        $this->client->request('DELETE', '/api/v1/admin/users/'.$encoded, server: ['HTTP_X_API_KEY' => $secret]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertNull($this->em->find(User::class, $user->getId()));
    }

    public function testInstancesSelfAdvertisesLifecycle(): void
    {
        $instance = $this->register();
        $this->client->request('GET', '/api/v1/platform-links/instances/self', server: [
            'HTTP_X_INSTANCE_ID' => $instance['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $instance['instance_secret'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertContains('lifecycle', $this->json()['capabilities']);
    }

    public function testTurnOffAndCloudDisableDoNotUndoEachOther(): void
    {
        $instance = $this->register();
        $sso = $this->localPerson('split');
        $userId = (int) $sso->getId();
        $this->attach($sso, $instance, 'split', ExternalIdentity::MODE_SSO);
        $linkId = (int) $this->identity($instance, 'split')->getId();

        $this->asPerson($userId);
        $this->client->request('POST', '/api/v1/me/platform-links/'.$linkId.'/turn-off');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->client->getCookieJar()->clear();
        $this->post($instance, 'split', 'enabled');
        self::assertSame(ExternalIdentity::STATUS_PAUSED, $this->identity($instance, 'split')->getStatus());

        $this->asPerson($userId);
        $this->client->request('POST', '/api/v1/me/platform-links/'.$linkId.'/resume');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $this->identity($instance, 'split')->getStatus());
        $this->client->getCookieJar()->clear();

        $this->post($instance, 'split', 'disabled');
        self::assertSame(ExternalIdentity::STATUS_SUSPENDED, $this->identity($instance, 'split')->getStatus());
        $this->asPerson($userId);
        $this->client->request('POST', '/api/v1/me/platform-links/'.$linkId.'/resume');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('cloud_disabled', $this->json()['code']);
        $this->client->request('POST', '/api/v1/me/platform-links/'.$linkId.'/turn-off');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(ExternalIdentity::STATUS_BLOCKED, $this->identity($instance, 'split')->getStatus());
        $this->client->request('POST', '/api/v1/me/platform-links/'.$linkId.'/resume');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(ExternalIdentity::STATUS_SUSPENDED, $this->identity($instance, 'split')->getStatus());
        $this->client->getCookieJar()->clear();

        $this->post($instance, 'split', 'deleted');
        $this->asPerson($userId);
        $this->client->request('POST', '/api/v1/me/platform-links/'.$linkId.'/resume');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('identity_revoked', $this->json()['code']);
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     */
    private function post(array $instance, string $uid, string $event): void
    {
        $this->client->request('POST', '/api/v1/platform-links/lifecycle', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INSTANCE_ID' => $instance['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $instance['instance_secret'],
        ], content: (string) json_encode(['external_id' => $uid, 'event' => $event]));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return array{instance_id: string, instance_secret: string}
     */
    private function register(): array
    {
        $admin = $this->localPerson('registrar');
        $admin->setUserLevel('ADMIN');
        $this->em->flush();
        $this->authenticateClient($this->client, $admin);
        $host = 'cf24-'.bin2hex(random_bytes(3)).'.example.com';
        $this->client->request('POST', '/api/v1/platform-links/instances', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'client' => 'nextcloud',
            'host' => 'https://'.$host,
            'redirect_uris' => ['https://'.$host.'/callback'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $body = $this->json();
        $this->client->getCookieJar()->clear();

        return ['instance_id' => (string) $body['instance_id'], 'instance_secret' => (string) $body['instance_secret']];
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     */
    private function provision(array $instance, string $uid, ?string $password = null): User
    {
        $entity = $this->em->getRepository(PlatformInstance::class)->findOneBy(['instanceId' => $instance['instance_id']]);
        self::assertInstanceOf(PlatformInstance::class, $entity);
        $result = static::getContainer()->get(AdminUserProvisioningService::class)->provision(
            'nextcloud',
            $uid,
            $uid.'-'.bin2hex(random_bytes(3)).'@synaplan.internal',
            plainPassword: $password,
            instance: $entity,
        );

        return $result['user'];
    }

    private function asPerson(int $userId): void
    {
        $user = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);
        $this->authenticateClient($this->client, $user);
    }

    private function localPerson(string $prefix): User
    {
        $user = (new User())
            ->setMail($prefix.'-'.bin2hex(random_bytes(3)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     */
    private function attach(User $user, array $instance, string $uid, string $mode): void
    {
        $row = (new ExternalIdentity())
            ->setUserId((int) $user->getId())
            ->setSource('nextcloud')
            ->setInstanceId($instance['instance_id'])
            ->setExternalId($uid)
            ->setMode($mode)
            ->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $this->em->persist($row);
        $this->em->flush();
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     */
    private function identity(array $instance, string $uid): ExternalIdentity
    {
        $this->em->clear();
        $row = $this->em->getRepository(ExternalIdentity::class)->findOneBy([
            'source' => 'nextcloud',
            'instanceId' => $instance['instance_id'],
            'externalId' => $uid,
        ]);
        self::assertInstanceOf(ExternalIdentity::class, $row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, (string) $this->client->getResponse()->getContent());

        return $decoded;
    }
}
