<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Telegram channel is an admin preview: a signed-in non-admin gets the
 * same 404 as a missing route, and an admin can read their (empty) bot.
 */
final class TelegramChannelAdminPreviewTest extends WebTestCase
{
    public function testChannelIsHiddenFromARegularUserAndOpenForAnAdmin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $member = $this->user('preview-member', 'NEW');
        $admin = $this->user('preview-admin', 'ADMIN');
        $em->persist($member);
        $em->persist($admin);
        $em->flush();
        $memberId = (int) $member->getId();
        $adminId = (int) $admin->getId();

        try {
            $client->request('GET', '/api/v1/channels/telegram');
            $this->assertResponseStatusCodeSame(401);

            $this->login($client, (string) $member->getMail());
            $client->request('GET', '/api/v1/channels/telegram');
            $this->assertResponseStatusCodeSame(404);
            $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame(['error' => 'not_found'], $body);

            $this->login($client, (string) $admin->getMail());
            $client->request('GET', '/api/v1/channels/telegram');
            $this->assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertTrue($payload['success'] ?? false);
            $this->assertSame('none', $payload['status'] ?? null);
        } finally {
            $this->removeUser($memberId);
            $this->removeUser($adminId);
        }
    }

    private function user(string $name, string $level): User
    {
        $user = new User();
        $user->setMail($name.'-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPw(password_hash('PreviewPass123!', \PASSWORD_BCRYPT));
        $user->setUserLevel($level);
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails([]);

        return $user;
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $email,
                'password' => 'PreviewPass123!',
            ], \JSON_THROW_ON_ERROR),
        );
        $this->assertResponseIsSuccessful();
    }

    private function removeUser(int $userId): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        foreach ($em->getRepository(Token::class)->findBy(['user' => $userId]) as $token) {
            $em->remove($token);
        }
        $em->flush();
        $entity = $em->getRepository(User::class)->find($userId);
        if ($entity instanceof User) {
            $em->remove($entity);
            $em->flush();
        }
    }
}
