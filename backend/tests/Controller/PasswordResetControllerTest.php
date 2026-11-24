<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\VerificationToken;
use App\Service\Auth\PasswordResetRequest;
use App\Service\Auth\TurnstileVerifier;
use App\Service\Iam\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * "Forgot password" must not work as a mail cannon: it sits behind the human
 * check and per-IP and per-address windows, and it answers the same for an
 * address without an account.
 */
final class PasswordResetControllerTest extends WebTestCase
{
    private const DOMAIN = '@password-reset.synaplan.internal';
    private const PATH = '/api/v1/auth/forgot-password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $ip;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->removeTestUsers();
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        parent::tearDown();
    }

    public function testAKnownAddressGetsOneResetMail(): void
    {
        $email = $this->address();
        $user = $this->user($email);

        $this->post(['email' => strtoupper($email)]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['success']);
        self::assertEmailCount(1);
        $token = $this->em->getRepository(VerificationToken::class)->findOneBy(['user' => $user, 'type' => PasswordResetRequest::TOKEN_TYPE]);
        self::assertInstanceOf(VerificationToken::class, $token);
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNoMail(): void
    {
        $this->post(['email' => $this->address()]);

        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true, 'message' => 'If email exists, reset instructions sent'], $this->json());
        self::assertEmailCount(0);
    }

    public function testAnEmptyAddressIsRefused(): void
    {
        $this->post(['email' => '  ']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRequestsForOneAddressAreLimited(): void
    {
        $email = $this->address();
        for ($i = 0; $i < 3; ++$i) {
            // A new connection each time: only the address window may trip.
            $this->ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
            $this->post(['email' => $email]);
            self::assertResponseIsSuccessful();
        }

        $this->post(['email' => $email]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('PASSWORD_RESET_RATE_LIMITED', $this->json()['code']);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testRequestsFromOneConnectionAreLimited(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->post(['email' => $this->address()]);
            self::assertResponseIsSuccessful();
        }

        $this->post(['email' => $this->address()]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('PASSWORD_RESET_RATE_LIMITED', $this->json()['code']);
    }

    public function testAFailedHumanCheckSendsNothing(): void
    {
        $this->user($email = $this->address());
        $siteverify = new MockHttpClient(new MockResponse('{"success":false,"error-codes":["invalid-input-response"]}'));
        static::getContainer()->set(TurnstileVerifier::class, new TurnstileVerifier($siteverify, new NullLogger(), 'test-secret'));

        $this->post(['email' => $email, 'challengeToken' => 'forged']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('SIGNUP_CHALLENGE_FAILED', $this->json()['code']);
        self::assertEmailCount(0);
    }

    public function testAMissingHumanCheckTokenIsRefusedWhenAProviderIsConfigured(): void
    {
        $this->user($email = $this->address());
        static::getContainer()->set(TurnstileVerifier::class, new TurnstileVerifier(new MockHttpClient(), new NullLogger(), 'test-secret'));

        $this->post(['email' => $email]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('SIGNUP_CHALLENGE_FAILED', $this->json()['code']);
        self::assertEmailCount(0);
    }

    private function user(string $email): User
    {
        $user = (new User())->setMail($email)->setType('WEB')->setProviderId('local')->setUserLevel('PRO');
        $user->setCreated(date('Y-m-d H:i:s'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function address(): string
    {
        return 'reset'.bin2hex(random_bytes(4)).self::DOMAIN;
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body): void
    {
        $this->client->request('POST', self::PATH, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip], json_encode($body, JSON_THROW_ON_ERROR));
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
