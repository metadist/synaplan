<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileControllerTest extends WebTestCase
{
    private $client;
    private User $testUser;
    private string $authToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        
        // Get test user and authenticate
        $userRepository = static::getContainer()->get(UserRepository::class);
        $this->testUser = $userRepository->findOneBy(['email' => 'admin@synaplan.com']);
        
        if (!$this->testUser) {
            $this->markTestSkipped('Test user not found. Run fixtures first.');
        }

        // Login to get JWT token
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'admin@synaplan.com',
            'password' => 'admin123'
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->authToken = $data['token'] ?? '';
    }

    public function testUploadSingleTextFile(): void
    {
        // Create test file
        $testFile = $this->createTestFile('test.txt', 'This is a test file with some content for extraction.');

        $uploadedFile = new UploadedFile(
            $testFile,
            'test.txt',
            'text/plain',
            null,
            true // test mode
        );

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'TEST_GROUP',
            'process_level' => 'vectorize'
        ], [
            'files' => [$uploadedFile]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['files']);
        $this->assertArrayHasKey('id', $data['files'][0]);
        $this->assertArrayHasKey('extracted_text_length', $data['files'][0]);
        $this->assertArrayHasKey('chunks_created', $data['files'][0]);
        $this->assertGreaterThan(0, $data['files'][0]['extracted_text_length']);
    }

    public function testUploadMultipleFiles(): void
    {
        $file1 = $this->createTestFile('doc1.txt', 'First document content');
        $file2 = $this->createTestFile('doc2.txt', 'Second document content');

        $uploadedFile1 = new UploadedFile($file1, 'doc1.txt', 'text/plain', null, true);
        $uploadedFile2 = new UploadedFile($file2, 'doc2.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'MULTI_TEST',
            'process_level' => 'extract'
        ], [
            'files' => [$uploadedFile1, $uploadedFile2]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['files']);
    }

    public function testUploadWithExtractOnlyProcessLevel(): void
    {
        $testFile = $this->createTestFile('extract_only.txt', 'Test content');
        $uploadedFile = new UploadedFile($testFile, 'extract_only.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'EXTRACT_ONLY',
            'process_level' => 'extract'
        ], [
            'files' => [$uploadedFile]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('extracted_text_length', $data['files'][0]);
        // Should NOT have chunks_created since we only extracted
        $this->assertArrayNotHasKey('chunks_created', $data['files'][0]);
    }

    public function testUploadWithInvalidProcessLevel(): void
    {
        $testFile = $this->createTestFile('test.txt', 'content');
        $uploadedFile = new UploadedFile($testFile, 'test.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'TEST',
            'process_level' => 'invalid_level'
        ], [
            'files' => [$uploadedFile]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Invalid process_level', $data['error']);
    }

    public function testUploadWithoutAuthentication(): void
    {
        $testFile = $this->createTestFile('test.txt', 'content');
        $uploadedFile = new UploadedFile($testFile, 'test.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'TEST'
        ], [
            'files' => [$uploadedFile]
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testUploadWithoutFiles(): void
    {
        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'TEST',
            'process_level' => 'extract'
        ], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('No files uploaded', $data['error']);
    }

    public function testListFiles(): void
    {
        // First upload a file
        $testFile = $this->createTestFile('list_test.txt', 'Content for listing test');
        $uploadedFile = new UploadedFile($testFile, 'list_test.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'LIST_TEST'
        ], [
            'files' => [$uploadedFile]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        // Now list files
        $this->client->request('GET', '/api/v1/files', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('files', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertIsArray($data['files']);
        $this->assertGreaterThan(0, count($data['files']));
    }

    public function testListFilesWithGroupFilter(): void
    {
        // Upload file with specific group
        $testFile = $this->createTestFile('filtered.txt', 'Filtered content');
        $uploadedFile = new UploadedFile($testFile, 'filtered.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'FILTER_TEST_UNIQUE'
        ], [
            'files' => [$uploadedFile]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        // List files with group filter
        $this->client->request('GET', '/api/v1/files?group_key=FILTER_TEST_UNIQUE', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertGreaterThan(0, count($data['files']));
        
        // All files should have the filtered group key
        foreach ($data['files'] as $file) {
            $this->assertEquals('FILTER_TEST_UNIQUE', $file['group_key']);
        }
    }

    public function testDeleteFile(): void
    {
        // First upload a file
        $testFile = $this->createTestFile('delete_test.txt', 'To be deleted');
        $uploadedFile = new UploadedFile($testFile, 'delete_test.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/files/upload', [
            'group_key' => 'DELETE_TEST'
        ], [
            'files' => [$uploadedFile]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $uploadResponse = json_decode($this->client->getResponse()->getContent(), true);
        $fileId = $uploadResponse['files'][0]['id'];

        // Now delete it
        $this->client->request('DELETE', '/api/v1/files/' . $fileId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testDeleteNonExistentFile(): void
    {
        $this->client->request('DELETE', '/api/v1/files/999999', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());
    }

    // Helper methods

    private function createTestFile(string $filename, string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'synaplan_test_');
        file_put_contents($tempFile, $content);
        return $tempFile;
    }
}

