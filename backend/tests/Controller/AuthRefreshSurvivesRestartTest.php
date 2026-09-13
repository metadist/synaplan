<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a container restart the 5-minute access cookie is typically stale:
 * either its TTL elapsed during downtime, or APP_SECRET was rotated and the
 * HMAC no longer verifies. The DB-backed refresh token in BTOKENS must still
 * mint a new access cookie. If CookieTokenAuthenticator claims /auth/refresh
 * and 401s on that stale cookie, every user is locked out.
 */
final class AuthRefreshSurvivesRestartTest extends WebTestCase
{
    /** @var list<int> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        $this->cleanupCreatedUsers();
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testRefreshSucceedsWhenAccessCookieNoLongerVerifies(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine')->getManager();

        $user = $this->persistUser($em, 'restart-session@example.com');
        $userId = (int) $user->getId();

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'restart-session@example.com',
                'password' => 'RestartPass123!',
            ]),
        );
        $this->assertResponseIsSuccessful('login must succeed');

        $jar = $client->getCookieJar();
        $refreshCookie = $jar->get(TokenService::REFRESH_COOKIE);
        $this->assertNotNull($refreshCookie, 'login must set a refresh_token cookie');
        $this->assertNotNull($jar->get(TokenService::ACCESS_COOKIE), 'login must set an access_token cookie');

        $refreshValue = $refreshCookie->getValue();

        // Simulate a restart: the access cookie is still sent by the browser
        // but no longer verifies (expired TTL or a new APP_SECRET).
        $jar->set(new BrowserKitCookie(
            TokenService::ACCESS_COOKIE,
            'unsigned-after-restart',
            (string) (time() + 300),
            $refreshCookie->getPath(),
            (string) $refreshCookie->getDomain(),
            $refreshCookie->isSecure(),
            true,
        ));

        $this->assertSame('unsigned-after-restart', $jar->get(TokenService::ACCESS_COOKIE)?->getValue());
        $this->assertSame($refreshValue, $jar->get(TokenService::REFRESH_COOKIE)?->getValue());

        $client->request('POST', '/api/v1/auth/refresh');

        $this->assertResponseIsSuccessful(
            'refresh must consult BTOKENS, not 401 on the stale access cookie',
        );
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($payload['success'] ?? false);
        $this->assertSame($userId, $payload['user']['id'] ?? null);

        $newAccess = $jar->get(TokenService::ACCESS_COOKIE);
        $this->assertNotNull($newAccess, 'refresh must mint a new access cookie');
        $this->assertNotSame('unsigned-after-restart', $newAccess->getValue());

        $rewrittenRefresh = $jar->get(TokenService::REFRESH_COOKIE);
        $this->assertNotNull($rewrittenRefresh);
        $this->assertSame($refreshValue, $rewrittenRefresh->getValue());
        $ttl = $rewrittenRefresh->getExpiresTime() - time();
        $this->assertGreaterThan(29 * 86400, $ttl);
        $this->assertLessThanOrEqual(TokenService::REFRESH_TOKEN_TTL + 5, $ttl);
    }

    public function testLoginSucceedsWhenStaleAccessCookieIsStillSent(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine')->getManager();

        $user = $this->persistUser($em, 'restart-relogin@example.com', withFirstName: false);
        $userId = (int) $user->getId();

        $client->getCookieJar()->set(new BrowserKitCookie(
            TokenService::ACCESS_COOKIE,
            'unsigned-after-restart',
            (string) (time() + 300),
            '/',
            '',
            false,
            true,
        ));

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'restart-relogin@example.com',
                'password' => 'RestartPass123!',
            ]),
        );

        $this->assertResponseIsSuccessful(
            'login must not 401 on a leftover access cookie from the previous process',
        );
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($payload['success'] ?? false);
        $this->assertSame($userId, $payload['user']['id'] ?? null);
    }

    public function testInvalidRefreshDoesNotClearExistingAuthCookies(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine')->getManager();

        $this->persistUser($em, 'refresh-must-not-wipe@example.com', withFirstName: false);

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'refresh-must-not-wipe@example.com',
                'password' => 'RestartPass123!',
            ]),
        );
        $this->assertResponseIsSuccessful();

        $jar = $client->getCookieJar();
        $accessBefore = $jar->get(TokenService::ACCESS_COOKIE)?->getValue();
        $this->assertNotNull($accessBefore);

        $jar->set(new BrowserKitCookie(
            TokenService::REFRESH_COOKIE,
            'dead-refresh-from-previous-tab',
            (string) (time() + 3600),
            '/',
            '',
            false,
            true,
        ));

        $client->request('POST', '/api/v1/auth/refresh');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_REFRESH_TOKEN', $payload['code'] ?? null);

        $accessAfter = $jar->get(TokenService::ACCESS_COOKIE);
        $this->assertNotNull($accessAfter, 'a failed refresh must not expire the access cookie');
        $this->assertSame($accessBefore, $accessAfter->getValue());
    }

    public function testRefreshRejectsSuspendedAccountAndDoesNotExtendToken(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = $client->getContainer()->get('doctrine')->getManager();

        $user = $this->persistUser($em, 'restart-suspended@example.com', withFirstName: false);
        $userId = (int) $user->getId();

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'restart-suspended@example.com',
                'password' => 'RestartPass123!',
            ]),
        );
        $this->assertResponseIsSuccessful('login must succeed before the account is suspended');

        $em->clear();
        $tokens = $em->getRepository(Token::class)->findBy(['user' => $userId]);
        $this->assertNotEmpty($tokens);
        $expiryBefore = $tokens[0]->getExpires();

        $suspended = $em->getRepository(User::class)->find($userId);
        $this->assertNotNull($suspended);
        $suspended->setAccountStatus(User::ACCOUNT_STATUS_SUSPENDED);
        $em->flush();

        $client->request('POST', '/api/v1/auth/refresh');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('ACCOUNT_SUSPENDED', $payload['code'] ?? null);

        $em->clear();
        $tokensAfter = $em->getRepository(Token::class)->findBy(['user' => $userId]);
        $this->assertNotEmpty($tokensAfter);
        $this->assertSame($expiryBefore, $tokensAfter[0]->getExpires());
    }

    private function persistUser(EntityManagerInterface $em, string $email, bool $withFirstName = true): User
    {
        $user = new User();
        $user->setMail($email);
        $user->setPw(password_hash('RestartPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        if ($withFirstName) {
            $user->setUserDetails(['firstName' => 'Restart']);
        }
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = (int) $user->getId();

        return $user;
    }

    private function cleanupCreatedUsers(): void
    {
        if ([] === $this->createdUserIds) {
            return;
        }

        try {
            /** @var EntityManagerInterface $em */
            $em = static::getContainer()->get('doctrine')->getManager();
        } catch (\Throwable) {
            $this->createdUserIds = [];

            return;
        }

        $em->clear();
        foreach ($this->createdUserIds as $userId) {
            foreach ($em->getRepository(Token::class)->findBy(['user' => $userId]) as $token) {
                $em->remove($token);
            }
            $entity = $em->getRepository(User::class)->find($userId);
            if ($entity) {
                $em->remove($entity);
            }
        }
        $em->flush();
        $this->createdUserIds = [];
    }
}
