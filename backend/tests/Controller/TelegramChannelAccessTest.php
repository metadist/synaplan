<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every signed-in user can reach their own Telegram bot; anonymous callers
 * still need to sign in.
 */
final class TelegramChannelAccessTest extends WebTestCase
{
    public function testChannelIsOpenForARegularUser(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $member = $this->user('telegram-member', 'NEW');
        $em->persist($member);
        $em->flush();
        $memberId = (int) $member->getId();

        try {
            $client->request('GET', '/api/v1/channels/telegram');
            $this->assertResponseStatusCodeSame(401);

            $this->login($client, (string) $member->getMail());
            $client->request('GET', '/api/v1/channels/telegram');
            $this->assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertTrue($payload['success'] ?? false);
            $this->assertSame('none', $payload['status'] ?? null);
        } finally {
            $this->removeUser($memberId);
        }
    }

    private function user(string $name, string $level): User
    {
        $user = new User();
        $user->setMail($name.'-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPw(password_hash('AccessPass123!', \PASSWORD_BCRYPT));
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
                'password' => 'AccessPass123!',
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
