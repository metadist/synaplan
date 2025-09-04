<?php

require_once __DIR__ . '/../Helpers/TestDatabaseHelper.php';
require_once __DIR__ . '/../../../public/inc/auth/ApiKeyManager.php';
require_once __DIR__ . '/../../../public/inc/auth/UserRegistration.php';
require_once __DIR__ . '/../../../public/inc/files/FileManager.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration Tests for API functionality
 */
class ApiIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup test database
        TestDatabaseHelper::setupTestDatabase();
        
        // Setup test session
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        $_SESSION["LANG"] = "en";
        
        // Mock globals
        $GLOBALS["debug"] = true;
        $GLOBALS["baseUrl"] = "https://test.synaplan.com/";
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up
        TestDatabaseHelper::cleanupTestDatabase();
        unset($_SESSION["USERPROFILE"]);
        $_REQUEST = [];
        $_FILES = [];
    }
    
    public function testApiKeyWorkflow()
    {
        // Test creating API key
        $_REQUEST['name'] = 'Test Integration Key';
        $result = ApiKeyManager::createApiKey();
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['key']);
        $this->assertStringStartsWith('sk_live_', $result['key']);
        
        // Test getting API keys
        $result = ApiKeyManager::getApiKeys();
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['keys']);
        $this->assertGreaterThan(0, count($result['keys']));
        
        // Test updating API key status
        $apiKeyId = $result['keys'][0]['BID'];
        $_REQUEST['id'] = $apiKeyId;
        $_REQUEST['status'] = 'paused';
        $result = ApiKeyManager::setApiKeyStatus();
        
        $this->assertTrue($result['success']);
        
        // Test deleting API key
        $_REQUEST['id'] = $apiKeyId;
        $result = ApiKeyManager::deleteApiKey();
        
        $this->assertTrue($result['success']);
    }
    
    public function testUserRegistrationWorkflow()
    {
        // Test valid registration
        $_REQUEST = [
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'confirmPassword' => 'password123'
        ];
        
        $result = UserRegistration::registerNewUser();
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Registration successful', $result['message']);
    }
    
    public function testFileManagerWorkflow()
    {
        // Test getting message files
        $result = FileManager::getMessageFiles(1);
        
        $this->assertIsArray($result);
        
        // Test getting latest files
        $result = FileManager::getLatestFiles(5);
        
        $this->assertIsArray($result);
    }
    
    public function testAnonymousWidgetSession()
    {
        // Setup anonymous widget session
        $_SESSION["is_widget"] = true;
        $_SESSION["widget_owner_id"] = 12345;
        $_SESSION["widget_id"] = 1;
        $_SESSION["anonymous_session_id"] = "test_session_123";
        unset($_SESSION["USERPROFILE"]);
        
        // Test file access for anonymous widget
        $result = FileManager::getMessageFiles(1);
        
        $this->assertIsArray($result);
        
        // Test RAG files with widget session
        $_REQUEST['groupKey'] = 'WIDGET';
        $_FILES = []; // No files uploaded
        
        $result = FileManager::saveRAGFiles();
        
        $this->assertFalse($result['success']);
        $this->assertEquals('No valid files uploaded', $result['error']);
    }
}
