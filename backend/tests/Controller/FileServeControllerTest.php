<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test File Serving with Auth Check.
 */
class FileServeControllerTest extends WebTestCase
{
    private $client;
    private string $authToken;
    private int $userId;
    private string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Login
        $this->client = static::createClient();
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'demo@synaplan.com',
            'password' => 'demo123',
        ]));

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->authToken = $data['token'];
        $this->userId = $data['user']['id'];

        // Upload test file and get path
        $this->testFilePath = $this->uploadAndGetPath();
    }

    private function uploadAndGetPath(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'Private file content');

        $this->client->request('POST', '/api/v1/files/upload', [
            'process_level' => 'extract',
        ], [
            'files' => [
                new \Symfony\Component\HttpFoundation\File\UploadedFile(
                    $tempFile,
                    'test-serve.txt',
                    'text/plain',
                    null,
                    true
                ),
            ],
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ]);

        $response = $this->client->getResponse();

        if (200 !== $response->getStatusCode()) {
            $this->markTestSkipped('File upload failed: '.$response->getContent());
        }

        $data = json_decode($response->getContent(), true);

        if (!isset($data['files'][0]['file_path'])) {
            $this->markTestSkipped('No file path in response: '.json_encode($data));
        }

        return $data['files'][0]['file_path'];
    }

    public function testServePrivateFileWithAuth(): void
    {
        $this->client->request('GET', '/up/'.$this->testFilePath, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getContent());
    }

    public function testServePrivateFileWithoutAuth(): void
    {
        $this->client->request('GET', '/up/'.$this->testFilePath);

        $response = $this->client->getResponse();
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testServePublicFile(): void
    {
        // Get file ID from path
        $fileId = $this->getFileIdFromPath($this->testFilePath);

        // Make file public
        $this->client->request('POST', '/api/v1/files/'.$fileId.'/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ], json_encode(['expiry_days' => 7]));

        // Access without auth should now work
        $this->client->request('GET', '/up/'.$this->testFilePath);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testServeNonExistentFile(): void
    {
        $this->client->request('GET', '/up/nonexistent/file.txt', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testCacheHeadersForPublicFile(): void
    {
        $fileId = $this->getFileIdFromPath($this->testFilePath);

        // Make public
        $this->client->request('POST', '/api/v1/files/'.$fileId.'/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ], json_encode(['expiry_days' => 0]));

        // Get file
        $this->client->request('GET', '/up/'.$this->testFilePath);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control') ?? '');
    }

    public function testCacheHeadersForPrivateFile(): void
    {
        $this->client->request('GET', '/up/'.$this->testFilePath, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ]);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control') ?? '');
    }

    private function getFileIdFromPath(string $path): int
    {
        $this->client->request('GET', '/api/v1/files?limit=1000', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken,
        ]);

        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);

        foreach ($data['files'] as $file) {
            if ($file['file_path'] === $path) {
                return $file['id'];
            }
        }

        throw new \Exception('File not found: '.$path);
    }
}
