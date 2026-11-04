<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LegacyApiControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    public function testProfileIsTheSignedInAccountWhenAnotherUserIdIsSupplied(): void
    {
        [$client, $user, $other, $token] = $this->twoAccounts();

        $client->request(
            'GET',
            '/api.php?action=getProfile&user_id='.(int) $other->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame((int) $user->getId(), (int) ($data['profile']['id'] ?? 0));
        self::assertSame($user->getMail(), $data['profile']['email'] ?? null);
    }

    public function testMessageGetHidesAnotherAccountsMessage(): void
    {
        [$client, , $other, $token] = $this->twoAccounts();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $message = new Message();
        $message->setUserId((int) $other->getId());
        $message->setTrackingId(time());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setText('private-marker-legacy-api');
        $message->setDirection('IN');
        $message->setProviderIndex('WEB');
        $message->setMessageType('TEST');
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setStatus('complete');
        $em->persist($message);
        $em->flush();
        $messageId = (int) $message->getId();

        try {
            $client->request(
                'GET',
                '/api.php?action=messageGet&message_id='.$messageId,
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
            );

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('private-marker-legacy-api', (string) $client->getResponse()->getContent());
        } finally {
            $cleanup = $client->getContainer()->get(EntityManagerInterface::class);
            $stored = $cleanup->find(Message::class, $messageId);
            if ($stored instanceof Message) {
                $cleanup->remove($stored);
                $cleanup->flush();
            }
        }
    }

    public function testSendEmailIsGone(): void
    {
        [$client, , , $token] = $this->twoAccounts();

        $client->request(
            'POST',
            '/api.php?action=sendEmail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode(['email' => 'someone-else@example.com'])
        );

        self::assertResponseStatusCodeSame(410);
    }

    /**
     * @return array{0: KernelBrowser, 1: User, 2: User, 3: string}
     */
    private function twoAccounts(): array
    {
        $client = static::createClient();
        $repository = $client->getContainer()->get('doctrine')->getRepository(User::class);
        $user = $repository->findOneBy(['mail' => 'admin@synaplan.com']);
        $other = $repository->findOneBy(['mail' => 'demo@synaplan.com']);
        if (!$user instanceof User || !$other instanceof User) {
            self::markTestSkipped('Fixture users admin@synaplan.com and demo@synaplan.com are required.');
        }

        return [$client, $user, $other, $this->authenticateClient($client, $user)];
    }
}
