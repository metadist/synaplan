<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\UserMemoryController;
use App\Entity\UseLog;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/v1/user/memories/parse with the test model, which answers no
 * memory action: the input is required, an answer without actions is told
 * with its code (and to an admin, with what the model said), and the call is
 * recorded as usage.
 */
final class UserMemoryParseTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(UseLog::class)->findBy(['userId' => $this->users]) as $usage) {
            $this->em->remove($usage);
        }
        $this->em->flush();
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testTheInputIsRequired(): void
    {
        $this->signIn($this->user('NEW'));

        $body = $this->parse('   ');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(UserMemoryController::ERROR_CODE_INPUT_REQUIRED, $body['code'] ?? null);
    }

    public function testAnAnswerWithoutActionsIsToldWithItsCodeAndRecordedAsUsage(): void
    {
        $user = $this->user('NEW');
        $this->signIn($user);

        $body = $this->parse('I prefer dark mode in all applications');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(UserMemoryController::ERROR_CODE_AI_PARSE_FAILED, $body['code'] ?? null);
        self::assertArrayNotHasKey('debug', $body);
        self::assertSame(['EMBEDDINGS', 'MEMORY_PARSE'], $this->usageOf($user), 'The search for similar memories embeds the input first.');
    }

    public function testAnAdminSeesWhatTheModelSaid(): void
    {
        $this->signIn($this->user('ADMIN'));

        $body = $this->parse('I prefer dark mode in all applications');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('AI response missing valid actions: ', (string) ($body['debug'] ?? ''));
    }

    private function user(string $level): User
    {
        $user = $this->createUser('memory-parse')->setUserLevel($level);
        $this->em->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }

    private function signIn(User $user): void
    {
        $this->authenticateClient($this->client, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $input): array
    {
        $this->client->request('POST', '/api/v1/user/memories/parse', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['input' => $input]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @return list<string>
     */
    private function usageOf(User $user): array
    {
        $this->em->clear();

        return array_map(static fn (UseLog $usage): string => $usage->getAction(), $this->em->getRepository(UseLog::class)->findBy(['userId' => $user->getId()]));
    }
}
