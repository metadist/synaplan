<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\VerificationToken;
use App\Repository\ConfigRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Auth\EmailLinkSignIn;
use App\Service\Auth\TokenService;
use App\Service\Billing\PayFirst\PayFirstConfig;
use App\Service\Iam\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * PF3: a one-time sign-in link by mail (journey J-PF-3). The request never
 * tells whether an address has an account; the link works once, for 15
 * minutes, is stored only as a hash and verifies the address.
 */
final class EmailLinkControllerTest extends WebTestCase
{
    private const DOMAIN = '@email-link.synaplan.internal';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $ip;
    private ?string $previousMailerDsn = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
        // Pay first needs a mail transport; the null transport sends nothing.
        $this->previousMailerDsn = $_ENV['MAILER_DSN'] ?? null;
        $_ENV['MAILER_DSN'] = 'null://default';
        $this->removeTestUsers();
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        if (null === $this->previousMailerDsn) {
            unset($_ENV['MAILER_DSN']);
        } else {
            $_ENV['MAILER_DSN'] = $this->previousMailerDsn;
        }
        $this->em->getConnection()->executeStatement('DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?', [PayFirstConfig::CONFIG_GROUP, PayFirstConfig::KEY]);
        parent::tearDown();
    }

    public function testAbsentUntilPayFirstIsOn(): void
    {
        $this->post('/api/v1/auth/email-link', ['email' => 'ada'.self::DOMAIN]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLinkSignsInOnceAndVerifiesTheAddress(): void
    {
        $this->switchOn();
        $this->user('ada'.self::DOMAIN);

        $this->post('/api/v1/auth/email-link', ['email' => 'ADA'.self::DOMAIN]);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $secret = $this->secretFromMail();
        $stored = $this->em->getRepository(VerificationToken::class)->findOneBy(['type' => EmailLinkSignIn::TOKEN_TYPE, 'token' => hash('sha256', $secret)]);
        self::assertInstanceOf(VerificationToken::class, $stored, 'Only the hash of the link is stored.');

        $this->post('/api/v1/auth/email-link/consume', ['token' => $secret]);
        self::assertResponseIsSuccessful();
        self::assertContains(TokenService::ACCESS_COOKIE, array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies()));
        $this->em->clear();
        self::assertTrue($this->em->getRepository(User::class)->findOneBy(['mail' => 'ada'.self::DOMAIN])?->isEmailVerified());

        $this->post('/api/v1/auth/email-link/consume', ['token' => $secret]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('EMAIL_LINK_INVALID', $this->json()['code']);
    }

    public function testAPlainTextPostCannotPlantASession(): void
    {
        $this->switchOn();
        $user = $this->user('dora'.self::DOMAIN);
        $secret = static::getContainer()->get(VerificationTokenRepository::class)->createHashedToken($user, EmailLinkSignIn::TOKEN_TYPE, 900);

        // What a cross-site form can send without a preflight.
        $this->client->request('POST', '/api/v1/auth/email-link/consume', [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => $this->ip], json_encode(['token' => $secret], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(415);
        self::assertNotContains(TokenService::ACCESS_COOKIE, array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies()));
    }

    public function testALinkDoesNotSignInWhilePayFirstIsOff(): void
    {
        $user = $this->user('emil'.self::DOMAIN);
        $secret = static::getContainer()->get(VerificationTokenRepository::class)->createHashedToken($user, EmailLinkSignIn::TOKEN_TYPE, 900);

        $this->post('/api/v1/auth/email-link/consume', ['token' => $secret]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('EMAIL_LINK_OFF', $this->json()['code']);
    }

    public function testOffWithoutAMailTransport(): void
    {
        $this->switchOn();
        $_ENV['MAILER_DSN'] = 'null://null';

        $this->post('/api/v1/auth/email-link', ['email' => 'ada'.self::DOMAIN]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testALinkStopsWorkingWhenTheSignInEmailChanges(): void
    {
        $this->switchOn();
        $user = $this->user('finn'.self::DOMAIN);
        $this->post('/api/v1/auth/email-link', ['email' => 'finn'.self::DOMAIN]);
        $secret = $this->secretFromMail();

        $user = $this->em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->setMail('finn-new'.self::DOMAIN);
        $this->em->flush();

        $this->post('/api/v1/auth/email-link/consume', ['token' => $secret]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoMail(): void
    {
        $this->switchOn();

        $this->post('/api/v1/auth/email-link', ['email' => 'nobody'.self::DOMAIN]);

        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true], $this->json());
        self::assertEmailCount(0);
    }

    public function testAnExpiredLinkDoesNotSignIn(): void
    {
        $this->switchOn();
        $user = $this->user('bea'.self::DOMAIN);
        $secret = static::getContainer()->get(VerificationTokenRepository::class)->createHashedToken($user, EmailLinkSignIn::TOKEN_TYPE, -1);

        $this->post('/api/v1/auth/email-link/consume', ['token' => $secret]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRequestsForOneAddressAreLimited(): void
    {
        $this->switchOn();
        $address = 'cleo'.bin2hex(random_bytes(4)).self::DOMAIN;
        for ($i = 0; $i < 5; ++$i) {
            $this->post('/api/v1/auth/email-link', ['email' => $address]);
            self::assertResponseIsSuccessful();
        }

        $this->post('/api/v1/auth/email-link', ['email' => $address]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('EMAIL_LINK_RATE_LIMITED', $this->json()['code']);
    }

    private function switchOn(): void
    {
        static::getContainer()->get(ConfigRepository::class)->setValue(0, PayFirstConfig::CONFIG_GROUP, PayFirstConfig::KEY, 'true');
        $this->em->flush();
    }

    private function user(string $email): User
    {
        $user = (new User())->setMail($email)->setType('WEB')->setProviderId('local')->setUserLevel('PRO');
        $user->setCreated(date('Y-m-d H:i:s'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function secretFromMail(): string
    {
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame(1, preg_match('#/login/link\?token=([0-9a-f]{64})#', (string) $mail->getHtmlBody(), $m));

        return $m[1];
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body): void
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        return $payload;
    }

    private function removeTestUsers(): void
    {
        $this->em->clear();
        $users = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.mail LIKE :domain')->setParameter('domain', '%'.self::DOMAIN)
            ->getQuery()->getResult();
        foreach ($users as $user) {
            static::getContainer()->get(UserDeletionService::class)->deleteUser($user);
        }
    }
}
