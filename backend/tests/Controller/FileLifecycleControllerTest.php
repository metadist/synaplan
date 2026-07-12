<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Functional coverage for the knowledge-file lifecycle API (hosting-partner
 * CORE-4): source identity on upload, overwrite-in-place, mark-stale, and the
 * bulk check-stale poll. Uses process_level=extract so the tests never depend on
 * an embedding provider.
 */
class FileLifecycleControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private $client;
    private $em;
    private ?User $testUser = null;
    private string $authToken;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        $userRepository = $this->client->getContainer()->get(UserRepository::class);
        $this->testUser = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        if (!$this->testUser) {
            $this->markTestSkipped('Test user not found. Run fixtures first.');
        }

        $this->authToken = $this->authenticateClient($this->client, $this->testUser);
    }

    protected function tearDown(): void
    {
        if (isset($this->testUser)) {
            if (!$this->em || !$this->em->isOpen()) {
                self::bootKernel();
                $this->em = self::getContainer()->get('doctrine')->getManager();
            }

            $files = $this->em->getRepository(File::class)->findBy(['userId' => $this->testUser->getId()]);
            foreach ($files as $file) {
                $this->em->remove($file);
            }
            $this->em->flush();
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testUploadPersistsSourceIdentity(): void
    {
        $data = $this->upload('report.txt', 'Version one content.', [
            'source' => 'nextcloud',
            'source_id' => 'nc-42',
            'source_etag' => 'etag-v1',
            'process_level' => 'extract',
        ]);

        self::assertTrue($data['success']);
        self::assertFalse($data['files'][0]['overwritten']);
        self::assertSame('nc-42', $data['files'][0]['source_id']);

        $file = $this->em->getRepository(File::class)->find($data['files'][0]['id']);
        self::assertNotNull($file);
        self::assertSame('nc-42', $file->getSourceId());
        self::assertSame('etag-v1', $file->getSourceEtag());
        self::assertFalse($file->isStale());
    }

    public function testOverwriteReplacesInPlaceAndKeepsId(): void
    {
        $first = $this->upload('doc.txt', 'Original content.', [
            'source' => 'nextcloud',
            'source_id' => 'nc-100',
            'source_etag' => 'etag-1',
            'process_level' => 'extract',
        ]);
        $firstId = $first['files'][0]['id'];
        self::assertFalse($first['files'][0]['overwritten']);

        $second = $this->upload('doc.txt', 'Completely new content after an edit.', [
            'source' => 'nextcloud',
            'source_id' => 'nc-100',
            'source_etag' => 'etag-2',
            'overwrite' => '1',
            'process_level' => 'extract',
        ]);

        self::assertTrue($second['success']);
        self::assertTrue($second['files'][0]['overwritten']);
        self::assertSame($firstId, $second['files'][0]['id'], 'Overwrite must keep the file id stable');

        // Exactly one row exists for this source id, with the new etag.
        $rows = $this->em->getRepository(File::class)->findBy([
            'userId' => $this->testUser->getId(),
            'sourceId' => 'nc-100',
        ]);
        self::assertCount(1, $rows);
        self::assertSame('etag-2', $rows[0]->getSourceEtag());
    }

    public function testMarkStaleSetsMarker(): void
    {
        $data = $this->upload('stale.txt', 'Some content.', [
            'source' => 'nextcloud',
            'source_id' => 'nc-200',
            'source_etag' => 'etag-1',
            'process_level' => 'extract',
        ]);
        $id = $data['files'][0]['id'];

        $this->client->request('POST', '/api/v1/files/'.$id.'/mark-stale', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['source_etag' => 'etag-2']));

        $response = $this->client->getResponse();
        self::assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertTrue($body['stale']);

        $file = $this->em->getRepository(File::class)->find($id);
        self::assertTrue($file->isStale());
        self::assertSame('etag-2', $file->getSourceEtag());
    }

    public function testCheckStaleClassifiesCurrentStaleAndMissing(): void
    {
        $this->upload('a.txt', 'A', [
            'source' => 'nextcloud',
            'source_id' => 'nc-A',
            'source_etag' => 'etag-A',
            'process_level' => 'extract',
        ]);
        $this->upload('b.txt', 'B', [
            'source' => 'nextcloud',
            'source_id' => 'nc-B',
            'source_etag' => 'etag-B',
            'process_level' => 'extract',
        ]);

        $this->client->request('POST', '/api/v1/files/check-stale', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'source' => 'nextcloud',
            'items' => [
                ['source_id' => 'nc-A', 'source_etag' => 'etag-A'],      // unchanged -> current
                ['source_id' => 'nc-B', 'source_etag' => 'etag-B-NEW'],  // drifted -> stale
                ['source_id' => 'nc-GONE', 'source_etag' => 'x'],        // never ingested -> missing
            ],
        ]));

        $response = $this->client->getResponse();
        self::assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);

        self::assertTrue($body['success']);
        self::assertSame(['current' => 1, 'stale' => 1, 'missing' => 1], $body['counts']);

        $byId = [];
        foreach ($body['results'] as $row) {
            $byId[$row['source_id']] = $row;
        }
        self::assertSame('current', $byId['nc-A']['status']);
        self::assertSame('stale', $byId['nc-B']['status']);
        self::assertSame('missing', $byId['nc-GONE']['status']);
        self::assertNull($byId['nc-GONE']['file_id']);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function upload(string $filename, string $content, array $params): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'synaplan_test_');
        file_put_contents($tempFile, $content);
        $uploadedFile = new UploadedFile($tempFile, $filename, 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', $params, [
            'files' => [$uploadedFile],
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ]);

        $response = $this->client->getResponse();
        self::assertContains($response->getStatusCode(), [200, 206], 'Upload should succeed: '.$response->getContent());

        return json_decode($response->getContent(), true);
    }
}
