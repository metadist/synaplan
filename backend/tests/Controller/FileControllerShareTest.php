<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test File Sharing Endpoints
 */
class FileControllerShareTest extends WebTestCase
{
    private string $authToken;
    private int $userId;
    private int $testFileId;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Login to get auth token
        $client = static::createClient();
        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'demo@synaplan.com',
            'password' => 'demo123'
        ]));

        $response = $client->getResponse();
        
        if ($response->getStatusCode() !== 200) {
            echo "\nLogin failed: " . $response->getContent() . "\n";
            $this->markTestSkipped('Login failed, cannot test file sharing');
        }
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->authToken = $data['token'];
        $this->userId = $data['user']['id'];

        // Upload a test file
        $this->testFileId = $this->uploadTestFile();
    }

    private function uploadTestFile(): int
    {
        $client = static::createClient();
        
        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'Test file content for sharing');
        
        $client->request('POST', '/api/v1/files/upload', [
            'process_level' => 'extract'
        ], [
            'files' => [
                new \Symfony\Component\HttpFoundation\File\UploadedFile(
                    $tempFile,
                    'test-share.txt',
                    'text/plain',
                    null,
                    true
                )
            ]
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        
        return $data['files'][0]['id'];
    }

    public function testMakeFilePublic(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/api/v1/files/' . $this->testFileId . '/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ], json_encode([
            'expiry_days' => 7
        ]));

        $response = $client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertTrue($data['is_public']);
        $this->assertNotEmpty($data['share_url']);
        $this->assertNotEmpty($data['share_token']);
        $this->assertNotNull($data['expires_at']);
    }

    public function testGetShareInfo(): void
    {
        $client = static::createClient();
        
        // First make it public
        $client->request('POST', '/api/v1/files/' . $this->testFileId . '/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ], json_encode(['expiry_days' => 7]));

        // Get share info
        $client->request('GET', '/api/v1/files/' . $this->testFileId . '/share', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);

        $response = $client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['is_public']);
        $this->assertNotEmpty($data['share_url']);
        $this->assertFalse($data['is_expired']);
    }

    public function testRevokeShare(): void
    {
        $client = static::createClient();
        
        // First make it public
        $client->request('POST', '/api/v1/files/' . $this->testFileId . '/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ], json_encode(['expiry_days' => 7]));

        // Revoke
        $client->request('DELETE', '/api/v1/files/' . $this->testFileId . '/share', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);

        $response = $client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertFalse($data['is_public']);

        // Verify it's no longer public
        $client->request('GET', '/api/v1/files/' . $this->testFileId . '/share', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ]);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['is_public']);
    }

    public function testUnauthorizedShareAccess(): void
    {
        $client = static::createClient();
        
        // Try to share without auth
        $client->request('POST', '/api/v1/files/' . $this->testFileId . '/share', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ], json_encode(['expiry_days' => 7]));

        $response = $client->getResponse();
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testShareNonExistentFile(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/api/v1/files/999999/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->authToken
        ], json_encode(['expiry_days' => 7]));

        $response = $client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());
    }
}

