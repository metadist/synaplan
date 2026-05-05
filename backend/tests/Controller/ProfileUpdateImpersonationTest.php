<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\TokenService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Reproduces a bug where an admin impersonating another user updates the
 * profile (e.g. firstName), receives a 200 OK, but the change is not
 * persisted in the database.
 */
class ProfileUpdateImpersonationTest extends WebTestCase
{
    public function testImpersonatorUpdatesTargetProfileAndItPersists(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();
        $tokenService = $client->getContainer()->get(TokenService::class);

        // 1) Create admin
        $admin = new User();
        $admin->setMail('imp-admin@example.com');
        $admin->setPw(password_hash('AdminPass123!', PASSWORD_BCRYPT));
        $admin->setUserLevel('PRO');
        $admin->setProviderId('local');
        $admin->setCreated(date('YmdHis'));
        $admin->setUserDetails(['admin' => true]);
        $em->persist($admin);

        // 2) Create target user
        $target = new User();
        $target->setMail('imp-target@example.com');
        $target->setPw(password_hash('TargetPass123!', PASSWORD_BCRYPT));
        $target->setUserLevel('PRO');
        $target->setProviderId('local');
        $target->setCreated(date('YmdHis'));
        $target->setUserDetails([
            'firstName' => 'OldFirst',
            'lastName' => 'OldLast',
        ]);
        $em->persist($target);
        $em->flush();

        $targetId = (int) $target->getId();
        $adminId = (int) $admin->getId();

        // 3) Mint impersonation access token (target user, admin claim)
        $impersonationAccessToken = $tokenService->generateAccessToken(
            $target,
            impersonatorId: $adminId,
        );

        // Set cookie on client
        $client->getCookieJar()->set(new Cookie(
            TokenService::ACCESS_COOKIE,
            $impersonationAccessToken,
            (string) (time() + TokenService::ACCESS_TOKEN_TTL),
            '/',
            'localhost',
        ));

        // 4) PUT /api/v1/profile with new firstName/lastName
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

        $this->assertResponseIsSuccessful('PUT /api/v1/profile must succeed when impersonating');

        // 5) Force fresh DB read (clear identity map, re-fetch)
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($targetId);

        $this->assertInstanceOf(User::class, $reloaded);

        $details = $reloaded->getUserDetails();

        $this->assertSame(
            'NewFirst',
            $details['firstName'] ?? null,
            'Target user firstName must be persisted as NewFirst after impersonator update.',
        );
        $this->assertSame(
            'NewLast',
            $details['lastName'] ?? null,
            'Target user lastName must be persisted as NewLast after impersonator update.',
        );

        // 6) Sanity-check: GET /api/v1/profile via the impersonation cookie returns the new data
        $client->request('GET', '/api/v1/profile');
        $this->assertResponseIsSuccessful('GET /api/v1/profile must succeed when impersonating');

        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('NewFirst', $payload['profile']['firstName'] ?? null);
        $this->assertSame('NewLast', $payload['profile']['lastName'] ?? null);
        $this->assertSame('imp-target@example.com', $payload['profile']['email'] ?? null);

        // Cleanup
        $em->clear();
        $reloadedTarget = $em->getRepository(User::class)->find($targetId);
        if ($reloadedTarget) {
            $em->remove($reloadedTarget);
        }
        $reloadedAdmin = $em->getRepository(User::class)->find($adminId);
        if ($reloadedAdmin) {
            $em->remove($reloadedAdmin);
        }
        $em->flush();
    }
}
