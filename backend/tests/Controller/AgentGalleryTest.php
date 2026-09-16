<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AgentGalleryTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    public function testGalleryReturnsMineCardsWithoutDraft(): void
    {
        $user = $this->createUser('agent-gallery@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/agents', [
            'name' => 'Contract review',
            'description' => 'Reviews NDAs',
            'draft' => [
                'schema' => 'agent.v1',
                'behaviour' => [
                    'starterPrompts' => ['Review this NDA', 'List the risks', 'Rewrite clause 4', 'Fourth is dropped'],
                    'greeting' => 'Hello',
                    'memory' => 'user',
                ],
            ],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/v1/agents/gallery');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertTrue($body['success']);
        self::assertNotEmpty($body['cards']);
        $card = $body['cards'][0];
        self::assertSame('Contract review', $card['name']);
        self::assertSame('mine', $card['origin']);
        self::assertSame('agent-gallery@synaplan.internal', $card['ownerName']);
        self::assertNull($card['version']);
        self::assertSame(['Review this NDA', 'List the risks', 'Rewrite clause 4'], $card['starterPrompts']);
        self::assertArrayNotHasKey('draft', $card);
        self::assertArrayNotHasKey('promptId', $card);
    }

    public function testGalleryDoesNotListForeignAssistants(): void
    {
        $owner = $this->createUser('agent-gallery-owner@synaplan.internal');
        $other = $this->createUser('agent-gallery-other@synaplan.internal');
        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/agents', ['name' => 'Private review']);

        $this->authenticateClient($this->client, $other);
        $this->client->request('GET', '/api/v1/agents/gallery');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        foreach ($this->json()['cards'] as $card) {
            self::assertNotSame('Private review', $card['name']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    private function createUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('agent-gallery-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
