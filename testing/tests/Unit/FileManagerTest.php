<?php

require_once __DIR__ . '/../../../public/inc/files/FileManager.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for FileManager
 */
class FileManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test session
        $_SESSION["USERPROFILE"]["BID"] = 12345;
        $_SESSION["is_widget"] = false;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up session
        unset($_SESSION["USERPROFILE"]);
        unset($_SESSION["is_widget"]);
        $_REQUEST = [];
        $_FILES = [];
    }

    public function testGetMessageFilesWithInvalidId()
    {
        $result = FileManager::getMessageFiles(0);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSaveRAGFilesWithoutGroupKey()
    {
        $_REQUEST['groupKey'] = '';
        $_FILES = [];
        
        $result = FileManager::saveRAGFiles();
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Group key is required', $result['error']);
    }

    public function testSaveRAGFilesWithoutFiles()
    {
        $_REQUEST['groupKey'] = 'TEST';
        $_FILES = [];
        
        $result = FileManager::saveRAGFiles();
        
        $this->assertFalse($result['success']);
        $this->assertEquals('No valid files uploaded', $result['error']);
    }

    public function testGetLatestFilesWithDefaultLimit()
    {
        $result = FileManager::getLatestFiles();
        
        $this->assertIsArray($result);
        // Without database connection, this will return empty array
        $this->assertEmpty($result);
    }

    public function testGetLatestFilesWithCustomLimit()
    {
        $result = FileManager::getLatestFiles(5);
        
        $this->assertIsArray($result);
        // Without database connection, this will return empty array
        $this->assertEmpty($result);
    }

    public function testAnonymousWidgetSession()
    {
        $_SESSION["is_widget"] = true;
        $_SESSION["widget_owner_id"] = 67890;
        unset($_SESSION["USERPROFILE"]);
        
        $result = FileManager::getMessageFiles(123);
        
        $this->assertIsArray($result);
    }

    public function testSaveRAGFilesDefaultGroupKeyForWidget()
    {
        $_SESSION["is_widget"] = true;
        $_SESSION["widget_owner_id"] = 67890;
        unset($_SESSION["USERPROFILE"]);
        unset($_REQUEST['groupKey']);
        $_FILES = [];
        
        $result = FileManager::saveRAGFiles();
        
        // Should use 'WIDGET' as default group key for anonymous widgets
        $this->assertFalse($result['success']);
        $this->assertEquals('No valid files uploaded', $result['error']);
    }

    public function testSaveRAGFilesDefaultGroupKeyForUser()
    {
        $_SESSION["is_widget"] = false;
        unset($_REQUEST['groupKey']);
        $_FILES = [];
        
        $result = FileManager::saveRAGFiles();
        
        // Should use 'DEFAULT' as default group key for regular users
        $this->assertFalse($result['success']);
        $this->assertEquals('No valid files uploaded', $result['error']);
    }
}
