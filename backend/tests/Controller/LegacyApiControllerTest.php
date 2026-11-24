<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\PromptMetaRepository;
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

    public function testCookieSessionCannotCallTheLegacyApiThroughGet(): void
    {
        // What an <img src> in somebody else's text sends: the URL and the
        // reader's cookie, nothing else.
        [$client] = $this->twoAccounts();

        $client->request('GET', '/api.php?action=promptUpdate&topic=general&tool_files=1');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('POST', $client->getResponse()->headers->get('Allow'));

        $client->request('GET', '/api.php?action=createApiKey');
        self::assertResponseStatusCodeSame(405);
    }

    public function testPromptUpdateRefusesAPromptOfAnotherOwner(): void
    {
        [$client, , $other, $token] = $this->twoAccounts();

        // The shared system prompt resolves for every account; a private
        // prompt of somebody else does not resolve at all.
        foreach ([0 => 403, (int) $other->getId() => 404] as $ownerId => $expectedStatus) {
            $prompt = $this->storePrompt($client, $ownerId);
            try {
                $this->postPromptUpdate($client, $token, $prompt->getTopic());

                self::assertResponseStatusCodeSame($expectedStatus);
                $meta = $client->getContainer()->get(PromptMetaRepository::class);
                self::assertSame([], $meta->findByPrompt((int) $prompt->getId()));
            } finally {
                $this->removePrompt($client, (int) $prompt->getId());
            }
        }
    }

    public function testPromptUpdateWritesTheCallersOwnPrompt(): void
    {
        [$client, $user, , $token] = $this->twoAccounts();
        $prompt = $this->storePrompt($client, (int) $user->getId());

        try {
            $this->postPromptUpdate($client, $token, $prompt->getTopic());

            self::assertResponseIsSuccessful();
            $meta = $client->getContainer()->get(PromptMetaRepository::class);
            $stored = $meta->findByPromptAndKey((int) $prompt->getId(), 'tool_files');
            self::assertNotNull($stored);
            self::assertSame('1', $stored->getMetaValue());
        } finally {
            $this->removePrompt($client, (int) $prompt->getId());
        }
    }

    private function postPromptUpdate(KernelBrowser $client, string $token, string $topic): void
    {
        $client->request(
            'POST',
            '/api.php?action=promptUpdate',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode(['topic' => $topic, 'tool_files' => true])
        );
    }

    private function storePrompt(KernelBrowser $client, int $ownerId): Prompt
    {
        $prompt = new Prompt();
        $prompt->setOwnerId($ownerId);
        $prompt->setLanguage('en');
        $prompt->setTopic('legacy-'.bin2hex(random_bytes(6)));
        $prompt->setShortDescription('Legacy API test prompt');
        $prompt->setPrompt('You are a test prompt.');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($prompt);
        $em->flush();

        return $prompt;
    }

    private function removePrompt(KernelBrowser $client, int $promptId): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ($client->getContainer()->get(PromptMetaRepository::class)->findByPrompt($promptId) as $meta) {
            $em->remove($meta);
        }
        $stored = $em->find(Prompt::class, $promptId);
        if ($stored instanceof Prompt) {
            $em->remove($stored);
        }
        $em->flush();
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
