<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminUserResourcesTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testReturnsCardsOnly(): void
    {
        $this->setGroupsFlag('1');
        $admin = $this->createUser('iam-meta-admin@synaplan.internal', 'ADMIN');
        $owner = $this->createUser('iam-meta-owner@synaplan.internal', 'NEW');
        $chat = new Chat();
        $chat->setUserId((int) $owner->getId());
        $chat->setTitle('Private notes');
        $this->em->persist($chat);
        $this->em->flush();

        $this->authenticateClient($this->client, $admin);
        $this->client->request('GET', '/api/v1/admin/users/'.$owner->getId().'/resources');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $cards = $payload['resources'] ?? [];
        self::assertIsArray($cards);
        $conversation = null;
        foreach ($cards as $card) {
            self::assertArrayHasKey('kind', $card);
            self::assertArrayHasKey('name', $card);
            self::assertArrayHasKey('icon', $card);
            self::assertArrayHasKey('shareCount', $card);
            self::assertArrayNotHasKey('content', $card);
            self::assertArrayNotHasKey('messages', $card);
            if ('conversation' === ($card['kind'] ?? '') && (string) $chat->getId() === (string) ($card['id'] ?? '')) {
                $conversation = $card;
            }
        }
        self::assertNotNull($conversation);
        self::assertSame('Private notes', $conversation['name']);
        self::assertSame(0, $conversation['shareCount']);
    }

    private function setGroupsFlag(string $value): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, $value);
        $this->em->flush();
    }

    private function createUser(string $email, string $level): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            $existing->setUserLevel($level);
            $this->em->flush();

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('iam-test-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
