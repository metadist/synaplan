<?php

namespace App\Tests\Controller;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Trait\AuthenticatedTestTrait;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for POST /api/v1/summary/generate.
 *
 * Exercises the dual-input design: the endpoint accepts either a raw
 * "text" string (legacy behaviour) or a "fileId" referencing a
 * previously-uploaded file whose extracted text should be summarized.
 *
 * AiFacade is mocked in the test container so these tests don't
 * depend on any particular provider/model config and never invoke a
 * real LLM. File entities are created directly via the EntityManager
 * to keep each test focused on one HTTP request (the summary call).
 */
class SummaryControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const MOCK_SUMMARY = 'mocked summary for the test suite';

    private $client;
    private $em;
    private User $testUser;
    private string $authToken;
    private AiFacade&MockObject $aiFacade;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        // Replace the real AiFacade with a mock in the test container.
        // Symfony's test container exposes ->set() for private services,
        // so the controller's autowired AiFacade becomes our mock.
        // Same pattern as WordPressIntegrationControllerTest.
        $this->aiFacade = $this->createMock(AiFacade::class);
        static::getContainer()->set(AiFacade::class, $this->aiFacade);

        $userRepository = static::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);
        if (!$user) {
            $this->markTestSkipped('Test user not found. Run fixtures first.');
        }

        $this->testUser = $user;
        $this->authToken = $this->authenticateClient($this->client, $this->testUser);
    }

    protected function tearDown(): void
    {
        if (isset($this->testUser)) {
            if (!$this->em || !$this->em->isOpen()) {
                self::bootKernel();
                $this->em = self::getContainer()->get('doctrine')->getManager();
            }

            $files = $this->em->getRepository(File::class)
                ->findBy(['userId' => $this->testUser->getId()]);
            foreach ($files as $file) {
                $this->em->remove($file);
            }
            $this->em->flush();
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/summary/generate',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['text' => 'Some content.'])
        );

        $this->assertEquals(401, $client->getResponse()->getStatusCode());
    }

    public function testRequiresTextOrFileId(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        $this->postSummary(['summaryType' => 'abstractive']);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('text or fileId', $data['error']);
    }

    public function testRejectsBothTextAndFileId(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        $this->postSummary([
            'text' => 'Some text.',
            'fileId' => 12345,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('not both', $data['error']);
    }

    public function testFileIdNotFound(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        $this->postSummary(['fileId' => 999999999]);

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testFileIdWithoutExtractedText(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        $fileId = $this->createFile($this->testUser->getId(), '', 'uploaded');

        $this->postSummary(['fileId' => $fileId]);

        $response = $this->client->getResponse();
        $this->assertEquals(422, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('extracted text', $data['error']);
    }

    public function testFileIdWrongOwner(): void
    {
        $this->aiFacade->expects($this->never())->method('chat');

        // Create a file owned by a different user — admin must not be
        // able to summarize it. "Not found" and "wrong owner" collapse
        // into the same 404 so file IDs can't be enumerated.
        $otherUserId = $this->testUser->getId() + 100000;
        $fileId = $this->createFile($otherUserId, 'secret contents', 'extracted');

        $this->postSummary(['fileId' => $fileId]);

        $this->assertEquals(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTextParamStillWorks(): void
    {
        $text = 'This is a document that needs to be summarized for the test suite.';

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with($this->callback(fn ($messages) => $this->userMessageContains($messages, $text)))
            ->willReturn($this->mockChatResponse());

        $this->postSummary([
            'text' => $text,
            'summaryType' => 'abstractive',
            'length' => 'short',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(self::MOCK_SUMMARY, $data['summary']);
    }

    public function testFileIdHappyPath(): void
    {
        $fileText = 'The quick brown fox jumps over the lazy dog. '
            .'This sentence is famous because it contains every letter of the alphabet.';
        $fileId = $this->createFile($this->testUser->getId(), $fileText, 'extracted');

        // Assert the file's extracted text (not the fileId) ends up in
        // the messages passed to AiFacade — that's the whole point of
        // the fileId feature.
        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->with($this->callback(fn ($messages) => $this->userMessageContains($messages, 'quick brown fox')))
            ->willReturn($this->mockChatResponse());

        $this->postSummary([
            'fileId' => $fileId,
            'summaryType' => 'abstractive',
            'length' => 'short',
            'outputLanguage' => 'en',
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(self::MOCK_SUMMARY, $data['summary']);
        $this->assertGreaterThan(0, $data['metadata']['original_length']);
    }

    /**
     * @return array{content: string, provider: string, model: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}}
     */
    private function mockChatResponse(): array
    {
        return [
            'content' => self::MOCK_SUMMARY,
            'provider' => 'mock',
            'model' => 'mock-model',
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 7,
                'total_tokens' => 17,
            ],
        ];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function userMessageContains(array $messages, string $needle): bool
    {
        foreach ($messages as $m) {
            if ('user' === $m['role'] && str_contains($m['content'], $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postSummary(array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/summary/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
            ],
            json_encode($body)
        );
    }

    /**
     * Persist a minimal File entity directly. Avoids touching
     * /files/upload (which has its own moving parts) and keeps each
     * test focused on the /summary/generate request.
     */
    private function createFile(int $userId, string $extractedText, string $status): int
    {
        $file = new File();
        $file->setUserId($userId);
        $file->setFileName('test.txt');
        $file->setFileType('txt');
        $file->setFileMime('text/plain');
        $file->setFilePath('test/test.txt');
        $file->setFileSize(strlen($extractedText));
        $file->setFileText($extractedText);
        $file->setStatus($status);

        $this->em->persist($file);
        $this->em->flush();

        return (int) $file->getId();
    }
}
