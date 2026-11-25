<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Entity\VerificationToken;
use App\Repository\VerificationTokenRepository;
use App\Service\Auth\EmailLinkSignIn;
use App\Service\Auth\OAuthSignupGate;
use App\Service\Auth\PasswordResetRequest;
use App\Service\Iam\AccessRevocation;
use App\Service\Iam\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Anyone can open an account for an address they do not own. When the owner
 * of the mailbox later arrives through a provider or a mailed sign-in link,
 * the password chosen before must not sign anyone in.
 */
final class UnverifiedAccountClaimTest extends WebTestCase
{
    private const DOMAIN = '@account-claim.synaplan.internal';
    private const PASSWORD = 'Chosen-By-Someone-Else-1!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->removeTestUsers();
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        parent::tearDown();
    }

    public function testAProviderSignInRemovesThePasswordSetBeforeTheAddressWasProven(): void
    {
        $user = $this->account('ada'.self::DOMAIN, false);
        $userId = (int) $user->getId();
        $tokens = static::getContainer()->get(VerificationTokenRepository::class);
        $tokens->createToken($user, AccessRevocation::EMAIL_VERIFICATION_TOKEN);
        $tokens->createToken($user, PasswordResetRequest::TOKEN_TYPE);
        $key = (new ApiKey())->setOwner($user)->setKey('sk_test_'.bin2hex(random_bytes(12)))->setName('left behind');
        $this->em->persist($key);
        $this->em->flush();

        static::getContainer()->get(OAuthSignupGate::class)->finishSignIn($user, false, [], true);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($stored->isEmailVerified());
        self::assertNull($stored->getPw());
        self::assertSame([], $this->em->getRepository(VerificationToken::class)->findBy(['userId' => $userId]));
        self::assertSame([], $this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $userId]));

        $this->login('ada'.self::DOMAIN);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAMailedSignInLinkRemovesIt(): void
    {
        $user = $this->account('bea'.self::DOMAIN, false);
        $secret = static::getContainer()->get(VerificationTokenRepository::class)->createHashedToken($user, EmailLinkSignIn::TOKEN_TYPE, 900);

        $signedIn = static::getContainer()->get(EmailLinkSignIn::class)->consume($secret);

        self::assertInstanceOf(User::class, $signedIn);
        self::assertTrue($signedIn->isEmailVerified());
        self::assertNull($signedIn->getPw());
        $this->login('bea'.self::DOMAIN);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAVerifiedAccountKeepsItsPassword(): void
    {
        $user = $this->account('cleo'.self::DOMAIN, true);

        static::getContainer()->get(OAuthSignupGate::class)->finishSignIn($user, false, [], true);
        $secret = static::getContainer()->get(VerificationTokenRepository::class)->createHashedToken($user, EmailLinkSignIn::TOKEN_TYPE, 900);
        static::getContainer()->get(EmailLinkSignIn::class)->consume($secret);

        $this->login('cleo'.self::DOMAIN);
        self::assertResponseIsSuccessful();
    }

    public function testAnUnverifiedAccountWithoutAPasswordIsOnlyMarkedVerified(): void
    {
        $user = (new User())->setMail('dan'.self::DOMAIN)->setType('WEB')->setProviderId('apple');
        $user->setCreated(date('Y-m-d H:i:s'));
        $this->em->persist($user);
        $this->em->flush();
        $key = (new ApiKey())->setOwner($user)->setKey('sk_test_'.bin2hex(random_bytes(12)))->setName('kept');
        $this->em->persist($key);
        $this->em->flush();

        static::getContainer()->get(OAuthSignupGate::class)->finishSignIn($user, false, [], true);

        self::assertTrue($user->isEmailVerified());
        self::assertCount(1, $this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $user->getId()]));
    }

    private function account(string $email, bool $verified): User
    {
        $user = (new User())->setMail($email)->setType('WEB')->setProviderId('local');
        $user->setPw(password_hash(self::PASSWORD, PASSWORD_BCRYPT));
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setEmailVerified($verified);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function login(string $email): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => $email,
            'password' => self::PASSWORD,
        ], JSON_THROW_ON_ERROR));
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
