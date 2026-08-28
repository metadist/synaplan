<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;

/**
 * Deterministic reproducer of the impersonation cookie-swap race: a refresh
 * leaves a valid admin stash next to a plain admin access token. The refresh
 * must recover the admin session (200) instead of returning 401 and wiping
 * cookies, and must keep the stash so `/impersonate/exit` can tear it down.
 */
class AuthRefreshImpersonationRecoveryTest extends WebTestCase
{
    public function testCorruptedSwapRefreshRecoversAdminSessionInsteadOfLoggingOut(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $admin = new User();
        $admin->setMail('recover-admin@example.com');
        $admin->setPw(password_hash('AdminPass123!', PASSWORD_BCRYPT));
        $admin->setUserLevel('ADMIN');
        $admin->setProviderId('local');
        $admin->setCreated(date('YmdHis'));
        $admin->setEmailVerified(true);
        $admin->setUserDetails(['firstName' => 'Ada', 'lastName' => 'Admin']);
        $em->persist($admin);
        $em->flush();
        $adminId = (int) $admin->getId();

        // --- Admin login: mints a plain admin access token (NO impersonator_id)
        // and a DB-backed admin refresh token. ---
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'recover-admin@example.com',
                'password' => 'AdminPass123!',
            ]),
        );
        $this->assertResponseIsSuccessful('Admin login should succeed');

        $jar = $client->getCookieJar();
        $refreshCookie = $jar->get('refresh_token');
        $accessCookie = $jar->get('access_token');
        $this->assertNotNull($refreshCookie, 'login must set a refresh_token cookie');
        $this->assertNotNull($accessCookie, 'login must set an access_token cookie');

        $adminRefreshValue = $refreshCookie->getValue();
        $plainAdminAccessValue = $accessCookie->getValue();

        // Recreate the corrupted state: stash = valid admin refresh, access =
        // plain admin token, regular refresh cleared. Reuse the refresh cookie's
        // path/domain so the jar replays the stash on the next request.
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

        // Sanity: the state we are about to exercise matches the trace.
        $this->assertNull($jar->get('refresh_token'), 'regular refresh cookie is cleared during impersonation');
        $this->assertNotNull($jar->get('admin_refresh_token'), 'stash present');
        $this->assertSame(
            $plainAdminAccessValue,
            $jar->get('access_token')?->getValue(),
            'access cookie is still the plain admin token',
        );

        // --- The pre-exit refresh that used to 401 + wipe everything ---
        $client->request('POST', '/api/v1/auth/refresh');

        $this->assertResponseIsSuccessful(
            'Corrupted-swap refresh MUST recover the admin session (200), not 401 + logout',
        );

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($payload['success'] ?? false, 'refresh response success=true');
        $this->assertSame(
            'recover-admin@example.com',
            $payload['user']['email'] ?? null,
            'recovered session must be the admin, not the (lost) impersonation target',
        );
        $this->assertTrue($payload['user']['isAdmin'] ?? false, 'recovered user is admin');

        // A fresh admin access token must be installed (not a clear cookie).
        $newAccess = $jar->get('access_token');
        $this->assertNotNull($newAccess, 'access cookie must be refreshed, not deleted');
        $this->assertNotSame('', $newAccess->getValue(), 'access cookie must carry a real token, not be wiped');

        // Crucially the stash must survive so the follow-up /impersonate/exit
        // can still restore the regular refresh cookie and clear the stash.
        $stashCookie = $jar->get('admin_refresh_token');
        $this->assertNotNull($stashCookie, 'stash must be kept on recovery so exit can tear it down cleanly');
        $this->assertNotSame(
            '',
            $stashCookie->getValue(),
            'stash must not be wiped by the recovery refresh',
        );

        // --- Cleanup (tokens first for FK safety) ---
        $em->clear();
        $tokens = $em->getRepository(Token::class)->findBy(['user' => $adminId]);
        foreach ($tokens as $token) {
            $em->remove($token);
        }
        $em->flush();
        $entity = $em->getRepository(User::class)->find($adminId);
        if ($entity) {
            $em->remove($entity);
            $em->flush();
        }
    }
}
