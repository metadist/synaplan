<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\PlatformInstanceRepository;
use App\Seed\OidcCloudSeeder;
use App\Service\Auth\OidcTokenService;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\OidcCloud;
use App\Service\PlatformLink\PlatformInstanceSupersession;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Service\PlatformLink\RedirectUriPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The configured cloud's app reports what it takes, on a person's token
 * whose azp is that cloud's client.
 */
final class OidcCloudReportTest extends WebTestCase
{
    private const ISSUER = 'https://idp.example/realms/synaplan';

    private const CLIENT_ID = 'synaplan-opencloud';

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
        $existing = $this->instances()->findByInstanceId(PlatformInstance::OIDC_CLOUD_ID);
        if ($existing instanceof PlatformInstance) {
            $this->em->remove($existing);
            $this->em->flush();
        }
        static::getContainer()->set(OidcCloud::class, new OidcCloud('opencloud', 'https://cloud.example.com', self::CLIENT_ID, 'http://synaplan-opencloud:9106'));
    }

    public function testTheAppReportIsWhatTheCoreOffers(): void
    {
        $this->seed();
        $person = $this->person();
        $this->installTokens(['cloud.person.token' => $person]);

        $this->put('cloud.person.token', [
            'app_capabilities' => ['deliveries', 'pickups', 'from-the-future'],
            'software_version' => '1.2.0',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['deliveries', 'pickups'], $this->json()['app_capabilities']);
        self::assertSame('1.2.0', $this->json()['software_version']);
        self::assertSame(['deliveries', 'pickups'], $this->row()->getAppCapabilities());
        self::assertTrue($this->row()->canReceiveCopies());
        self::assertTrue($this->row()->reports('pickups'));

        $this->seed();
        self::assertSame(['deliveries', 'pickups'], $this->row()->getAppCapabilities(), 'a restart keeps the report');
        self::assertSame('1.2.0', $this->row()->getAppVersion());
    }

    public function testAnEmptyReportOffersNothingThatWaits(): void
    {
        $this->seed();
        $person = $this->person();
        $this->installTokens(['cloud.person.token' => $person]);
        $this->put('cloud.person.token', ['app_capabilities' => ['deliveries'], 'software_version' => '1.2.0']);
        self::assertResponseIsSuccessful();

        $this->put('cloud.person.token', ['app_capabilities' => [], 'software_version' => '0.9.0']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->row()->getAppCapabilities());
        self::assertFalse($this->row()->canReceiveCopies());
        self::assertSame('0.9.0', $this->row()->getAppVersion());
    }

    public function testAnotherClientCannotReport(): void
    {
        $this->seed();
        $person = $this->person();
        $this->installTokens(['other.person.token' => $person + ['azp' => 'synaplan-app']]);

        $this->put('other.person.token', ['app_capabilities' => ['deliveries'], 'software_version' => '9.9.9']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([], $this->row()->getAppCapabilities());
        self::assertNull($this->row()->getAppVersion());
    }

    public function testAReportThatIsNotAListIsRefused(): void
    {
        $this->seed();
        $person = $this->person();
        $this->installTokens(['cloud.person.token' => $person]);

        $this->put('cloud.person.token', ['app_capabilities' => 'deliveries']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->row()->getAppCapabilities());
    }

    private function seed(): void
    {
        $cloud = new OidcCloud('opencloud', 'https://cloud.example.com', self::CLIENT_ID, 'http://synaplan-opencloud:9106');
        $seeder = new OidcCloudSeeder(
            $cloud,
            $this->instances(),
            static::getContainer()->get(RedirectUriPolicy::class),
            static::getContainer()->get(AuditLogWriter::class),
            static::getContainer()->get(PlatformInstanceSupersession::class),
        );
        $seeder->seed();
        $this->em->clear();
    }

    /**
     * @return array{id: int, sub: string, email: string}
     */
    private function person(): array
    {
        $sub = 'oc-'.bin2hex(random_bytes(6));
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
                    'azp' => $person['azp'] ?? self::CLIENT_ID,
                ];
            }
        );
        static::getContainer()->set(OidcTokenService::class, $tokens);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $token, array $body): void
    {
        $this->client->request(
            'PUT',
            '/api/v1/platform-links/oidc-cloud',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: (string) json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function row(): PlatformInstance
    {
        $this->em->clear();
        $row = $this->instances()->findByInstanceId(PlatformInstance::OIDC_CLOUD_ID);
        self::assertInstanceOf(PlatformInstance::class, $row);

        return $row;
    }

    private function instances(): PlatformInstanceRepository
    {
        return static::getContainer()->get(PlatformInstanceRepository::class);
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
