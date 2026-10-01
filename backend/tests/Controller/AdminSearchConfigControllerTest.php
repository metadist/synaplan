<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Model;
use App\Entity\RevectorizeRun;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\RevectorizeRunRepository;
use App\Service\SmartSearch\SearchModelConfigService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminSearchConfigControllerTest extends WebTestCase
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

    public function testMembersCannotSeeOrChangeTheSearchModels(): void
    {
        $this->authenticateClient($this->client, $this->createUser('search-models-member@synaplan.internal', 'NEW'));

        $this->client->request('GET', '/api/v1/admin/search/config');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->put(['slot' => 'ai', 'modelId' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBothSlotsInheritUntilAnAdminChooses(): void
    {
        $chat = $this->createModel('chat', 'search-user-chat');
        $admin = $this->createUser('search-models-read@synaplan.internal', 'ADMIN');
        $this->setDefault('CHAT', $chat, $admin->getId() ?? 0);
        $this->authenticateClient($this->client, $admin);

        $this->client->request('GET', '/api/v1/admin/search/config');
        self::assertResponseIsSuccessful();
        $body = $this->json();

        self::assertNull($body['ai']['selectedModelId']);
        self::assertSame($chat->getId(), $body['ai']['inheritedModelId']);
        self::assertSame($chat->getId(), $body['ai']['effectiveModelId']);
        self::assertNull($body['embed']['selectedModelId']);
        self::assertArrayHasKey('rows', $body['index']);
        self::assertContains($chat->getId(), array_column($body['ai']['options'], 'id'));
    }

    public function testAiModelChangeAppliesAtOnceAndCanBeUndone(): void
    {
        $this->setDefault('TOOLS', $this->createModel('chat', 'search-tools-undo'));
        $picked = $this->createModel('chat', 'search-ai-pick');
        $this->authenticateClient($this->client, $this->createUser('search-models-ai@synaplan.internal', 'ADMIN'));

        $body = $this->put(['slot' => 'ai', 'modelId' => $picked->getId()]);
        self::assertResponseIsSuccessful();
        self::assertNull($body['previousModelId']);
        self::assertNull($body['runId']);
        self::assertSame($picked->getId(), $body['config']['ai']['effectiveModelId']);

        $undo = $this->put(['slot' => 'ai', 'modelId' => $body['previousModelId']]);
        self::assertResponseIsSuccessful();
        self::assertSame($picked->getId(), $undo['previousModelId']);
        self::assertNull($undo['config']['ai']['selectedModelId']);
    }

    public function testRejectsAModelOfTheWrongKind(): void
    {
        $embedding = $this->createModel('vectorize', 'search-wrong-kind');
        $this->authenticateClient($this->client, $this->createUser('search-models-kind@synaplan.internal', 'ADMIN'));

        $body = $this->put(['slot' => 'ai', 'modelId' => $embedding->getId()]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_model', $body['reason']);

        $this->put(['slot' => 'nope', 'modelId' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testEmbeddingChangeStartsOneSearchReindexRun(): void
    {
        $target = $this->createModel('vectorize', 'search-embed-target');
        $this->authenticateClient($this->client, $this->createUser('search-models-embed@synaplan.internal', 'ADMIN'));

        $body = $this->put(['slot' => 'embed', 'modelId' => $target->getId()]);
        self::assertResponseIsSuccessful();
        self::assertIsInt($body['runId']);
        self::assertSame($target->getId(), $body['config']['embed']['effectiveModelId']);
        self::assertSame($body['runId'], $body['config']['activeRun']['id']);

        $run = static::getContainer()->get(RevectorizeRunRepository::class)->find($body['runId']);
        self::assertInstanceOf(RevectorizeRun::class, $run);
        self::assertSame(RevectorizeRun::SCOPE_SEARCH, $run->getScope());
        self::assertSame($target->getId(), $run->getModelToId());

        $second = $this->put(['slot' => 'embed', 'modelId' => $this->createModel('vectorize', 'search-embed-second')->getId()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('run_in_progress', $second['reason']);
        self::assertSame(
            (string) $target->getId(),
            static::getContainer()->get(ConfigRepository::class)->getValue(0, 'DEFAULTMODEL', SearchModelConfigService::SLOT_EMBED),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function put(array $payload): array
    {
        $this->client->request(
            'PUT',
            '/api/v1/admin/search/config',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function createModel(string $tag, string $providerId): Model
    {
        $model = (new Model())
            ->setService('test')
            ->setName('Search '.$providerId)
            ->setTag($tag)
            ->setSelectable(1)
            ->setProviderId($providerId)
            ->setPriceIn(0)
            ->setPriceOut(0);
        $this->em->persist($model);
        $this->em->flush();

        return $model;
    }

    private function setDefault(string $capability, Model $model, int $owner = 0): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue($owner, 'DEFAULTMODEL', $capability, (string) $model->getId());
    }

    private function createUser(string $email, string $level): User
    {
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('search-models-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
