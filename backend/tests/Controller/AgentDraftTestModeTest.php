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

final class AgentDraftTestModeTest extends WebTestCase
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

    public function testNonOwnerDraftTrueIs403(): void
    {
        $owner = $this->createUser('agent-draft-owner@synaplan.internal');
        $other = $this->createUser('agent-draft-other@synaplan.internal');
        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/agents', ['name' => 'Draft only']);
        $id = (int) $this->json()['agent']['id'];

        $this->authenticateClient($this->client, $other);
        $this->client->request(
            'POST',
            '/api/v1/messages/stream',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'message' => 'hello',
                'agentId' => $id,
                'draft' => '1',
                'incognito' => '1',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testDraftTrueWithoutAgentIdIs403(): void
    {
        $user = $this->createUser('agent-draft-noid@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->client->request(
            'POST',
            '/api/v1/messages/stream',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'message' => 'hello',
                'draft' => true,
                'incognito' => '1',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
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
            ->setProviderId('agent-draft-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
