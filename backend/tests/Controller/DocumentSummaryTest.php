<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\UseLog;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The two document summaries: POST /api/v1/summary/generate (a text or an
 * owned file, with its options) and POST
 * /api/v1/prompts/{topic}/files/{id}/summarize (a short summary of an owned
 * file). Both answer with the account's summary model (the test model here)
 * and record their usage; a file of another account is not found.
 */
final class DocumentSummaryTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private const TEXT = 'Synaplan keeps the documents of a team in one place. It reads them, answers questions about them and summarises them on request.';

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = $this->createUser('summary');
        $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(UseLog::class)->findBy(['userId' => $this->user->getId()]) as $usage) {
            $this->em->remove($usage);
        }
        $this->em->flush();
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testATextIsSummarisedWithItsMetadataAndItsUsage(): void
    {
        $body = $this->post('/api/v1/summary/generate', ['text' => self::TEXT, 'length' => 'short', 'outputLanguage' => 'de']);

        self::assertResponseIsSuccessful();
        self::assertTrue($body['success']);
        self::assertNotSame('', $body['summary']);
        self::assertSame(
            [str_word_count(self::TEXT), 'test', 'test-model', ['summary_type' => 'abstractive', 'length' => 'short', 'output_language' => 'de', 'focus_areas' => ['main-ideas', 'key-facts']]],
            [$body['metadata']['original_length'], $body['metadata']['provider'], $body['metadata']['model'], $body['metadata']['configuration']],
        );
        self::assertSame([['DOC_SUMMARY', 'test']], $this->usage());
    }

    public function testTheRequestIsValidated(): void
    {
        $this->post('/api/v1/summary/generate', []);
        self::assertResponseStatusCodeSame(400);
        $this->post('/api/v1/summary/generate', ['text' => self::TEXT, 'fileId' => 1]);
        self::assertResponseStatusCodeSame(400);
        $this->post('/api/v1/summary/generate', ['text' => self::TEXT, 'summaryType' => 'poem']);
        self::assertResponseStatusCodeSame(400);
        $this->post('/api/v1/summary/generate', ['text' => self::TEXT, 'length' => 'endless']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->usage());
    }

    public function testAFileOfAnotherAccountIsNotFoundAndAFileWithoutTextIsNotReady(): void
    {
        $foreign = $this->file($this->createUser('summary-stranger'), self::TEXT);
        $empty = $this->file($this->user, '');

        $this->post('/api/v1/summary/generate', ['fileId' => $foreign->getId()]);
        self::assertResponseStatusCodeSame(404);
        $this->post('/api/v1/summary/generate', ['fileId' => (string) $empty->getId()]);
        self::assertResponseStatusCodeSame(422);
        $this->post('/api/v1/prompts/general/files/'.$foreign->getId().'/summarize', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->usage());
    }

    public function testAnOwnedFileIsSummarisedForItsPrompt(): void
    {
        $file = $this->file($this->user, self::TEXT);

        $body = $this->post('/api/v1/prompts/general/files/'.$file->getId().'/summarize', []);

        self::assertResponseIsSuccessful();
        self::assertSame([true, 'Team handbook'], [$body['success'], $body['fileName']]);
        self::assertNotSame('', $body['summary']);
        self::assertSame([['FILE_SUMMARY', 'test']], $this->usage());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $uri, array $payload): array
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function file(User $owner, string $text): File
    {
        return $this->persistEntity((new File())
            ->setUserId((int) $owner->getId())
            ->setFilePath('summary-test/handbook.txt')
            ->setFileType('txt')
            ->setFileName('Team handbook')
            ->setFileSize(\strlen($text))
            ->setFileMime('text/plain')
            ->setFileText($text)
            ->setStatus('extracted'));
    }

    /**
     * @return list<array{string, string}> action and provider of every usage row of the user
     */
    private function usage(): array
    {
        $this->em->clear();

        return array_map(
            static fn (UseLog $usage): array => [$usage->getAction(), $usage->getProvider()],
            $this->em->getRepository(UseLog::class)->findBy(['userId' => $this->user->getId()], ['id' => 'ASC']),
        );
    }
}
