<?php

use PHPUnit\Framework\TestCase;

/**
 * Full System Feature Tests
 * 
 * Tests complete workflows from frontend to backend
 */
class FullSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup complete test environment
        $GLOBALS["debug"] = true;
        $GLOBALS["baseUrl"] = "https://test.synaplan.com/";
        
        // Clear any existing sessions
        session_start();
        session_destroy();
        session_start();
    }
    
    public function testCompleteUserRegistrationFlow()
    {
        // Simulate user registration form submission
        $_REQUEST = [
            'action' => 'register',
            'email' => 'fulltest@example.com',
            'password' => 'testpassword123',
            'confirmPassword' => 'testpassword123'
        ];
        
        // Test registration
        $result = UserRegistration::registerNewUser();
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Registration successful', $result['message']);
        
        // Verify session result would be set (as in index.php)
        $_SESSION['registration_result'] = $result;
        $this->assertArrayHasKey('registration_result', $_SESSION);
        $this->assertTrue($_SESSION['registration_result']['success']);
    }
    
    public function testCompleteApiKeyManagementFlow()
    {
        // Setup authenticated user
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        
        // Create API key
        $_REQUEST = ['name' => 'Integration Test Key'];
        $createResult = ApiKeyManager::createApiKey();
        
        $this->assertTrue($createResult['success']);
        $apiKey = $createResult['key'];
        
        // Get all keys
        $getResult = ApiKeyManager::getApiKeys();
        $this->assertTrue($getResult['success']);
        $this->assertNotEmpty($getResult['keys']);
        
        // Find our created key
        $createdKey = null;
        foreach ($getResult['keys'] as $key) {
            if (strpos($key['BMASKEDKEY'], substr($apiKey, 0, 12)) !== false) {
                $createdKey = $key;
                break;
            }
        }
        
        $this->assertNotNull($createdKey, 'Created API key not found in list');
        
        // Update key status
        $_REQUEST = [
            'id' => $createdKey['BID'],
            'status' => 'paused'
        ];
        $updateResult = ApiKeyManager::setApiKeyStatus();
        $this->assertTrue($updateResult['success']);
        
        // Delete key
        $_REQUEST = ['id' => $createdKey['BID']];
        $deleteResult = ApiKeyManager::deleteApiKey();
        $this->assertTrue($deleteResult['success']);
    }
    
    public function testFileUploadAndProcessing()
    {
        // Setup authenticated user
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        
        // Test getting message files (should return empty array)
        $result = FileManager::getMessageFiles(999); // Non-existent message
        $this->assertIsArray($result);
        $this->assertEmpty($result);
        
        // Test getting latest files
        $result = FileManager::getLatestFiles(10);
        $this->assertIsArray($result);
        
        // Test RAG file upload without files
        $_REQUEST['groupKey'] = 'TEST_GROUP';
        $_FILES = [];
        
        $result = FileManager::saveRAGFiles();
        $this->assertFalse($result['success']);
        $this->assertEquals('No valid files uploaded', $result['error']);
    }
    
    public function testEmailNotificationFlow()
    {
        // Test registration email flow
        $email = 'flowtest@example.com';
        $pin = 'ABC123';
        $userId = 12345;
        
        $result = EmailService::sendRegistrationConfirmation($email, $pin, $userId);
        $this->assertTrue($result);
        
        // Test subsequent confirmation
        $result = EmailService::sendEmailConfirmation($email);
        $this->assertTrue($result);
        
        // Test admin notification for system events
        $result = EmailService::sendAdminNotification('System Test', 'Full system test completed');
        $this->assertTrue($result);
    }
    
    public function testSessionSecurityFlow()
    {
        // Test unauthenticated API key access
        unset($_SESSION["USERPROFILE"]);
        
        $result = ApiKeyManager::getApiKeys();
        $this->assertFalse($result['success']);
        
        $result = ApiKeyManager::createApiKey();
        $this->assertFalse($result['success']);
        
        // Test authenticated access
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        
        $result = ApiKeyManager::getApiKeys();
        $this->assertTrue($result['success']);
    }
    
    public function testErrorHandlingAcrossServices()
    {
        // Test various error conditions
        
        // Invalid API key operations
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        $_REQUEST = [
            'id' => -1,
            'status' => 'invalid'
        ];
        
        $result = ApiKeyManager::setApiKeyStatus();
        $this->assertFalse($result['success']);
        
        // Invalid registration data
        $_REQUEST = [
            'email' => 'invalid-email',
            'password' => '123',
            'confirmPassword' => '456'
        ];
        
        $result = UserRegistration::registerNewUser();
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
        
        // Invalid file operations
        $result = FileManager::getMessageFiles(-1);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
