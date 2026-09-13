<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use App\Service\TokenService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;

/**
 * After a container restart the 5-minute access cookie is typically stale:
 * either its TTL elapsed during downtime, or APP_SECRET was rotated and the
 * HMAC no longer verifies. The DB-backed refresh token in BTOKENS must still
 * mint a new access cookie. If CookieTokenAuthenticator claims /auth/refresh
 * and 401s on that stale cookie, every user is locked out.
 */
final class AuthRefreshSurvivesRestartTest extends WebTestCase
{
    public function testRefreshSucceedsWhenAccessCookieNoLongerVerifies(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setMail('restart-session@example.com');
        $user->setPw(password_hash('RestartPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails(['firstName' => 'Restart']);
        $em->persist($user);
        $em->flush();
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

        $em->clear();
        foreach ($em->getRepository(Token::class)->findBy(['user' => $userId]) as $token) {
            $em->remove($token);
        }
        $em->flush();
        $entity = $em->getRepository(User::class)->find($userId);
        if ($entity) {
            $em->remove($entity);
            $em->flush();
        }
    }

    public function testLoginSucceedsWhenStaleAccessCookieIsStillSent(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setMail('restart-relogin@example.com');
        $user->setPw(password_hash('RestartPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();
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

        $em->clear();
        foreach ($em->getRepository(Token::class)->findBy(['user' => $userId]) as $token) {
            $em->remove($token);
        }
        $em->flush();
        $entity = $em->getRepository(User::class)->find($userId);
        if ($entity) {
            $em->remove($entity);
            $em->flush();
        }
    }
}
