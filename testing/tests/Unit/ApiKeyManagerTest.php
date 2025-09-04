<?php

require_once __DIR__ . '/../../../public/inc/auth/ApiKeyManager.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for ApiKeyManager
 */
class ApiKeyManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock session for testing
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up session
        unset($_SESSION["USERPROFILE"]);
        $_REQUEST = [];
    }

    public function testGetApiKeysWithoutSession()
    {
        unset($_SESSION["USERPROFILE"]);
        
        $result = ApiKeyManager::getApiKeys();
        
        $this->assertFalse($result['success']);
        $this->assertEmpty($result['keys']);
    }

    public function testCreateApiKeyWithoutSession()
    {
        unset($_SESSION["USERPROFILE"]);
        
        $result = ApiKeyManager::createApiKey();
        
        $this->assertFalse($result['success']);
    }

    public function testSetApiKeyStatusWithInvalidStatus()
    {
        $_REQUEST['id'] = 123;
        $_REQUEST['status'] = 'invalid_status';
        
        $result = ApiKeyManager::setApiKeyStatus();
        
        $this->assertFalse($result['success']);
    }

    public function testSetApiKeyStatusWithInvalidId()
    {
        $_REQUEST['id'] = 0;
        $_REQUEST['status'] = 'active';
        
        $result = ApiKeyManager::setApiKeyStatus();
        
        $this->assertFalse($result['success']);
    }

    public function testDeleteApiKeyWithInvalidId()
    {
        $_REQUEST['id'] = 0;
        
        $result = ApiKeyManager::deleteApiKey();
        
        $this->assertFalse($result['success']);
    }
}
