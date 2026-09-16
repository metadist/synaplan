<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\GuestChatConfig;
use App\Service\RegistrationConfig;
use App\Service\Setup\SetupConstants;
use App\Service\Setup\SetupStateService;
use App\Service\TokenService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;

/**
 * The setup endpoints, from both sides: on an installation that has users they
 * must be dead ends, and on a virgin one they must produce exactly one
 * administrator.
 *
 * Both worlds are simulated by putting a real SetupStateService over stubbed
 * repositories into the container. The service is final, so it cannot be mocked
 * — and building the real thing is the better test anyway, because the decision
 * logic under test is the service's own.
 */
final class SetupControllerTest extends WebTestCase
{
    use EnablesSetupWizard;

    private const NEW_ADMIN_EMAIL = 'first-run-admin@example.com';

    /** @var list<int> */
    private array $createdUserIds = [];

    private bool $wroteConfigRows = false;

    protected function tearDown(): void
    {
        $this->restoreSetupWizardEnv();
        $this->deleteCreatedRows();

        parent::tearDown();
    }

    public function testStateReportsNoWizardOnAnInstallationThatHasUsers(): void
    {
        $client = $this->client();
        $this->createAdmin($client, 'setup-state-admin@example.com');

        $client->request('GET', '/api/v1/setup/state');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertFalse($payload['wizardRequired']);
        self::assertTrue($payload['adminExists']);
        self::assertArrayHasKey('access', $payload);
        self::assertArrayHasKey('registrationLocked', $payload['access']);
    }

    public function testStateReportsTheWizardOnAVirginInstance(): void
    {
        $client = $this->clientOnAVirginInstance();

        $client->request('GET', '/api/v1/setup/state');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertTrue($payload['wizardRequired']);
        self::assertFalse($payload['adminExists']);
    }

    /**
     * The single most important refusal in the feature: an instance with accounts
     * must never hand out an administrator to an anonymous caller.
     */
    public function testCreateFirstAdminIsRefusedOnAnInstallationThatHasUsers(): void
    {
        $client = $this->clientWithUsers();

        $this->postAdmin($client, self::NEW_ADMIN_EMAIL, 'SecurePass123');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('SETUP_ALREADY_COMPLETED', $this->payload($client)['code'] ?? null);
        self::assertNull($this->findUser($client, self::NEW_ADMIN_EMAIL));
    }

    public function testCreateFirstAdminCreatesOneVerifiedAdminAndSignsItIn(): void
    {
        $client = $this->clientOnAVirginInstance();

        $this->postAdmin($client, self::NEW_ADMIN_EMAIL, 'SecurePass123');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $payload = $this->payload($client);
        self::assertTrue($payload['success']);
        self::assertSame('ADMIN', $payload['user']['level']);
        self::assertTrue($payload['user']['isAdmin']);

        // Signed in, so step 2 of the wizard can write a provider key through the
        // admin API without a detour via the login page.
        self::assertNotNull(
            $client->getCookieJar()->get(TokenService::ACCESS_COOKIE),
            'the response must set the auth cookies'
        );

        $user = $this->findUser($client, self::NEW_ADMIN_EMAIL);
        self::assertNotNull($user);
        $this->createdUserIds[] = (int) $user->getId();
        self::assertSame('ADMIN', $user->getUserLevel());
        self::assertTrue($user->isEmailVerified(), 'there is no mailbox to confirm through yet');
    }

    /**
     * The completion screen refreshes the user via /auth/me. That call has to
     * succeed with the cookies POST /admin just set, or the SPA thinks nobody
     * is signed in and sends the administrator to /login.
     */
    public function testCreateFirstAdminSessionIsReadableViaAuthMe(): void
    {
        $client = $this->clientOnAVirginInstance();

        $this->postAdmin($client, self::NEW_ADMIN_EMAIL, 'SecurePass123');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->rememberCreatedUser($client, self::NEW_ADMIN_EMAIL);

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertTrue($payload['success']);
        self::assertSame(self::NEW_ADMIN_EMAIL, $payload['user']['email']);
        self::assertTrue($payload['user']['isAdmin']);
    }

    /**
     * Cookies only, unless the caller identifies itself as the native app — the
     * same rule AuthController::login() follows, because a web client that
     * received Bearer tokens in a JSON body would be storing them in JavaScript.
     */
    public function testCreateFirstAdminWithholdsBearerTokensFromWebClients(): void
    {
        $client = $this->clientOnAVirginInstance();

        $this->postAdmin($client, self::NEW_ADMIN_EMAIL, 'SecurePass123');

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertArrayNotHasKey('tokens', $this->payload($client));
        $this->rememberCreatedUser($client, self::NEW_ADMIN_EMAIL);
    }

    /**
     * A weak first password would be the worst place to be lenient — this account
     * is the one that can reach everything.
     */
    public function testCreateFirstAdminRejectsAPasswordThatFailsTheRules(): void
    {
        $client = $this->clientOnAVirginInstance();

        $this->postAdmin($client, self::NEW_ADMIN_EMAIL, 'short');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->findUser($client, self::NEW_ADMIN_EMAIL));
    }

    public function testCreateFirstAdminRejectsAPasswordWithoutTheRequiredCharacterClasses(): void
    {
        $client = $this->clientOnAVirginInstance();

        $this->postAdmin($client, self::NEW_ADMIN_EMAIL, 'alllowercase');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->findUser($client, self::NEW_ADMIN_EMAIL));
    }

    /**
     * The lock is what keeps a Compose scale or a Kubernetes rollout from
     * producing two "first" administrators. Whoever loses that race has nothing
     * to wait for, so the refusal has to come back straight away instead of
     * holding the request open until the winner is finished — and it has to be
     * the retriable code, not "this instance already has accounts".
     */
    public function testCreateFirstAdminRefusesWhileAnotherCallerHoldsTheLock(): void
    {
        $client = $this->clientOnAVirginInstance();

        $competitor = static::getContainer()->get(LockFactory::class)->createLock('first-run-setup-admin');
        self::assertTrue($competitor->acquire(), 'the competing caller must own the lock for this test to mean anything');

        try {
            $this->postAdmin($client, self::NEW_ADMIN_EMAIL, 'SecurePass123');
        } finally {
            $competitor->release();
        }

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('SETUP_IN_PROGRESS', $this->payload($client)['code'] ?? null);
        self::assertNull($this->findUser($client, self::NEW_ADMIN_EMAIL));
    }

    public function testCreateFirstAdminRejectsAnInvalidEmail(): void
    {
        $client = $this->clientOnAVirginInstance();

        $this->postAdmin($client, 'not-an-email', 'SecurePass123');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testCompleteRefusesAnonymousCallers(): void
    {
        $client = $this->client();

        $this->postComplete($client, registration: true, guestChat: true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Runs against the real repositories, because what matters here is the rows
     * that end up in BCONFIG.
     */
    public function testCompleteStoresThePolicyAndSetsTheFlag(): void
    {
        $client = $this->client();
        $this->loginAsAdmin($client, 'setup-complete-admin@example.com');

        $this->postComplete($client, registration: false, guestChat: false);
        $this->wroteConfigRows = true;

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertTrue($payload['success']);
        self::assertFalse($payload['registrationEnabled']);
        self::assertFalse($payload['guestChatEnabled']);

        $configRepository = static::getContainer()->get(ConfigRepository::class);
        self::assertSame(
            '1',
            $configRepository->getValue(
                SetupConstants::OWNER_ID,
                SetupConstants::CONFIG_GROUP,
                SetupConstants::KEY_COMPLETED,
            ),
        );
        self::assertSame(
            'false',
            $configRepository->getValue(
                SetupConstants::OWNER_ID,
                RegistrationConfig::CONFIG_GROUP,
                RegistrationConfig::KEY_ENABLED,
            ),
        );
    }

    /**
     * A virgin installation: no SETUP.COMPLETED row and no users, with the wizard
     * switched on for this test (`.env.test` disables it, because the
     * fixture-less PHPUnit database would otherwise look like a virgin install to
     * every single test).
     */
    private function clientOnAVirginInstance(): KernelBrowser
    {
        return $this->clientWithSetupState(userCount: 0, completedFlag: null);
    }

    /**
     * An installation whose user table is what closes the setup window, with no
     * SETUP.COMPLETED flag to fall back on.
     */
    private function clientWithUsers(): KernelBrowser
    {
        return $this->clientWithSetupState(userCount: 1, completedFlag: null);
    }

    /**
     * A plain client, on which `.env.test`'s `SETUP_WIZARD_ENABLED=false` already
     * means "not a virgin installation". Used wherever the assertion is about rows
     * in the database, which a stubbed repository would swallow.
     */
    private function client(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    private function clientWithSetupState(int $userCount, ?string $completedFlag): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->enableSetupWizard();

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn($completedFlag);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('countAll')->willReturn($userCount);
        $userRepository->method('hasAdmin')->willReturn($userCount > 0);

        static::getContainer()->set(
            SetupStateService::class,
            new SetupStateService($configRepository, $userRepository),
        );
        // SetupController reads the repository directly too — for `adminExists`
        // and for the re-check inside the creation lock — so a stub only in the
        // state service would leave the controller looking at the real, seeded
        // table and refusing with 409.
        static::getContainer()->set(UserRepository::class, $userRepository);

        return $client;
    }

    private function postAdmin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request(
            'POST',
            '/api/v1/setup/admin',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );
    }

    private function postComplete(KernelBrowser $client, bool $registration, bool $guestChat): void
    {
        $client->request(
            'POST',
            '/api/v1/setup/complete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'registrationEnabled' => $registration,
                'guestChatEnabled' => $guestChat,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function createAdmin(KernelBrowser $client, string $email, string $password = 'SetupPass123!'): void
    {
        $em = $client->getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setMail($email);
        $user->setPw(password_hash($password, PASSWORD_BCRYPT));
        $user->setUserLevel('ADMIN');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails([]);
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = (int) $user->getId();
    }

    private function loginAsAdmin(KernelBrowser $client, string $email): void
    {
        $password = 'SetupPass123!';
        $this->createAdmin($client, $email, $password);

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful('Login should succeed for '.$email);
    }

    private function rememberCreatedUser(KernelBrowser $client, string $email): void
    {
        $user = $this->findUser($client, $email);
        if (null !== $user) {
            $this->createdUserIds[] = (int) $user->getId();
        }
    }

    /**
     * DQL rather than `getRepository(User::class)`: the virgin-instance setup puts
     * a UserRepository stub into the container, and Doctrine's repository factory
     * would hand that same stub back here.
     */
    private function findUser(KernelBrowser $client, string $email): ?User
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->createQuery('SELECT u FROM '.User::class.' u WHERE u.mail = :mail')
            ->setParameter('mail', $email)
            ->getOneOrNullResult();
    }

    /**
     * The setup flag and the access rows are install-wide, so leaving them behind
     * would silently change the starting point of every later test.
     */
    private function deleteCreatedRows(): void
    {
        if ([] === $this->createdUserIds && !$this->wroteConfigRows) {
            return;
        }

        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        foreach ($this->createdUserIds as $id) {
            foreach ($em->getRepository(Token::class)->findBy(['user' => $id]) as $token) {
                $em->remove($token);
            }
        }
        $em->flush();

        foreach ($this->createdUserIds as $id) {
            $entity = $em->getRepository(User::class)->find($id);
            if (null !== $entity) {
                $em->remove($entity);
            }
        }
        $em->flush();
        $this->createdUserIds = [];

        if ($this->wroteConfigRows) {
            $connection = static::getContainer()->get(Connection::class);
            $connection->executeStatement(
                'DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP IN (:groups)',
                ['groups' => [
                    SetupConstants::CONFIG_GROUP,
                    RegistrationConfig::CONFIG_GROUP,
                    GuestChatConfig::CONFIG_GROUP,
                ]],
                ['groups' => ArrayParameterType::STRING],
            );
            $this->wroteConfigRows = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
