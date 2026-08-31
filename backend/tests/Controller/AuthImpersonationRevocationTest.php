<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;

/**
 * Token revocation during an active impersonation. The regular refresh cookie
 * is cleared while impersonating and the admin's real refresh token lives in the
 * `admin_refresh_token` stash, so the naive logout / revoke-all paths acted on
 * the wrong (or no) token and left the admin session alive in the DB.
 */
final class AuthImpersonationRevocationTest extends WebTestCase
{
    public function testLogoutDuringImpersonationRevokesStashedAdminToken(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createUser($em, 'revoke-logout-admin@example.com', 'ADMIN');
        $adminId = (int) $admin->getId();

        $adminRefreshValue = $this->login($client, 'revoke-logout-admin@example.com');

        $tokenService = static::getContainer()->get(TokenService::class);
        self::assertNotNull(
            $tokenService->validateRefreshToken($adminRefreshValue),
            'admin refresh token should be valid right after login',
        );

        // Recreate the impersonation cookie state: admin refresh moved to the
        // stash, the regular refresh cookie cleared (as impersonation start does).
        $jar = $client->getCookieJar();
        $refreshCookie = $jar->get('refresh_token');
        self::assertNotNull($refreshCookie, 'login must set a refresh_token cookie');
        $jar->set(new BrowserKitCookie(
            'admin_refresh_token',
            $adminRefreshValue,
            null,
            $refreshCookie->getPath(),
            (string) $refreshCookie->getDomain(),
            $refreshCookie->isSecure(),
            true,
        ));
        $jar->expire('refresh_token');

        $client->request('POST', '/api/v1/auth/logout');
        $this->assertResponseIsSuccessful('logout should succeed');

        self::assertNull(
            static::getContainer()->get(TokenService::class)->validateRefreshToken($adminRefreshValue),
            'logout during impersonation MUST revoke the stashed admin refresh token, not just clear the cookie',
        );

        $this->deleteUser($em, $adminId);
    }

    public function testRevokeAllDuringImpersonationRevokesAdminNotTarget(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createUser($em, 'revoke-all-admin@example.com', 'ADMIN');
        $target = $this->createUser($em, 'revoke-all-target@example.com', 'USER');
        $adminId = (int) $admin->getId();
        $targetId = (int) $target->getId();

        // The impersonated victim owns an independent session that must survive.
        $targetRefreshValue = static::getContainer()->get(TokenService::class)->generateRefreshToken($target);
        self::assertNotNull(
            static::getContainer()->get(TokenService::class)->validateRefreshToken($targetRefreshValue),
            'target refresh token should be valid before revoke-all',
        );

        $adminRefreshValue = $this->login($client, 'revoke-all-admin@example.com');

        // Start a real impersonation: stashes the admin refresh, mints an
        // impersonation access token for the target, clears the regular refresh.
        $client->request('POST', '/api/v1/admin/impersonate/'.$targetId);
        $this->assertResponseIsSuccessful('impersonation start should succeed');
        self::assertNotNull(
            $client->getCookieJar()->get('admin_refresh_token'),
            'impersonation start must set the admin_refresh_token stash',
        );

        // #[CurrentUser] now resolves to the target — the bug revoked the
        // victim's sessions and left the admin's stashed token alive.
        $client->request('POST', '/api/v1/auth/revoke-all');
        $this->assertResponseIsSuccessful('revoke-all should succeed');

        $tokenService = static::getContainer()->get(TokenService::class);
        self::assertNull(
            $tokenService->validateRefreshToken($adminRefreshValue),
            'revoke-all while impersonating MUST revoke the admin (stashed) refresh token',
        );
        self::assertNotNull(
            $tokenService->validateRefreshToken($targetRefreshValue),
            'revoke-all while impersonating MUST NOT revoke the impersonated victim sessions',
        );

        $this->deleteUser($em, $adminId);
        $this->deleteUser($em, $targetId);
    }

    private function createUser(EntityManagerInterface $em, string $email, string $level): User
    {
        $user = new User();
        $user->setMail($email);
        $user->setPw(password_hash('AdminPass123!', PASSWORD_BCRYPT));
        $user->setUserLevel($level);
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails(['firstName' => 'Test', 'lastName' => 'User']);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Logs the user in and returns the raw refresh-token cookie value.
     */
    private function login(KernelBrowser $client, string $email): string
    {
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => 'AdminPass123!']),
        );
        $this->assertResponseIsSuccessful('login should succeed for '.$email);

        $refreshCookie = $client->getCookieJar()->get('refresh_token');
        self::assertNotNull($refreshCookie, 'login must set a refresh_token cookie');

        return $refreshCookie->getValue();
    }

    private function deleteUser(EntityManagerInterface $em, int $userId): void
    {
        $em->clear();
        $tokens = $em->getRepository(Token::class)->findBy(['user' => $userId]);
        foreach ($tokens as $token) {
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
