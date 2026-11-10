<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CloudDelivery;
use App\Entity\CloudPickup;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Auth\OidcTokenService;
use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A connected cloud acting with the person's own token, without the instance
 * secret.
 */
final class PlatformPersonTokensTest extends WebTestCase
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
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(RedisService::class)->delete('platform_link:register_attempt:'.sha1('127.0.0.1'));
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    public function testAPersonBindsWithoutTheInstanceSecretAsTheTokenSubject(): void
    {
        $person = $this->person();
        $this->installTokens(['ada.token.value' => $person]);
        $instance = $this->register();

        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer('ada.token.value') + ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());

        $row = $this->row($instance, $person['sub']);
        self::assertSame($person['id'], $row->getUserId());
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $row->getStatus());

        $this->client->request('GET', '/api/v1/platform-links/sso/status', server: $this->bearer('ada.token.value'));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['bound']);
    }

    public function testAPersonCannotBindAnotherUserId(): void
    {
        $person = $this->person();
        $this->installTokens(['ada.token.value' => $person]);
        $this->register();

        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer('ada.token.value') + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['external_id' => 'somebody-else']));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('external_id_mismatch', $this->json()['error']);
    }

    public function testATokenOfAnUnregisteredClientReachesNoCloud(): void
    {
        $person = $this->person();
        $this->installTokens(['other.token.value' => $person + ['azp' => 'someone-else']]);
        $this->register();

        $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer('other.token.value') + ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('platform_disconnected', $this->json()['error']);
    }

    public function testThePersonReportsOnlyTheirOwnReadScope(): void
    {
        $ada = $this->person();
        $bob = $this->person();
        $this->installTokens(['ada.token.value' => $ada, 'bob.token.value' => $bob]);
        $instance = $this->register();
        foreach (['ada.token.value', 'bob.token.value'] as $token) {
            $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer($token) + ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseIsSuccessful();
        }

        $this->client->request('PUT', '/api/v1/platform-links/read-scope', server: $this->bearer('ada.token.value') + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['external_id' => $ada['sub'], 'mode' => 'all', 'folders' => []]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('all', $this->row($instance, $ada['sub'])->readScope()['mode']);

        $this->client->request('PUT', '/api/v1/platform-links/read-scope', server: $this->bearer('ada.token.value') + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['external_id' => $bob['sub'], 'mode' => 'all', 'folders' => []]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('unknown_identity', $this->json()['error']);
    }

    public function testAnotherPersonsDeliveryAndPickupLookLikeNoneAtAll(): void
    {
        $ada = $this->person();
        $bob = $this->person();
        $this->installTokens(['ada.token.value' => $ada, 'bob.token.value' => $bob]);
        $instance = $this->register();
        foreach (['ada.token.value', 'bob.token.value'] as $token) {
            $this->client->request('POST', '/api/v1/platform-links/sso/bind', server: $this->bearer($token) + ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseIsSuccessful();
        }
        $bobsRow = $this->row($instance, $bob['sub']);
        $delivery = new CloudDelivery($bob['id'], 1, (int) $bobsRow->getId(), CloudDelivery::TARGET_KIND_FOLDER, null, CloudDelivery::CONFLICT_RENAME);
        $pickup = new CloudPickup($bob['id'], (int) $bobsRow->getId(), $instance, CloudPickup::MODE_LIST, ['folder_path' => 'Invoices']);
        $this->em->persist($delivery);
        $this->em->persist($pickup);
        $this->em->flush();

        foreach ([
            '/api/v1/platform-links/deliveries/'.$delivery->getId().'/claim',
            '/api/v1/platform-links/pickups/'.$pickup->getId().'/claim',
            '/api/v1/platform-links/deliveries/999999999/claim',
        ] as $path) {
            $this->client->request('POST', $path, server: $this->bearer('ada.token.value') + ['CONTENT_TYPE' => 'application/json'], content: '{}');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $path);
            self::assertSame('unknown_delivery', $this->json()['error'], $path);
        }
    }

    public function testTwoActiveCloudsOnOneClientAreAmbiguous(): void
    {
        $person = $this->person();
        $this->installTokens(['ada.token.value' => $person]);
        $this->register();
        $this->register();

        $this->client->request('POST', '/api/v1/platform-links/deliveries/1/claim', server: $this->bearer('ada.token.value'));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('platform_ambiguous', $this->json()['error']);
    }

    public function testABrowserSessionReachesNoCloudRoute(): void
    {
        $person = $this->person();
        $user = $this->em->find(User::class, $person['id']);
        self::assertInstanceOf(User::class, $user);
        $this->authenticateClient($this->client, $user);

        $this->client->request('POST', '/api/v1/platform-links/deliveries/1/claim');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('platform_disconnected', $this->json()['error']);
    }

    public function testAPersonsTokenOpensNoInstanceRoute(): void
    {
        $person = $this->person();
        $this->installTokens(['ada.token.value' => $person]);
        $this->register();

        $this->client->request('GET', '/api/v1/platform-links/instances/self', server: $this->bearer('ada.token.value'));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @return array{id: int, sub: string, email: string}
     */
    private function person(): array
    {
        $sub = 'pt-'.bin2hex(random_bytes(6));
        $email = $sub.'@synaplan.internal';
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('keycloak')
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->persist((new ExternalIdentity())
            ->setUserId((int) $user->getId())
            ->setSource('oidc:'.self::ISSUER)
            ->setInstanceId('')
            ->setExternalId($sub));
        $this->em->flush();

        return ['id' => (int) $user->getId(), 'sub' => $sub, 'email' => $email];
    }

    /**
     * Registers an active cloud and announces the OIDC client, the way the
     * cloud's admin does it.
     */
    private function register(): string
    {
        $admin = (new User())
            ->setMail('pt-admin-'.bin2hex(random_bytes(4)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel('ADMIN');
        $admin->setCreated(date('YmdHis'));
        $admin->setEmailVerified(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->authenticateClient($this->client, $admin);
        $host = 'pt-'.bin2hex(random_bytes(4)).'.example.com';
        $this->client->request('POST', '/api/v1/platform-links/instances', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'client' => 'opencloud',
            'host' => 'https://'.$host,
            'redirect_uris' => ['https://'.$host.'/callback'],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $instance = $this->json();
        $this->client->getCookieJar()->clear();
        $this->client->request('PATCH', '/api/v1/platform-links/instances/self', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INSTANCE_ID' => (string) $instance['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => (string) $instance['instance_secret'],
        ], content: (string) json_encode(['oidc_client_id' => self::AZP]));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return (string) $instance['instance_id'];
    }

    /**
     * Person tokens validate as Synaplan bearers (azp: the people's client).
     * Nothing else is accepted.
     *
     * @param array<string, array{sub: string, email: string, azp?: string}> $peopleByToken
     */
    private function installTokens(array $peopleByToken): void
    {
        $tokens = $this->createStub(OidcTokenService::class);
        $tokens->method('validateBearerToken')->willReturnCallback(
            static function (string $token) use ($peopleByToken): ?array {
                $person = $peopleByToken[$token] ?? null;
                if (!is_array($person)) {
                    return null;
                }

                return [
                    'sub' => $person['sub'],
                    'email' => $person['email'],
                    'iss' => self::ISSUER,
                    'preferred_username' => $person['sub'],
                    'azp' => $person['azp'] ?? self::AZP,
                ];
            }
        );
        static::getContainer()->set(OidcTokenService::class, $tokens);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
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
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
