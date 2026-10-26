<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SignupCode;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SignupCodeRepository;
use App\Service\Auth\TokenService;
use App\Service\Billing\PayFirst\PayFirstConfig;
use App\Service\Iam\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * PF5: an invitation code opens an account when the link is used, at the
 * code's tier, with no password. Nothing exists before the link is opened.
 * An address that already has an account is signed in and the code stays.
 */
final class CodeSignupControllerTest extends WebTestCase
{
    private const DOMAIN = '@code-signup.synaplan.internal';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $ip;
    private ?string $previousMailerDsn = null;

    /** @var list<int> */
    private array $codeIds = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->previousMailerDsn = $_ENV['MAILER_DSN'] ?? null;
        $_ENV['MAILER_DSN'] = 'null://default';
        $this->removeTestUsers();
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        $conn = $this->em->getConnection();
        foreach ($this->codeIds as $id) {
            $conn->executeStatement('DELETE FROM BSIGNUP_CODE_USES WHERE BCODEID = ?', [$id]);
            $conn->executeStatement('DELETE FROM BSIGNUP_CODES WHERE BID = ?', [$id]);
        }
        if (null === $this->previousMailerDsn) {
            unset($_ENV['MAILER_DSN']);
        } else {
            $_ENV['MAILER_DSN'] = $this->previousMailerDsn;
        }
        $conn->executeStatement('DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?', [PayFirstConfig::CONFIG_GROUP, PayFirstConfig::KEY]);
        parent::tearDown();
    }

    public function testAbsentUntilPayFirstIsOn(): void
    {
        $this->post(['email' => $this->address(), 'code' => 'NOPE2345WXYZ']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('CODE_SIGNUP_OFF', $this->json()['code']);
    }

    public function testTheLinkCreatesOneAccountAtTheCodesTier(): void
    {
        $this->switchOn();
        [$plain, $codeId] = $this->code('PRO', 1);
        $email = $this->address();

        $this->post(['email' => $email, 'code' => $plain]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['mail' => $email]), 'Nothing exists before the link is opened.');
        $secret = $this->secretFromMail();

        $this->postConsume($secret);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['created']);
        self::assertContains(TokenService::ACCESS_COOKIE, array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies()));
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('PRO', $user->getUserLevel());
        self::assertTrue($user->isEmailVerified());
        self::assertSame('', (string) $user->getPw(), 'The account has no password.');
        self::assertSame(1, $this->uses($codeId));

        $this->postConsume($secret);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('CODE_SIGNUP_INVALID', $this->json()['code']);
        self::assertSame(1, $this->countUsers($email));
    }

    public function testAnExistingAddressIsSignedInAndTheCodeStays(): void
    {
        $this->switchOn();
        [$plain, $codeId] = $this->code('TEAM', 1);
        $email = $this->address();
        $this->user($email);

        $this->post(['email' => strtoupper($email), 'code' => $plain]);
        self::assertResponseIsSuccessful();

        $this->postConsume($this->secretFromMail());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['created']);
        self::assertSame(0, $this->uses($codeId));
        self::assertSame(1, $this->countUsers($email));
    }

    public function testAnInvalidCodeIsRefusedAndSendsNothing(): void
    {
        $this->switchOn();

        $this->post(['email' => $this->address(), 'code' => 'NOTACODE2345']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('SIGNUP_CODE_INVALID', $this->json()['code']);
        self::assertEmailCount(0);
    }

    public function testAPlainTextPostCannotPlantASession(): void
    {
        $this->switchOn();
        [$plain] = $this->code('PRO', 1);
        $this->post(['email' => $this->address(), 'code' => $plain]);
        $secret = $this->secretFromMail();

        $this->client->request('POST', '/api/v1/auth/code-signup/consume', [], [], ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => $this->ip], json_encode(['token' => $secret], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(415);
        self::assertNotContains(TokenService::ACCESS_COOKIE, array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies()));
    }

    public function testALinkStillOpensTheAccountAfterPayFirstIsTurnedOff(): void
    {
        $this->switchOn();
        [$plain] = $this->code('BUSINESS', 1);
        $email = $this->address();
        $this->post(['email' => $email, 'code' => $plain]);
        $secret = $this->secretFromMail();
        $this->em->getConnection()->executeStatement('DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?', [PayFirstConfig::CONFIG_GROUP, PayFirstConfig::KEY]);

        $this->postConsume($secret);

        self::assertResponseIsSuccessful();
        self::assertSame('BUSINESS', $this->em->getRepository(User::class)->findOneBy(['mail' => $email])?->getUserLevel());
    }

    public function testOffWithoutAMailTransport(): void
    {
        $this->switchOn();
        $_ENV['MAILER_DSN'] = 'null://null';

        $this->post(['email' => $this->address(), 'code' => 'NOPE2345WXYZ']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRequestsForOneAddressAreLimited(): void
    {
        $this->switchOn();
        $email = $this->address();
        for ($i = 0; $i < 5; ++$i) {
            $this->post(['email' => $email, 'code' => 'NOPE2345WXYZ']);
            self::assertResponseStatusCodeSame(400);
        }

        $this->post(['email' => $email, 'code' => 'NOPE2345WXYZ']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('CODE_SIGNUP_RATE_LIMITED', $this->json()['code']);
    }

    private function switchOn(): void
    {
        static::getContainer()->get(ConfigRepository::class)->setValue(0, PayFirstConfig::CONFIG_GROUP, PayFirstConfig::KEY, 'true');
        $this->em->flush();
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function code(string $level, int $maxUses): array
    {
        $plain = SignupCodeRepository::generateCode();
        $code = (new SignupCode())
            ->setCodeHash(SignupCodeRepository::hash($plain))
            ->setPrefix(substr($plain, 0, SignupCode::PREFIX_LENGTH))
            ->setLabel('Code signup test')
            ->setLevel($level)
            ->setMaxUses($maxUses)
            ->setActive(true)
            ->setCreatedBy(1);
        static::getContainer()->get(SignupCodeRepository::class)->save($code);
        $id = (int) $code->getId();
        $this->codeIds[] = $id;

        return [$plain, $id];
    }

    private function user(string $email): void
    {
        $user = (new User())->setMail($email)->setType('WEB')->setProviderId('local')->setUserLevel('NEW');
        $user->setCreated(date('Y-m-d H:i:s'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function secretFromMail(): string
    {
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame(1, preg_match('#/start/code\?token=([0-9a-f]{64})#', (string) $mail->getHtmlBody(), $m));

        return $m[1];
    }

    private function uses(int $codeId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT BUSES FROM BSIGNUP_CODES WHERE BID = ?', [$codeId]);
    }

    private function countUsers(string $email): int
    {
        return count($this->em->getRepository(User::class)->findBy(['mail' => $email]));
    }

    private function address(): string
    {
        return 'ada'.bin2hex(random_bytes(4)).self::DOMAIN;
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body): void
    {
        $this->client->request('POST', '/api/v1/auth/code-signup', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function postConsume(string $secret): void
    {
        $this->client->request('POST', '/api/v1/auth/code-signup/consume', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip], json_encode(['token' => $secret], JSON_THROW_ON_ERROR));
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
