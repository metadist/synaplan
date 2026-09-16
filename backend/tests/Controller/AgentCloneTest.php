<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\FileRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AgentCloneTest extends WebTestCase
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

    public function testCloneCopiesDraftAndPromptWithoutFiles(): void
    {
        $user = $this->createUser('agent-clone@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/agents', [
            'name' => 'Contract review',
            'description' => 'Reviews NDAs',
            'draft' => [
                'schema' => 'agent.v1',
                'models' => ['chat' => 'anthropic:claude-sonnet-4:chat', 'vision' => null, 'vectorize' => null],
                'knowledge' => [
                    'ownFolder' => true,
                    'folders' => ['4:shared-legal'],
                    'ragLimit' => 5,
                    'ragMinScore' => 0.4,
                ],
            ],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $source = $this->json()['agent'];
        $sourceId = (int) $source['id'];

        $prompt = static::getContainer()->get(PromptRepository::class)->find($source['promptId']);
        self::assertInstanceOf(Prompt::class, $prompt);
        $prompt->setPrompt('Review NDAs against the checklist.');
        $this->em->flush();

        $file = (new File())
            ->setUserId((int) $user->getId())
            ->setFilePath('clone-source.txt')
            ->setFileType('txt')
            ->setFileName('nda.txt')
            ->setFileSize(4)
            ->setFileMime('text/plain')
            ->setFileText('nda')
            ->setStatus('processed')
            ->setGroupKey('TASKPROMPT:agent:'.$source['slug']);
        $this->em->persist($file);
        $this->em->flush();

        $this->client->request('POST', '/api/v1/agents/'.$sourceId.'/clone');
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $clone = $this->json()['agent'];

        self::assertSame($sourceId, $clone['parentId']);
        self::assertSame('draft', $clone['status']);
        self::assertSame('manual', $clone['source']);
        self::assertSame('contract-review-copy', $clone['slug']);
        self::assertSame('Contract review copy', $clone['name']);
        self::assertNotSame($source['promptId'], $clone['promptId']);
        self::assertSame('anthropic:claude-sonnet-4:chat', $clone['draft']['models']['chat']);
        self::assertSame(['4:shared-legal'], $clone['draft']['knowledge']['folders']);
        self::assertTrue($clone['draft']['knowledge']['ownFolder']);

        $clonePrompt = static::getContainer()->get(PromptRepository::class)->find($clone['promptId']);
        self::assertInstanceOf(Prompt::class, $clonePrompt);
        self::assertSame('Review NDAs against the checklist.', $clonePrompt->getPrompt());
        self::assertSame('agent:contract-review-copy', $clonePrompt->getTopic());

        $files = static::getContainer()->get(FileRepository::class)->findBy([
            'userId' => (int) $user->getId(),
            'groupKey' => 'TASKPROMPT:agent:'.$clone['slug'],
        ]);
        self::assertSame([], $files);

        $this->client->request(
            'PATCH',
            '/api/v1/agents/'.$clone['id'],
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'My copy'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/agents/'.$sourceId);
        self::assertSame('Contract review', $this->json()['agent']['name']);
    }

    public function testCloneSlugDedupes(): void
    {
        $user = $this->createUser('agent-clone-dedupe@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->postJson('/api/v1/agents', ['name' => 'Policy helper']);
        $id = (int) $this->json()['agent']['id'];

        $this->client->request('POST', '/api/v1/agents/'.$id.'/clone');
        self::assertSame('policy-helper-copy', $this->json()['agent']['slug']);

        $this->client->request('POST', '/api/v1/agents/'.$id.'/clone');
        self::assertSame('policy-helper-copy-2', $this->json()['agent']['slug']);
    }

    public function testCloneForeignIdIs404(): void
    {
        $owner = $this->createUser('agent-clone-owner@synaplan.internal');
        $other = $this->createUser('agent-clone-other@synaplan.internal');
        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/agents', ['name' => 'Secret assistant']);
        $id = (int) $this->json()['agent']['id'];

        $this->authenticateClient($this->client, $other);
        $this->client->request('POST', '/api/v1/agents/'.$id.'/clone');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
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
            ->setProviderId('agent-clone-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
