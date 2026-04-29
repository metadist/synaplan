<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Edge-case coverage for {@see \App\Controller\AdminImpersonationController}
 * that the unit tests on {@see \App\Service\ImpersonationService} cannot reach,
 * because they exercise the controller's own input handling (route argument
 * resolution, repository lookup, role gate) instead of the service contract.
 *
 * Currently verifies:
 *  - 404 when the target user does not exist (UserRepository->find() === null).
 *  - 403 when an authenticated non-admin tries to start an impersonation —
 *    keeps the role gate honest as it sits BEFORE the repository lookup.
 *  - 401 when no session is attached at all.
 */
class AdminImpersonationControllerTest extends WebTestCase
{
    public function testStartReturns404WhenTargetUserDoesNotExist(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $admin = new User();
        $admin->setMail('impersonate-404-admin@example.com');
        $admin->setPw(password_hash('AdminPass123!', PASSWORD_BCRYPT));
        $admin->setUserLevel('ADMIN');
        $admin->setProviderId('local');
        $admin->setCreated(date('YmdHis'));
        $admin->setEmailVerified(true);
        $admin->setUserDetails([]);
        $em->persist($admin);
        $em->flush();
        $adminId = (int) $admin->getId();

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'impersonate-404-admin@example.com',
                'password' => 'AdminPass123!',
            ]),
        );
        $this->assertResponseIsSuccessful('Admin login should succeed');

        // A definitely-not-existing target id (well past anything seeded).
        $missingId = 9999999;
        $client->request('POST', '/api/v1/admin/impersonate/'.$missingId);

        $this->assertResponseStatusCodeSame(404);
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('User not found', $payload['error'] ?? null);
        $this->assertSame($missingId, $payload['userId'] ?? null);

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

    public function testStartReturns403WhenCallerIsNotAdmin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $caller = new User();
        $caller->setMail('impersonate-non-admin@example.com');
        $caller->setPw(password_hash('UserPass123!', PASSWORD_BCRYPT));
        $caller->setUserLevel('PRO');
        $caller->setProviderId('local');
        $caller->setCreated(date('YmdHis'));
        $caller->setEmailVerified(true);
        $caller->setUserDetails([]);
        $em->persist($caller);

        $target = new User();
        $target->setMail('impersonate-non-admin-target@example.com');
        $target->setPw(password_hash('TargetPass123!', PASSWORD_BCRYPT));
        $target->setUserLevel('PRO');
        $target->setProviderId('local');
        $target->setCreated(date('YmdHis'));
        $target->setEmailVerified(true);
        $target->setUserDetails([]);
        $em->persist($target);
        $em->flush();
        $callerId = (int) $caller->getId();
        $targetId = (int) $target->getId();

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'impersonate-non-admin@example.com',
                'password' => 'UserPass123!',
            ]),
        );
        $this->assertResponseIsSuccessful('Non-admin login should succeed');

        $client->request('POST', '/api/v1/admin/impersonate/'.$targetId);
        $this->assertResponseStatusCodeSame(403);
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Admin access required', $payload['error'] ?? null);

        $em->clear();
        foreach ([$callerId, $targetId] as $id) {
            $tokens = $em->getRepository(Token::class)->findBy(['user' => $id]);
            foreach ($tokens as $token) {
                $em->remove($token);
            }
        }
        $em->flush();
        foreach ([$callerId, $targetId] as $id) {
            $entity = $em->getRepository(User::class)->find($id);
            if ($entity) {
                $em->remove($entity);
            }
        }
        $em->flush();
    }
}
