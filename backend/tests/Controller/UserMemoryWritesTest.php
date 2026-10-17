<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserMemory;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST and PUT /api/v1/user/memories: a memory is created and edited by its
 * owner, the fields are checked, and a memory of another account is not
 * found and stays as it was.
 */
final class UserMemoryWritesTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;

    /** @var list<int> */
    private array $owners = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(UserMemory::class)->findBy(['userId' => $this->owners]) as $memory) {
            $this->em->remove($memory);
        }
        $this->em->flush();
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testTheOwnerCreatesAndEditsAMemory(): void
    {
        $this->authenticateClient($this->client, $this->owner('memory-owner'));

        $created = $this->send('POST', '/api/v1/user/memories', ['category' => 'preferences', 'key' => 'ui_theme', 'value' => 'Prefers dark mode']);
        self::assertResponseStatusCodeSame(201);
        $id = $created['memory']['id'] ?? null;

        $updated = $this->send('PUT', '/api/v1/user/memories/'.$id, ['value' => 'Prefers light mode', 'key' => 'theme']);
        self::assertResponseIsSuccessful();
        self::assertSame(['preferences', 'theme', 'Prefers light mode'], [$updated['memory']['category'] ?? null, $updated['memory']['key'] ?? null, $updated['memory']['value'] ?? null]);
    }

    public function testTheFieldsAreChecked(): void
    {
        $this->authenticateClient($this->client, $this->owner('memory-owner'));

        self::assertSame('Missing required fields: category, key, value', $this->send('POST', '/api/v1/user/memories', ['category' => 'work', 'value' => 'x'])['error'] ?? null);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Missing required field: value', $this->send('PUT', '/api/v1/user/memories/1', ['value' => '  '])['error'] ?? null);
        self::assertSame('Category must be a non-empty string', $this->send('PUT', '/api/v1/user/memories/1', ['value' => 'x', 'category' => ''])['error'] ?? null);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAMemoryOfAnotherAccountIsNotFoundAndStaysAsItWas(): void
    {
        $this->authenticateClient($this->client, $this->owner('memory-owner'));
        $id = $this->send('POST', '/api/v1/user/memories', ['category' => 'work', 'key' => 'employer', 'value' => 'Acme'])['memory']['id'] ?? null;
        $this->authenticateClient($this->client, $this->owner('memory-stranger'));

        $answer = $this->send('PUT', '/api/v1/user/memories/'.$id, ['value' => 'Evil Corp']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Memory not found', $answer['error'] ?? null);
        $this->em->clear();
        self::assertSame('Acme', $this->em->find(UserMemory::class, $id)?->getValue());
    }

    private function owner(string $name): User
    {
        $user = $this->createUser($name);
        $this->owners[] = (int) $user->getId();

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, array $body): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
