<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CloudDelivery;
use App\Entity\CloudPickup;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
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

    public function testAPersonsFirstCallLinksThemAsTheTokenSubject(): void
    {
        $person = $this->person();
        $this->installTokens(['ada.token.value' => $person]);
        $instance = $this->register();

        $this->use('ada.token.value');

        $row = $this->row($instance, $person['sub']);
        self::assertSame($person['id'], $row->getUserId());
        self::assertSame(ExternalIdentity::MODE_SSO, $row->getMode());
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $row->getStatus());
    }

    public function testACloudAccountLinkedToSomeoneElseIsNotTakenOver(): void
    {
        $ada = $this->person();
        $bob = $this->person();
        $this->installTokens(['ada.token.value' => ['sub' => $bob['sub']] + $ada]);
        $instance = $this->register();
        $this->em->persist((new ExternalIdentity())
            ->setUserId($bob['id'])
            ->setSource('opencloud')
            ->setInstanceId($instance)
            ->setExternalId($bob['sub'])
            ->setMode(ExternalIdentity::MODE_SSO));
        $this->em->flush();

        $this->use('ada.token.value');

        self::assertSame($bob['id'], $this->row($instance, $bob['sub'])->getUserId());
    }

    public function testALinkThePersonEndedIsNotCreatedAgain(): void
    {
        $person = $this->person();
        $this->installTokens(['ada.token.value' => $person]);
        $instance = $this->register();
        $this->use('ada.token.value');
        $row = $this->row($instance, $person['sub']);
        $row->setStatus(ExternalIdentity::STATUS_REVOKED);
        $this->em->flush();

        $this->client->request('GET', '/api/v1/auth/me', server: $this->bearer('ada.token.value'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $this->row($instance, $person['sub'])->getStatus());
    }

    public function testATokenOfAnUnregisteredClientReachesNoCloud(): void
    {
        $person = $this->person();
        $this->installTokens(['other.token.value' => $person + ['azp' => 'someone-else']]);
        $this->register();

        $this->client->request('POST', '/api/v1/platform-links/deliveries/1/claim', server: $this->bearer('other.token.value'));
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
            $this->use($token);
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
            $this->use($token);
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
     * An active cloud whose people sign in with the client self::AZP.
     */
    private function register(): string
    {
        $host = 'pt-'.bin2hex(random_bytes(4)).'.example.com';
        $instance = new PlatformInstance('opencloud', 'pi_'.bin2hex(random_bytes(12)), $host, ['https://'.$host.'/callback'], PlatformInstance::STATUS_ACTIVE);
        $instance->setOidcClientId(self::AZP);
        $this->em->persist($instance);
        $this->em->flush();

        return $instance->getInstanceId();
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
     * Any call with the person's token; the first one links them.
     */
    private function use(string $token): void
    {
        $this->client->request('GET', '/api/v1/auth/me', server: $this->bearer($token));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
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
