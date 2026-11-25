<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\VerificationToken;
use App\Service\Auth\EmailChange;
use App\Service\Iam\UserDeletionService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * A new sign-in email is stored only after the link mailed to that address
 * was opened. Until then the account keeps its address, so nobody can point
 * an account at a mailbox they do not read.
 */
final class EmailChangeControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const DOMAIN = '@email-change.synaplan.internal';
    private const PASSWORD = 'OldPass123!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $token;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->removeTestUsers();
        $this->user = $this->account('ada'.self::DOMAIN);
        $this->token = $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        parent::tearDown();
    }

    public function testANewAddressWaitsForItsLinkAndTheAccountKeepsTheOldOne(): void
    {
        $this->put(['email' => 'Ada.New'.self::DOMAIN, 'currentPassword' => self::PASSWORD, 'firstName' => 'Ada']);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('ada.new'.self::DOMAIN, $mail->getTo()[0]->getAddress());
        $payload = $this->json();
        self::assertSame('ada'.self::DOMAIN, $payload['email']);
        self::assertSame('ada.new'.self::DOMAIN, $payload['pendingEmail']);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($this->user->getId());
        self::assertSame('ada'.self::DOMAIN, $stored->getMail());
        self::assertSame('Ada', $stored->getUserDetails()['firstName']);
        $secret = $this->secretFromMail();
        self::assertInstanceOf(
            VerificationToken::class,
            $this->em->getRepository(VerificationToken::class)->findOneBy(['type' => EmailChange::TOKEN_TYPE, 'token' => hash('sha256', $secret)]),
            'Only the hash of the link is stored.',
        );

        $this->client->request('GET', '/api/v1/profile', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertSame('ada.new'.self::DOMAIN, $this->json()['profile']['pendingEmail']);
    }

    public function testTheLinkStoresTheAddressOnce(): void
    {
        $this->put(['email' => 'ada.new'.self::DOMAIN, 'currentPassword' => self::PASSWORD]);
        $secret = $this->secretFromMail();

        // The mail may be opened in a browser that is not signed in.
        $this->client->getCookieJar()->clear();
        $this->confirm($secret);
        self::assertResponseIsSuccessful();
        self::assertSame('ada.new'.self::DOMAIN, $this->json()['email']);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($this->user->getId());
        self::assertSame('ada.new'.self::DOMAIN, $stored->getMail());
        self::assertArrayNotHasKey(EmailChange::DETAILS_KEY, $stored->getUserDetails());

        $this->confirm($secret);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('EMAIL_CHANGE_INVALID', $this->json()['code']);

        $this->login('ada.new'.self::DOMAIN);
        self::assertResponseIsSuccessful();
    }

    public function testACancelledChangeLeavesADeadLink(): void
    {
        $this->put(['email' => 'ada.new'.self::DOMAIN, 'currentPassword' => self::PASSWORD]);
        $secret = $this->secretFromMail();

        $this->client->request('DELETE', '/api/v1/profile/email-change', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseIsSuccessful();

        $this->confirm($secret);
        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($this->user->getId());
        self::assertSame('ada'.self::DOMAIN, $stored->getMail());
        self::assertArrayNotHasKey(EmailChange::DETAILS_KEY, $stored->getUserDetails());
    }

    public function testALaterRequestReplacesTheEarlierLink(): void
    {
        $this->put(['email' => 'first'.self::DOMAIN, 'currentPassword' => self::PASSWORD]);
        $first = $this->secretFromMail();
        $this->put(['email' => 'second'.self::DOMAIN, 'currentPassword' => self::PASSWORD]);
        $second = $this->secretFromMail();

        $this->confirm($first);
        self::assertResponseStatusCodeSame(400);

        $this->confirm($second);
        self::assertResponseIsSuccessful();
        self::assertSame('second'.self::DOMAIN, $this->json()['email']);
    }

    public function testAnAddressRegisteredMeanwhileIsNotTaken(): void
    {
        $this->put(['email' => 'bea'.self::DOMAIN, 'currentPassword' => self::PASSWORD]);
        $secret = $this->secretFromMail();
        $this->account('bea'.self::DOMAIN);

        $this->confirm($secret);

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertSame('ada'.self::DOMAIN, $this->em->getRepository(User::class)->find($this->user->getId())->getMail());
    }

    public function testTheConfirmationOnlyAcceptsJson(): void
    {
        $this->client->request('POST', '/api/v1/auth/email-change/confirm', ['token' => 'x']);

        self::assertResponseStatusCodeSame(415);
    }

    public function testCancellingNeedsASignedInAccount(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('DELETE', '/api/v1/profile/email-change');

        self::assertResponseStatusCodeSame(401);
    }

    private function account(string $email): User
    {
        $user = (new User())->setMail($email)->setType('WEB')->setProviderId('local')->setUserLevel('PRO');
        $user->setPw(password_hash(self::PASSWORD, PASSWORD_BCRYPT));
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setEmailVerified(true);
        $user->setUserDetails(['language' => 'en']);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function put(array $payload): void
    {
        $this->client->request('PUT', '/api/v1/profile', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function confirm(string $secret): void
    {
        $this->client->request('POST', '/api/v1/auth/email-change/confirm', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['token' => $secret], JSON_THROW_ON_ERROR));
    }

    private function login(string $email): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => $email,
            'password' => self::PASSWORD,
        ], JSON_THROW_ON_ERROR));
    }

    private function secretFromMail(): string
    {
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame(1, preg_match('#/email-change\?token=([0-9a-f]{64})#', (string) $mail->getHtmlBody(), $m));

        return $m[1];
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
