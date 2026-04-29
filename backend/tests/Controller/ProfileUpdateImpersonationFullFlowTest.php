<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Full end-to-end reproducer of the bug report:
 *   "Ich habe gerade versucht, den namen im profil als impersonater zu
 *    ändern, er hats zwar erfolgreich angezeigt aber beim refresh der
 *    seite hat er das nicht gespeichert und den alten angezeigt."
 *
 * Walks the exact request path the browser does:
 *   1) Admin login (gets access + refresh cookies)
 *   2) POST /admin/users/{id}/impersonate (cookies swap to target)
 *   3) PUT /api/v1/profile  (firstName = NewFirst)
 *   4) GET /api/v1/profile  (must reflect NewFirst)
 *   5) Independent DB read  (must reflect NewFirst)
 */
class ProfileUpdateImpersonationFullFlowTest extends WebTestCase
{
    public function testAdminImpersonatorProfileUpdatePersists(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // --- Seed admin + target ---
        $admin = new User();
        $admin->setMail('full-flow-admin@example.com');
        $admin->setPw(password_hash('AdminPass123!', PASSWORD_BCRYPT));
        $admin->setUserLevel('ADMIN');
        $admin->setProviderId('local');
        $admin->setCreated(date('YmdHis'));
        $admin->setEmailVerified(true);
        $admin->setUserDetails([]);
        $em->persist($admin);

        $target = new User();
        $target->setMail('full-flow-target@example.com');
        $target->setPw(password_hash('TargetPass123!', PASSWORD_BCRYPT));
        $target->setUserLevel('PRO');
        $target->setProviderId('local');
        $target->setCreated(date('YmdHis'));
        $target->setEmailVerified(true);
        $target->setUserDetails([
            'firstName' => 'OldFirst',
            'lastName' => 'OldLast',
        ]);
        $em->persist($target);
        $em->flush();

        $targetId = (int) $target->getId();
        $adminId = (int) $admin->getId();

        // --- 1) Admin login ---
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'full-flow-admin@example.com',
                'password' => 'AdminPass123!',
            ]),
        );
        $this->assertResponseIsSuccessful('Admin login should succeed');

        // --- 2) Start impersonation ---
        $client->request('POST', '/api/v1/admin/impersonate/'.$targetId);
        $this->assertResponseIsSuccessful('Start impersonation should succeed');

        // Verify the impersonation cookies are in place
        $cookieJar = $client->getCookieJar();
        $this->assertNotNull($cookieJar->get('access_token'), 'access_token cookie present');
        $this->assertNotNull($cookieJar->get('admin_refresh_token'), 'admin_refresh_token stash present');

        // --- 3) PUT /api/v1/profile ---
        $client->request(
            'PUT',
            '/api/v1/profile',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'firstName' => 'NewFirst',
                'lastName' => 'NewLast',
            ]),
        );
        $this->assertResponseIsSuccessful('Profile update while impersonating should succeed');
        $putPayload = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($putPayload['success'] ?? false, 'PUT response success=true');

        // --- 4) GET /api/v1/profile (same client, same cookies) ---
        $client->request('GET', '/api/v1/profile');
        $this->assertResponseIsSuccessful('GET profile while impersonating should succeed');
        $getPayload = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(
            'full-flow-target@example.com',
            $getPayload['profile']['email'] ?? null,
            'GET must return the TARGET user profile, not the admin profile',
        );
        $this->assertSame(
            'NewFirst',
            $getPayload['profile']['firstName'] ?? null,
            'GET firstName must reflect the just-saved NewFirst',
        );
        $this->assertSame(
            'NewLast',
            $getPayload['profile']['lastName'] ?? null,
            'GET lastName must reflect the just-saved NewLast',
        );

        // --- 5) Independent DB readback (simulate full page reload) ---
        $em->clear();
        /** @var UserRepository $userRepo */
        $userRepo = $em->getRepository(User::class);
        $reloaded = $userRepo->find($targetId);

        $this->assertInstanceOf(User::class, $reloaded);
        $this->assertSame(
            'NewFirst',
            $reloaded->getUserDetails()['firstName'] ?? null,
            'DB row must contain NewFirst after impersonator update',
        );
        $this->assertSame(
            'NewLast',
            $reloaded->getUserDetails()['lastName'] ?? null,
            'DB row must contain NewLast after impersonator update',
        );

        // --- Cleanup (remove tokens first to satisfy FK constraint) ---
        $em->clear();
        foreach ([$targetId, $adminId] as $id) {
            $tokens = $em->getRepository(Token::class)->findBy(['user' => $id]);
            foreach ($tokens as $token) {
                $em->remove($token);
            }
        }
        $em->flush();
        foreach ([$targetId, $adminId] as $id) {
            $entity = $em->getRepository(User::class)->find($id);
            if ($entity) {
                $em->remove($entity);
            }
        }
        $em->flush();
    }
}
