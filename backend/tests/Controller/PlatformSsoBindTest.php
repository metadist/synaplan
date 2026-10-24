<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLogEntry;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Auth\OidcTokenService;
use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organisation-login bind and OIDC bearer tokens on chat (CF2.2).
 */
final class PlatformSsoBindTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const ISSUER = 'https://idp.example/realms/synaplan';

    private const AZP = 'synaplan-opencloud';

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

    public function testBindIsIdempotentAndRefusesAWrongClientOrATurnedOffRow(): void
    {
        $this->client->disableReboot();
        $person = $this->person();
        $this->installToken($person['sub'], $person['email']);
        $instance = $this->register();

        $this->client->request('GET', '/api/v1/platform-links/sso/status?external_id=ada', server: $this->bearer('good.token.value', $instance));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertFalse($this->json()['bound']);

        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer('good.token.value', $instance) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['external_id' => 'ada']));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, $this->client->getResponse()->getContent());
        self::assertSame(ExternalIdentity::MODE_SSO, $this->json()['mode']);
        $row = $this->row($instance['instance_id'], 'ada');
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $row->getStatus());
        self::assertSame((int) $person['id'], $row->getUserId());

        $this->em->clear();
        $audit = $this->em->getRepository(AuditLogEntry::class)->findBy([
            'actorId' => $person['id'],
            'action' => 'platform_link.bound',
        ]);
        self::assertNotEmpty($audit);

        $this->client->request('GET', '/api/v1/platform-links/sso/status?external_id=ada', server: $this->bearer('good.token.value', $instance));
        self::assertTrue($this->json()['bound']);
        self::assertSame(ExternalIdentity::MODE_SSO, $this->json()['mode']);

        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer('good.token.value', $instance) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['external_id' => 'ada']));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertCount(1, $this->rows($instance['instance_id']));

        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer('bad.azp.token', $instance) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['external_id' => 'ada']));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('azp_mismatch', $this->json()['code']);

        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: [
            'HTTP_AUTHORIZATION' => 'Bearer good.token.value',
            'HTTP_X_INSTANCE_ID' => 'pi_doesnotexist',
            'HTTP_X_INSTANCE_SECRET' => 'nope',
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode(['external_id' => 'ada']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->markStatus($row, ExternalIdentity::STATUS_PAUSED);
        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer('good.token.value', $instance) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['external_id' => 'ada']));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(PlatformDisconnectedException::CODE, $this->json()['code']);
        self::assertSame(PlatformDisconnectedException::MESSAGE, $this->json()['message']);

        $this->markStatus($row, ExternalIdentity::STATUS_REVOKED);
        $this->client->request('GET', '/v1/models', server: ['HTTP_AUTHORIZATION' => 'Bearer good.token.value']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(PlatformDisconnectedException::CODE, $this->json()['code']);
    }

    public function testBearerReachesChatAndModelsAndIsRefusedElsewhere(): void
    {
        $this->client->disableReboot();
        $person = $this->person();
        $this->installToken($person['sub'], $person['email']);
        $instance = $this->register();

        $this->client->request('GET', '/v1/models', server: ['HTTP_AUTHORIZATION' => 'Bearer good.token.value']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, $this->client->getResponse()->getContent());
        self::assertSame('list', $this->json()['object']);

        $this->client->request('POST', '/v1/chat/completions', server: [
            'HTTP_AUTHORIZATION' => 'Bearer good.token.value',
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode([
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, $this->client->getResponse()->getContent());
        self::assertSame('chat.completion', $this->json()['object']);

        $this->client->request('POST', '/v1/messages', server: [
            'HTTP_AUTHORIZATION' => 'Bearer good.token.value',
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $row = (new ExternalIdentity())
            ->setUserId($person['id'])
            ->setSource('opencloud')
            ->setInstanceId($instance['instance_id'])
            ->setExternalId('ada')
            ->setMode(ExternalIdentity::MODE_SSO)
            ->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $this->em->persist($row);
        $this->em->flush();

        $this->client->request('GET', '/v1/models', server: ['HTTP_AUTHORIZATION' => 'Bearer good.token.value']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->em->clear();
        $seen = $this->em->getRepository(ExternalIdentity::class)->find($row->getId());
        self::assertInstanceOf(ExternalIdentity::class, $seen);
        self::assertGreaterThan(0, $seen->getLastSeen());
    }

    /**
     * @return array{instance_id: string, instance_secret: string}
     */
    private function register(): array
    {
        $admin = $this->createUser('cf22-sso-admin-'.uniqid().'@synaplan.internal', 'ADMIN', 'external');
        $this->authenticateClient($this->client, $admin);
        $host = 'cf22-'.bin2hex(random_bytes(4)).'.example.com';
        $this->client->request('POST', '/api/v1/platform-links/instances', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'client' => 'opencloud',
            'host' => 'https://'.$host,
            'redirect_uris' => ['https://'.$host.'/callback'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $this->client->getResponse()->getContent());
        $body = $this->json();
        $instance = ['instance_id' => (string) $body['instance_id'], 'instance_secret' => (string) $body['instance_secret']];
        $this->client->getCookieJar()->clear();
        $this->client->request('PATCH', '/api/v1/platform-links/instances/self', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INSTANCE_ID' => $instance['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $instance['instance_secret'],
        ], content: (string) json_encode(['oidc_client_id' => self::AZP, 'server_id' => 'cloud-fixture']));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, $this->client->getResponse()->getContent());

        return $instance;
    }

    /**
     * @return array{id: int, sub: string, email: string}
     */
    private function person(): array
    {
        $sub = 'cf22-'.bin2hex(random_bytes(6));
        $email = $sub.'@synaplan.internal';
        $user = $this->createUser($email, 'ADMIN', 'keycloak');
        $oidc = (new ExternalIdentity())
            ->setUserId((int) $user->getId())
            ->setSource('oidc:'.self::ISSUER)
            ->setInstanceId('')
            ->setExternalId($sub);
        $this->em->persist($oidc);
        $this->em->flush();

        return ['id' => (int) $user->getId(), 'sub' => $sub, 'email' => $email];
    }

    private function installToken(string $sub, string $email): void
    {
        $tokens = $this->createStub(OidcTokenService::class);
        $tokens->method('validateBearerToken')->willReturnCallback(
            static function (string $token) use ($sub, $email): ?array {
                $claims = [
                    'sub' => $sub,
                    'email' => $email,
                    'iss' => self::ISSUER,
                    'preferred_username' => 'ada',
                    'azp' => self::AZP,
                ];
                if ('good.token.value' === $token) {
                    return $claims;
                }
                if ('bad.azp.token' === $token) {
                    $claims['azp'] = 'someone-else';

                    return $claims;
                }

                return null;
            }
        );
        static::getContainer()->set(OidcTokenService::class, $tokens);
    }

    /**
     * @param array{instance_id: string, instance_secret: string} $instance
     *
     * @return array<string, string>
     */
    private function bearer(string $token, array $instance): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_INSTANCE_ID' => $instance['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $instance['instance_secret'],
        ];
    }

    private function row(string $instanceId, string $externalId): ExternalIdentity
    {
        $this->em->clear();
        $row = $this->em->getRepository(ExternalIdentity::class)->findOneBy([
            'source' => 'opencloud',
            'instanceId' => $instanceId,
            'externalId' => $externalId,
        ]);
        self::assertInstanceOf(ExternalIdentity::class, $row);

        return $row;
    }

    /**
     * @return list<ExternalIdentity>
     */
    private function rows(string $instanceId): array
    {
        $this->em->clear();

        /** @var list<ExternalIdentity> $rows */
        $rows = $this->em->getRepository(ExternalIdentity::class)->findBy([
            'source' => 'opencloud',
            'instanceId' => $instanceId,
        ]);

        return $rows;
    }

    private function markStatus(ExternalIdentity $row, string $status): void
    {
        $managed = $this->em->find(ExternalIdentity::class, $row->getId());
        self::assertInstanceOf(ExternalIdentity::class, $managed);
        $managed->setStatus($status);
        $this->em->flush();
    }

    private function createUser(string $email, string $level, string $provider): User
    {
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId($provider)
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
        self::assertIsArray($decoded, (string) $this->client->getResponse()->getContent());

        return $decoded;
    }
}
