<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SavedPrompt;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SavedPromptControllerTest extends WebTestCase
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

    public function testUpdateChangesNameCommandAndBodyOfOwnPrompt(): void
    {
        $owner = $this->createUser('saved-prompt-owner@synaplan.internal');
        $this->authenticateClient($this->client, $owner);

        $this->sendJson('POST', '/api/v1/saved-prompts', ['name' => 'Note', 'command' => 'notiz', 'body' => 'Write a note', 'tags' => ['work']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json()['prompt'] ?? null;
        self::assertIsArray($created);
        $id = (int) $created['id'];

        $this->sendJson('PUT', '/api/v1/saved-prompts/'.$id, ['name' => 'Note 2', 'command' => 'notiz2', 'body' => 'Write a better note', 'tags' => ['work']]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = $this->json();
        self::assertTrue($body['success'] ?? false);
        self::assertSame($id, $body['prompt']['id'] ?? null);
        self::assertSame('Note 2', $body['prompt']['name'] ?? null);
        self::assertSame('notiz2', $body['prompt']['command'] ?? null);
        self::assertSame('Write a better note', $body['prompt']['body'] ?? null);
        self::assertSame(['work'], $body['prompt']['tags'] ?? null);

        $this->client->request('GET', '/api/v1/saved-prompts');
        $commands = array_column($this->json()['prompts'] ?? [], 'command');
        self::assertSame(['notiz2'], $commands);
    }

    public function testUpdateRejectsACommandAnotherOwnPromptAlreadyUses(): void
    {
        $owner = $this->createUser('saved-prompt-dupe@synaplan.internal');
        $first = $this->createPrompt($owner, 'First', 'first');
        $this->createPrompt($owner, 'Second', 'second');
        $this->authenticateClient($this->client, $owner);

        $this->sendJson('PUT', '/api/v1/saved-prompts/'.$first->getId(), ['name' => 'First', 'command' => 'second', 'body' => 'Text']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('You already have a prompt with that command.', $this->json()['error'] ?? null);
        $this->em->clear();
        self::assertSame('first', $this->em->find(SavedPrompt::class, $first->getId())?->getCommand());
    }

    public function testUpdateOfAnotherUsersPromptIs404(): void
    {
        $owner = $this->createUser('saved-prompt-private@synaplan.internal');
        $other = $this->createUser('saved-prompt-other@synaplan.internal');
        $prompt = $this->createPrompt($owner, 'Private', 'private');
        $this->authenticateClient($this->client, $other);

        $this->sendJson('PUT', '/api/v1/saved-prompts/'.$prompt->getId(), ['name' => 'Taken', 'command' => 'taken', 'body' => 'Text']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->em->clear();
        self::assertSame('private', $this->em->find(SavedPrompt::class, $prompt->getId())?->getCommand());
    }

    public function testDeleteRemovesOwnPrompt(): void
    {
        $owner = $this->createUser('saved-prompt-delete@synaplan.internal');
        $prompt = $this->createPrompt($owner, 'Gone', 'gone');
        $id = $prompt->getId();
        $this->authenticateClient($this->client, $owner);

        $this->client->request('DELETE', '/api/v1/saved-prompts/'.$id);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertTrue($this->json()['success'] ?? false);
        $this->em->clear();
        self::assertNull($this->em->find(SavedPrompt::class, $id));
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('saved-prompt-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createPrompt(User $owner, string $name, string $command): SavedPrompt
    {
        $prompt = (new SavedPrompt())
            ->setUserId((int) $owner->getId())
            ->setName($name)
            ->setCommand($command)
            ->setBody('Text');
        $this->em->persist($prompt);
        $this->em->flush();

        return $prompt;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJson(string $method, string $uri, array $payload): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
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
}
