<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Iam\IamConfig;
use App\Service\RAG\RagScopeResolver;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two Agent Builder success criteria the S3 review found unmet:
 *  - S2: a recipient with "use" on a published assistant searches its knowledge;
 *  - S1: assistant instruction prompts stay out of the unpinned classifier
 *    unless the assistant is published and routable.
 */
final class AgentSharedKnowledgeTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
        $this->em->flush();
    }

    public function testRecipientSearchesTheOwnersAssistantFolderAndSeesPublishedStarters(): void
    {
        $owner = $this->createUser('agent-knowledge-owner@synaplan.internal');
        $recipient = $this->createUser('agent-knowledge-recipient@synaplan.internal');
        $stranger = $this->createUser('agent-knowledge-stranger@synaplan.internal');
        $ownerId = (int) $owner->getId();
        $recipientId = (int) $recipient->getId();

        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/agents', [
            'name' => 'Knowledge review '.uniqid(),
            'draft' => [
                'schema' => 'agent.v1',
                'behaviour' => ['starterPrompts' => ['Published starter']],
                'knowledge' => ['ownFolder' => true, 'folders' => [(int) $stranger->getId().':third-party']],
            ],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $agent = $this->json()['agent'];
        $agentId = (int) $agent['id'];
        $ownFolder = 'TASKPROMPT:agent:'.$agent['slug'];

        $this->postJson('/api/v1/agents/'.$agentId.'/publish', ['changelog' => 'v1']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        // Owner keeps editing after publishing — the recipient must not see this.
        $this->client->request(
            'PATCH',
            '/api/v1/agents/'.$agentId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['draft' => $agent['draft'] + ['behaviour' => ['starterPrompts' => ['Unpublished secret starter']]]], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $ragScopes = static::getContainer()->get(RagScopeResolver::class);
        $runtime = static::getContainer()->get(AgentRuntimeResolver::class);

        // Before the share: no access, so RAG falls back to the recipient's own files.
        $fallback = $ragScopes->resolve($recipientId, RagScopeResolver::sharedPickerKey($ownerId, $ownFolder));
        self::assertCount(1, $fallback);
        self::assertSame($recipientId, $fallback[0]->ownerId);
        self::assertNull($fallback[0]->groupKey);

        $this->postJson('/api/v1/shares', [
            'kind' => 'agent',
            'resource' => (string) $agentId,
            'subjectType' => 'user',
            'subjectId' => $recipientId,
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $profile = $runtime->resolve($agentId, $recipient);
        self::assertSame(RagScopeResolver::sharedPickerKey($ownerId, $ownFolder), $profile->primaryRagGroupKey(), 'a foreign scope is emitted in the shared picker form');

        $scopes = $ragScopes->resolve($recipientId, (string) $profile->primaryRagGroupKey());
        self::assertCount(1, $scopes);
        self::assertSame($ownerId, $scopes[0]->ownerId, "the recipient searches the owner's files");
        self::assertSame($ownFolder, $scopes[0]->groupKey);

        // The owner's own resolution is unchanged: a plain group key on their own files.
        self::assertSame($ownFolder, $runtime->resolve($agentId, $owner)->primaryRagGroupKey());

        // A third user's folder listed in the definition is not forwarded through the assistant share.
        $thirdParty = $ragScopes->resolve($recipientId, RagScopeResolver::sharedPickerKey((int) $stranger->getId(), 'third-party'));
        self::assertSame($recipientId, $thirdParty[0]->ownerId, 'no transitive grant on a stranger\'s folder');

        $this->authenticateClient($this->client, $recipient);
        $this->client->request('GET', '/api/v1/agents/gallery');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $card = null;
        foreach ($this->json()['cards'] as $candidate) {
            if ((int) $candidate['id'] === $agentId) {
                $card = $candidate;
            }
        }
        self::assertIsArray($card, 'shared assistant appears in the recipient gallery');
        self::assertSame('shared', $card['origin']);
        self::assertSame(1, $card['version']);
        self::assertSame(['Published starter'], $card['starterPrompts'], 'starter prompts come from the published snapshot, not the live draft');
    }

    public function testAssistantTopicsReachTheClassifierOnlyWhenPublishedAndRoutable(): void
    {
        $owner = $this->createUser('agent-routable-owner@synaplan.internal');
        $ownerId = (int) $owner->getId();
        $this->authenticateClient($this->client, $owner);

        $this->postJson('/api/v1/agents', ['name' => 'Routing test '.uniqid()]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $agent = $this->json()['agent'];
        $agentId = (int) $agent['id'];
        $topic = 'agent:'.$agent['slug'];

        $prompts = static::getContainer()->get(PromptRepository::class);
        self::assertNotContains($topic, $prompts->getAllTopics(0, $ownerId), 'a draft assistant is invisible to the sorter');
        self::assertNotContains($topic, array_column($prompts->getTopicsWithDescriptions(0, 'en', $ownerId), 'topic'));

        $this->postJson('/api/v1/agents/'.$agentId.'/publish', ['changelog' => 'v1']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertNotContains($topic, $prompts->getAllTopics(0, $ownerId), 'published but not routable stays pinned-only');

        $this->client->request(
            'PATCH',
            '/api/v1/agents/'.$agentId,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['routable' => true], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertContains($topic, $prompts->getAllTopics(0, $ownerId), 'routable assistants are offered to the sorter');
        self::assertContains($topic, array_column($prompts->getTopicsWithDescriptions(0, 'en', $ownerId), 'topic'));

        $otherId = (int) $this->createUser('agent-routable-other@synaplan.internal')->getId();
        self::assertNotContains($topic, $prompts->getAllTopics(0, $otherId), 'routable is per owner, never global');
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
            ->setProviderId('agent-knowledge-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
